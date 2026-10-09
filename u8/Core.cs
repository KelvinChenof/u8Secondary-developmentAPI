using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;
using System.Web.Script.Serialization;
using System.Xml;

namespace U8OmApi
{
    internal sealed class ApiError : Exception
    {
        internal readonly string Code;
        internal readonly string Stage;
        internal ApiError(string code, string stage = null, Exception inner = null) : base(code, inner) { Code = code; Stage = stage; }
    }

    // 按 V18 已验证规则核对登录身份，不用不透明的 cUserId/cUserName 额外拒绝。
    internal static class LoginChecks
    {
        internal static Dictionary<string, bool> Compare(IDictionary<string, string> values, string date)
        {
            Dictionary<string, string> expected = new Dictionary<string, string> {
                {"returned_sub_id", "AS"}, {"returned_account_id", "001"}, {"returned_year", "2026"},
                {"returned_business_date", date}, {"returned_server", "192.0.2.11"},
                {"login_sub_id", "AS"}, {"login_account_id", "001"}, {"login_year", "2026"},
                {"login_server", "192.0.2.11"}
            };
            Dictionary<string, bool> matches = new Dictionary<string, bool>();
            foreach (KeyValuePair<string, string> item in expected)
            {
                string value;
                matches.Add(item.Key, values != null && values.TryGetValue(item.Key, out value)
                    && !String.IsNullOrEmpty(item.Value) && String.Equals(value, item.Value, StringComparison.Ordinal));
            }
            string user;
            matches.Add("returned_operator", values != null && values.TryGetValue("returned_operator", out user)
                && (user == "1" || user == "1示例操作员"));
            return matches;
        }
        internal static string Failure(bool authenticated, IDictionary<string, bool> matches)
        {
            if (!authenticated) return "LOGIN_AUTHENTICATION_REJECTED";
            if (matches == null || matches.Count != 10) return "LOGIN_CONTEXT_UNVERIFIED";
            foreach (bool match in matches.Values) if (!match) return "LOGIN_CONTEXT_UNVERIFIED";
            return null;
        }
    }

    internal static class Wire
    {
        internal static readonly JavaScriptSerializer Json = new JavaScriptSerializer { MaxJsonLength = 4194304, RecursionLimit = 64 };
        internal static Dictionary<string, object> Map(params object[] pairs)
        {
            Dictionary<string, object> map = new Dictionary<string, object>();
            for (int i = 0; i < pairs.Length; i += 2) map.Add((string)pairs[i], pairs[i + 1]);
            return map;
        }
        internal static string Code(string value)
        {
            if (value == null || !Regex.IsMatch(value, @"\A[A-Za-z0-9._-]{1,30}\z")) throw new ApiError("INVALID_ORDER_CODE");
            return value;
        }
        internal static long Positive(string value)
        {
            long number;
            if (!Int64.TryParse(value, NumberStyles.None, CultureInfo.InvariantCulture, out number) || number < 1) throw new ApiError("INVALID_ID");
            return number;
        }
        internal static string Hash(string value)
        {
            using (SHA256 sha = SHA256.Create()) return Hex(sha.ComputeHash(Encoding.UTF8.GetBytes(value)));
        }
        internal static string Hex(byte[] value) { return BitConverter.ToString(value).Replace("-", "").ToLowerInvariant(); }
        internal static string Sign(string key, string method, string path, string time, string nonce, string body)
        {
            string text = "om-v1\n" + method + "\n" + path + "\n" + time + "\n" + nonce + "\n" + Hash(body);
            using (HMACSHA256 hmac = new HMACSHA256(Encoding.UTF8.GetBytes(key))) return Hex(hmac.ComputeHash(Encoding.UTF8.GetBytes(text)));
        }
        internal static bool Equal(string left, string right)
        {
            if (left == null || right == null || left.Length != right.Length) return false;
            int difference = 0;
            for (int i = 0; i < left.Length; i++) difference |= left[i] ^ right[i];
            return difference == 0;
        }
    }

    internal sealed class Authenticator
    {
        private readonly string key;
        private readonly Dictionary<string, long> seen = new Dictionary<string, long>();
        internal Authenticator(string key)
        {
            if (key == null || key.Length < 32 || key.Length > 256) throw new ApiError("HMAC_KEY_INVALID");
            this.key = key;
        }
        internal string ResponseSignature(string nonce, string body)
        {
            using (HMACSHA256 hmac = new HMACSHA256(Encoding.UTF8.GetBytes(key)))
                return Wire.Hex(hmac.ComputeHash(Encoding.UTF8.GetBytes("om-response-v1\n" + nonce + "\n" + Wire.Hash(body))));
        }
        internal void Check(string method, string path, string time, string nonce, string signature, string body, long now)
        {
            // 验签覆盖方法、路径、时间、nonce 和正文；时间误差最多五分钟。
            long stamp;
            if (!Int64.TryParse(time, NumberStyles.None, CultureInfo.InvariantCulture, out stamp) || stamp < now - 300 || stamp > now + 300
                || nonce == null || !Regex.IsMatch(nonce, @"\A[0-9a-f]{32}\z")
                || signature == null || !Regex.IsMatch(signature, @"\A[0-9a-f]{64}\z")
                || !Wire.Equal(signature, Wire.Sign(key, method, path, time, nonce, body))) throw new ApiError("AUTH_FAILED");
            List<string> expired = new List<string>();
            foreach (KeyValuePair<string, long> entry in seen) if (entry.Value < now) expired.Add(entry.Key);
            foreach (string item in expired) seen.Remove(item);
            if (seen.ContainsKey(nonce)) throw new ApiError("REPLAY_REJECTED");
            if (seen.Count >= 10000) throw new ApiError("AUTH_CAPACITY");
            seen.Add(nonce, stamp + 300);
        }
    }

    internal sealed class Settings
    {
        public int oa_app_id { get; set; }
        public string listen_prefix { get; set; }
        public string[] allowed_source_ips { get; set; }
        public bool audit_enabled { get; set; }
        public string audited_cstate { get; set; }
        public string audited_verify_state { get; set; }
        internal void Validate()
        {
            Uri uri;
            System.Net.IPAddress address;
            if (oa_app_id < 1 || !Uri.TryCreate(listen_prefix, UriKind.Absolute, out uri) || uri.Scheme != "http"
                || uri.AbsolutePath != "/" || !String.IsNullOrEmpty(uri.Query) || !String.IsNullOrEmpty(uri.UserInfo)
                || !String.IsNullOrEmpty(uri.Fragment) || !listen_prefix.EndsWith("/", StringComparison.Ordinal)
                || !System.Net.IPAddress.TryParse(uri.Host, out address) || address.AddressFamily != System.Net.Sockets.AddressFamily.InterNetwork
                || uri.Host == "0.0.0.0" || allowed_source_ips == null || allowed_source_ips.Length == 0) throw new ApiError("CONFIG_INVALID");
            foreach (string ip in allowed_source_ips)
                if (!System.Net.IPAddress.TryParse(ip, out address) || address.AddressFamily != System.Net.Sockets.AddressFamily.InterNetwork || ip == "0.0.0.0") throw new ApiError("CONFIG_INVALID");
            if (audit_enabled && (String.IsNullOrEmpty(audited_cstate) || audited_cstate == "0" || String.IsNullOrEmpty(audited_verify_state)))
                throw new ApiError("AUDITED_STATE_NOT_CONFIGURED");
        }
    }

    internal sealed class Order
    {
        internal Dictionary<string, string> Head;
        internal List<Dictionary<string, string>> Body;
        internal string Value(string field) { string result; return Head.TryGetValue(field, out result) ? result : ""; }
        internal string Code { get { return Value("ccode"); } }
        internal string Moid { get { return Value("moid"); } }
        internal bool IsOpen
        {
            get { return Value("cbustype") == "委外加工" && Value("cstate") == "0" && Value("iverifystatenew") == "0"
                && Value("iswfcontrolled") == "0" && Value("cverifier") == "" && Value("dverifydate") == ""
                && Value("ccloser") == "" && Value("dclosedate") == "" && Body.Count > 0; }
        }
        internal bool IsAudited(Settings config)
        {
            // 多个状态字段及审核人共同确认成功，避免只看一个状态码。
            DateTime date;
            return !String.IsNullOrEmpty(config.audited_cstate) && config.audited_cstate != "0"
                && Value("cbustype") == "委外加工" && Value("cstate") == config.audited_cstate
                && Value("iverifystatenew") == config.audited_verify_state && Value("iswfcontrolled") == "0"
                && Value("cverifier") == "1示例操作员" && DateTime.TryParse(Value("dverifydate"), out date)
                && Value("ccloser") == "" && Value("dclosedate") == "" && Body.Count > 0;
        }
        internal static Order Parse(string head, string body, string code, string moid)
        {
            List<Dictionary<string, string>> heads = Rows(head, 1);
            if (heads.Count != 1) throw new ApiError("HEAD_ROW_COUNT");
            Order order = new Order { Head = heads[0], Body = Rows(body, 5000) };
            if (order.Code != Wire.Code(code) || order.Moid != moid || order.Value("cbustype") != "委外加工") throw new ApiError("IDENTITY_MISMATCH");
            Wire.Positive(order.Moid);
            foreach (string required in new[] { "ccode", "moid", "cbustype", "cstate", "iverifystatenew", "iswfcontrolled", "ddate" })
                if (String.IsNullOrEmpty(order.Value(required))) throw new ApiError("REQUIRED_HEAD_FIELD_MISSING");
            if (order.Body.Count == 0) throw new ApiError("BODY_EMPTY");
            foreach (Dictionary<string, string> row in order.Body)
                if (Read(row, "cinvcode") == "" || Read(row, "iquantity") == "") throw new ApiError("REQUIRED_BODY_FIELD_MISSING");
            return order;
        }
        internal static List<Dictionary<string, string>> Rows(string xml, int maximum)
        {
            // 限制 XML 大小和行数，并禁止 DTD 与外部实体。
            if (String.IsNullOrEmpty(xml) || xml.Length > 4194304) throw new ApiError("XML_SIZE_INVALID");
            XmlReaderSettings settings = new XmlReaderSettings { DtdProcessing = DtdProcessing.Prohibit, XmlResolver = null, MaxCharactersInDocument = 4194304 };
            XmlDocument document = new XmlDocument { XmlResolver = null };
            using (StringReader text = new StringReader(xml)) using (XmlReader reader = XmlReader.Create(text, settings)) document.Load(reader);
            if (document.DocumentElement == null || document.DocumentElement.Name != "xml" || document.DocumentElement.NamespaceURI != "") throw new ApiError("ROWSET_INVALID");
            XmlNamespaceManager ns = new XmlNamespaceManager(document.NameTable);
            ns.AddNamespace("rs", "urn:schemas-microsoft-com:rowset");
            XmlNodeList data = document.DocumentElement.SelectNodes("./rs:data", ns);
            if (data.Count != 1) throw new ApiError("ROWSET_INVALID");
            List<Dictionary<string, string>> rows = new List<Dictionary<string, string>>();
            foreach (XmlNode node in data[0].ChildNodes)
            {
                if (node.NodeType == XmlNodeType.Whitespace) continue;
                if (node.LocalName != "row" || node.NamespaceURI != "#RowsetSchema" || node.HasChildNodes || rows.Count >= maximum) throw new ApiError("ROWSET_INVALID");
                Dictionary<string, string> row = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
                foreach (XmlAttribute attribute in node.Attributes)
                {
                    if (attribute.NamespaceURI == "http://www.w3.org/2000/xmlns/") continue;
                    if (attribute.NamespaceURI != "" || row.ContainsKey(attribute.Name)) throw new ApiError("ROWSET_INVALID");
                    row.Add(attribute.Name, attribute.Value);
                }
                rows.Add(row);
            }
            return rows;
        }
        private static string Read(Dictionary<string, string> row, string key) { string value; return row.TryGetValue(key, out value) ? value : ""; }
        internal Dictionary<string, object> ToOa()
        {
            // 右侧使用委外 OM 的原生字段名，不混用采购订单别名。
            string[,] map = { {"code","ccode"},{"date","ddate"},{"operation_type_code","cbustype"},
                {"purchase_type_name","cptname"},{"purchase_type_code","cptcode"},{"vendorname","cvenabbname"},
                {"vendorcode","cvencode"},{"vendorabbname","cvenabbname"},{"cVenPerson","cvenperson"},
                {"cVenPhone","cvenphone"},{"cVenAddress","cvenaddress"},{"deptname","cdepname"},{"deptcode","cdepcode"},
                {"personname","cpersonname"},{"personcode","cpersoncode"},{"maker","cmaker"},{"currency_name","cexch_name"},
                {"currency_rate","nflat"},{"cvenpuomprotocolname","cvenpuomprotocolname"},{"remark","cmemo"} };
            Dictionary<string, object> output = new Dictionary<string, object>();
            for (int i = 0; i < map.GetLength(0); i++) output.Add(map[i, 0], Value(map[i, 1]));
            output.Add("state", IsOpen ? "开立" : Value("cstate"));
            output.Add("cstate", Value("cstate"));
            output.Add("iverifystatenew", Value("iverifystatenew"));
            output.Add("cverifier", Value("cverifier"));
            output.Add("dverifydate", Value("dverifydate"));
            output.Add("iswfcontrolled", Value("iswfcontrolled"));
            List<Dictionary<string, object>> entries = new List<Dictionary<string, object>>();
            string[,] fields = { {"inventorycode","cinvcode"},{"inventoryname","cinvname"},{"inventorystd","cinvstd"},
                {"unitname","cinvm_unit"},{"quantity","iquantity"},{"arrivedate","darrivedate"},{"price","iunitprice"},
                {"taxprice","itaxprice"},{"money","imoney"},{"tax","itax"},{"sum","isum"},{"taxrate","ipertaxrate"} };
            foreach (Dictionary<string, string> row in Body)
            {
                Dictionary<string, object> entry = new Dictionary<string, object>();
                for (int i = 0; i < fields.GetLength(0); i++) entry.Add(fields[i, 0], Read(row, fields[i, 1]));
                entries.Add(entry);
            }
            output.Add("entry", entries);
            return output;
        }
    }

    internal sealed class AuditRecord
    {
        public string code;
        public string moid;
        public int app_id;
        public long oa_run_id;
        public string status;
        public string reason;
    }
    // 每单独占领取并保留结果文件，进程重启后仍能识别历史尝试。
    internal sealed class AuditStore
    {
        private readonly string root;
        internal AuditStore(string root) { this.root = root; }
        private string Stem(string code) { return Path.Combine(root, Wire.Hash("001|2026|" + Wire.Code(code))); }
        internal AuditRecord Read(string code)
        {
            string stem = Stem(code);
            if (!File.Exists(stem + ".claim")) return null;
            try
            {
                AuditRecord claim = Wire.Json.Deserialize<AuditRecord>(File.ReadAllText(stem + ".claim", Encoding.UTF8));
                if (claim == null || claim.code != code || claim.app_id < 1 || claim.oa_run_id < 1) throw new ApiError("AUDIT_RECORD_INVALID");
                if (File.Exists(stem + ".result"))
                {
                    AuditRecord result = Wire.Json.Deserialize<AuditRecord>(File.ReadAllText(stem + ".result", Encoding.UTF8));
                    if (result == null || result.code != claim.code || result.moid != claim.moid || result.app_id != claim.app_id
                        || result.oa_run_id != claim.oa_run_id || (result.status != "audited" && result.status != "already_audited" && result.status != "outcome_unknown")) throw new ApiError("AUDIT_RECORD_INVALID");
                    return result;
                }
                // 有领取但无结果不等于失败；可能已写入 U8，必须先复核。
                claim.status = "outcome_unknown"; claim.reason = "PREVIOUS_ATTEMPT_REQUIRES_REVIEW";
                return claim;
            }
            catch { throw new ApiError("AUDIT_RECORD_REQUIRES_REVIEW"); }
        }
        internal void Claim(AuditRecord record)
        {
            Directory.CreateDirectory(root);
            WriteNew(Stem(record.code) + ".claim", record);
        }
        internal void Complete(AuditRecord record) { WriteNew(Stem(record.code) + ".result", record); }
        private static void WriteNew(string path, AuditRecord record)
        {
            byte[] bytes = Encoding.UTF8.GetBytes(Wire.Json.Serialize(record));
            using (FileStream file = new FileStream(path, FileMode.CreateNew, FileAccess.Write, FileShare.None))
            { file.Write(bytes, 0, bytes.Length); file.Flush(true); }
        }
    }
    internal sealed class NativeResult { internal bool Returned = false; internal bool Succeeded = false; internal string Reason; }
    internal interface IOrderHandle : IDisposable { Order Snapshot { get; } NativeResult Audit(); }
    internal sealed class AuditEngine
    {
        private readonly Settings settings;
        private readonly AuditStore store;
        private readonly Func<string, IOrderHandle> load;
        internal AuditEngine(Settings settings, AuditStore store, Func<string, IOrderHandle> load)
        { this.settings = settings; this.store = store; this.load = load; }
        internal AuditRecord Execute(string code, long run)
        {
            Wire.Code(code);
            if (run < 1 || !settings.audit_enabled) throw new ApiError("AUDIT_DISABLED");
            AuditRecord prior = store.Read(code);
            if (prior != null)
            {
                if (prior.oa_run_id != run || prior.app_id != settings.oa_app_id) throw new ApiError("DOCUMENT_ALREADY_CLAIMED");
                return prior;
            }
            AuditRecord record;
            NativeResult native;
            using (IOrderHandle handle = load(code))
            {
                Order before = handle.Snapshot;
                bool already = before.IsAudited(settings);
                if (!already && !before.IsOpen) throw new ApiError("ORDER_NOT_OPEN");
                record = new AuditRecord { app_id = settings.oa_app_id, code = code, moid = before.Moid, oa_run_id = run, status = "pending", reason = "AUDIT_ENTERED" };
                store.Claim(record); // 唯一原生写入前落盘，禁止覆盖已有领取。
                if (already)
                {
                    record.status = "already_audited"; record.reason = "VERIFIED_BY_LOAD"; store.Complete(record); return record;
                }
                try { native = handle.Audit(); }
                catch { native = new NativeResult { Reason = "NATIVE_EXCEPTION" }; }
            }
            record.status = "outcome_unknown";
            record.reason = native.Reason ?? "AUDIT_UNVERIFIED";
            try
            {
                using (IOrderHandle after = load(code))
                {
                    // 原生返回成功且重新加载确认同一 MOID 已审核，才记为成功。
                    if (native.Returned && native.Succeeded && after.Snapshot.Moid == record.moid && after.Snapshot.IsAudited(settings))
                    { record.status = "audited"; record.reason = "NATIVE_AND_POST_LOAD_CONFIRMED"; }
                    else if (native.Returned && !native.Succeeded) record.reason = native.Reason ?? "NATIVE_REJECTED";
                    else record.reason = "POST_LOAD_NOT_AUDITED";
                }
            }
            catch { record.reason = "POST_LOAD_UNAVAILABLE"; }
            store.Complete(record);
            return record;
        }
    }
}
