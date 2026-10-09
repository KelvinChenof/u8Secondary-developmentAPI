using System;
using System.Collections.Generic;
using System.IO;
using System.Text;

namespace U8OmApi
{
    internal sealed class FakeHandle : IOrderHandle
    {
        public Order Snapshot { get; set; }
        internal Action Write;
        internal NativeResult Result = new NativeResult { Returned = true, Succeeded = true, Reason = "NATIVE_ACCEPTED" };
        public NativeResult Audit() { Write(); return Result; }
        public void Dispose() { }
    }
    internal static class CoreTests
    {
        private static int count;
        private static void Assert(bool value, string name) { if (!value) throw new Exception(name); count++; Console.WriteLine("PASS: " + name); }
        private static void Reject(Action action, string name) { try { action(); } catch { Assert(true, name); return; } throw new Exception("Expected rejection: " + name); }
        private static string Xml(string attributes) { return "<xml xmlns:rs='urn:schemas-microsoft-com:rowset' xmlns:z='#RowsetSchema'><rs:data><z:row " + attributes + "/></rs:data></xml>"; }
        private static Order Fixture(bool audited, string code = "0000000062")
        {
            return Order.Parse(Xml("moid='1001' ccode='" + code + "' cbustype='委外加工' cstate='" + (audited ? "9" : "0")
                + "' iverifystatenew='" + (audited ? "8" : "0") + "' iswfcontrolled='0' ddate='2026-09-09' cvencode='0002' cmaker='李露' nflat='1.0000'"
                + (audited ? " cverifier='1示例操作员' dverifydate='2026-09-09'" : "")),
                Xml("cinvcode='00001' cinvname='测试' cinvm_unit='个' iquantity='500.0000' iunitprice='9.2920' itaxprice='10.5000' imoney='4646.02' ipertaxrate='13.0000'"), code, "1001");
        }
        private static Settings Config() { return new Settings { oa_app_id = 6, listen_prefix = "http://127.0.0.1:18766/", allowed_source_ips = new[] { "127.0.0.1" }, audit_enabled = true, audited_cstate = "9", audited_verify_state = "8" }; }
        private static Dictionary<string, string> LoginFixture()
        {
            return new Dictionary<string, string> {
                {"returned_sub_id", "AS"}, {"returned_account_id", "001"}, {"returned_year", "2026"},
                {"returned_operator", "1示例操作员"}, {"returned_business_date", "2026-09-09"}, {"returned_server", "192.0.2.11"},
                {"login_sub_id", "AS"}, {"login_account_id", "001"}, {"login_year", "2026"}, {"login_server", "192.0.2.11"}
            };
        }
        private static void CheckLoginDiagnostics()
        {
            const string date = "2026-09-09";
            Dictionary<string, bool> good = LoginChecks.Compare(LoginFixture(), date);
            Assert(good.Count == 10 && LoginChecks.Failure(true, good) == null, "V18 mandatory login context accepted");
            Assert(LoginChecks.Failure(false, null) == "LOGIN_AUTHENTICATION_REJECTED"
                && LoginChecks.Failure(false, good) == "LOGIN_AUTHENTICATION_REJECTED", "U8 false login separated from context failure");
            Dictionary<string, string> operatorId = LoginFixture(); operatorId["returned_operator"] = "1";
            Assert(LoginChecks.Failure(true, LoginChecks.Compare(operatorId, date)) == null, "U8 returned operator ID 1 accepted without changing submitted operator");
            foreach (string field in LoginFixture().Keys)
            {
                Dictionary<string, string> wrong = LoginFixture(); wrong[field] = "fixture-private-value";
                Dictionary<string, bool> result = LoginChecks.Compare(wrong, date);
                int failures = 0; foreach (bool match in result.Values) if (!match) failures++;
                Assert(failures == 1 && !result[field] && LoginChecks.Failure(true, result) == "LOGIN_CONTEXT_UNVERIFIED"
                    && !Wire.Json.Serialize(result).Contains("fixture-private-value"), "login mismatch isolated and value redacted: " + field);
            }
            Dictionary<string, string> missing = LoginFixture(); missing.Remove("login_account_id");
            Assert(LoginChecks.Failure(true, LoginChecks.Compare(missing, date)) == "LOGIN_CONTEXT_UNVERIFIED"
                && LoginChecks.Failure(true, null) == "LOGIN_CONTEXT_UNVERIFIED", "missing login evidence rejected");
            Dictionary<string, string> opaque = LoginFixture(); opaque["login_operator"] = "opaque-id"; opaque["login_user_name"] = "opaque-name";
            Assert(LoginChecks.Failure(true, LoginChecks.Compare(opaque, date)) == null, "opaque optional properties do not introduce new V18 rejection gates");
            ApiError error = new ApiError("LOGIN_RUNTIME_ERROR", "LoginInvoke", new Exception("fixture"));
            Assert(error.Code == "LOGIN_RUNTIME_ERROR" && error.Stage == "LoginInvoke" && error.InnerException != null,
                "constructor failure retains stage and exception chain");
        }
        private static void CheckHistoricalCodes(string root)
        {
            // 仅构造模拟状态；不读取或审核 U8 中的历史单据。
            foreach (string code in new[] { "0000000059", "0000000060" })
            {
                Order open = Fixture(false, code), audited = Fixture(true, code);
                int loads = 0, writes = 0;
                AuditEngine engine = new AuditEngine(Config(), new AuditStore(Path.Combine(root, "open-" + code)), delegate(string id) {
                    Assert(id == code, "loader receives requested identity " + code);
                    loads++; return new FakeHandle { Snapshot = loads == 1 ? open : audited, Write = delegate { writes++; } };
                });
                AuditRecord result = engine.Execute(code, 101);
                Assert(result.status == "audited" && writes == 1 && loads == 2, "historical code uses open state and one audit " + code);
                Assert(engine.Execute(code, 101).status == "audited" && writes == 1 && loads == 2, "historical code duplicate does not write " + code);
                Reject(delegate { engine.Execute(code, 102); }, "historical code cross-run remains blocked " + code);

                int existingWrites = 0;
                AuditEngine existing = new AuditEngine(Config(), new AuditStore(Path.Combine(root, "audited-" + code)), delegate(string id) {
                    return new FakeHandle { Snapshot = audited, Write = delegate { existingWrites++; } };
                });
                Assert(existing.Execute(code, 103).status == "already_audited" && existingWrites == 0, "audited historical order skips native audit " + code);

                Order closed = Fixture(false, code); closed.Head["cstate"] = "2";
                AuditStore closedStore = new AuditStore(Path.Combine(root, "closed-" + code));
                int closedWrites = 0;
                AuditEngine notOpen = new AuditEngine(Config(), closedStore, delegate(string id) {
                    return new FakeHandle { Snapshot = closed, Write = delegate { closedWrites++; } };
                });
                Reject(delegate { notOpen.Execute(code, 104); }, "non-open historical order rejected " + code);
                Assert(closedWrites == 0 && closedStore.Read(code) == null, "non-open order not claimed or written " + code);

                int unknownWrites = 0;
                AuditEngine uncertain = new AuditEngine(Config(), new AuditStore(Path.Combine(root, "unknown-" + code)), delegate(string id) {
                    return new FakeHandle { Snapshot = open, Write = delegate { unknownWrites++; throw new Exception("fixture"); } };
                });
                Assert(uncertain.Execute(code, 105).status == "outcome_unknown", "historical code unknown result retained " + code);
                Assert(uncertain.Execute(code, 105).status == "outcome_unknown" && unknownWrites == 1, "historical code unknown result never retries " + code);

                AuditStore pendingStore = new AuditStore(Path.Combine(root, "pending-" + code));
                pendingStore.Claim(new AuditRecord { code = code, moid = "1001", app_id = 6, oa_run_id = 106, status = "pending" });
                AuditEngine pending = new AuditEngine(Config(), pendingStore, delegate(string id) { throw new Exception("pending order must not load"); });
                Assert(pending.Execute(code, 106).status == "outcome_unknown", "historical code pending claim blocks native calls " + code);
            }
        }
        private static int Main(string[] args)
        {
            try { return Run(args); }
            catch (ApiError error) { Console.Error.WriteLine("CORE_TEST_FAILED code=" + error.Code); return 1; }
            catch (Exception error) { Console.Error.WriteLine("CORE_TEST_FAILED type=" + error.GetType().Name + " message=" + error.Message); return 1; }
        }
        private static int Run(string[] args)
        {
            CheckLoginDiagnostics();
            // 状态 9/8 仅为模拟测试值，不作为 U8 部署默认值。
            Order open = Fixture(false), audited = Fixture(true);
            Assert(open.IsOpen && !audited.IsOpen && audited.IsAudited(Config()), "explicit state configuration; no guessed production state");
            Assert(open.ToOa()["code"].Equals("0000000062") && open.ToOa()["vendorcode"].Equals("0002"), "leading zero identifiers retained");
            var entry = ((List<Dictionary<string, object>>)open.ToOa()["entry"])[0];
            Assert(entry["quantity"].Equals("500.0000") && entry["taxprice"].Equals("10.5000") && entry["unitname"].Equals("个"), "OM native detail mapping and decimal strings");
            Reject(delegate { Wire.Code("x' OR 1=1"); }, "unsafe code rejected");
            Reject(delegate { Order.Rows("<!DOCTYPE xml [<!ENTITY x SYSTEM 'file:///unused'>]><xml/>", 1); }, "DTD rejected");
            Reject(delegate { Order.Rows("<xml xmlns:rs='urn:schemas-microsoft-com:rowset'><rs:data/><rs:data/></xml>", 1); }, "duplicate rowset rejected");
            Reject(delegate { Order.Rows(Xml("ccode='a' CCODE='b'"), 1); }, "case duplicate fields rejected");
            Reject(delegate { Order.Parse(Xml("moid='1001' ccode='bad' cbustype='委外加工'"), Xml("cinvcode='1' iquantity='2'"), "0000000062", "1001"); }, "wrong identity rejected");
            Settings invalid = Config(); invalid.audited_cstate = "";
            Reject(delegate { invalid.Validate(); }, "audit disabled until state known");
            Settings parsedSettings = Wire.Json.Deserialize<Settings>(Wire.Json.Serialize(Config()));
            parsedSettings.Validate();
            Assert(parsedSettings.oa_app_id == 6 && parsedSettings.audit_enabled, "settings JSON round trip");
            string key = "test-only-public-key-32-characters", time = "1700000000", nonce = "0123456789abcdef0123456789abcdef", path = "/api/omorder/list?app_id=6&after=0";
            string signature = Wire.Sign(key, "GET", path, time, nonce, "");
            Authenticator auth = new Authenticator(key);
            auth.Check("GET", path, time, nonce, signature, "", 1700000000);
            Assert(true, "valid HMAC request");
            Reject(delegate { auth.Check("GET", path, time, nonce, signature, "", 1700000000); }, "replay rejected");
            Reject(delegate { new Authenticator(key).Check("GET", path, time, nonce, signature, "tampered", 1700000000); }, "tampered body rejected");
            Reject(delegate { new Authenticator(key).Check("GET", path, time, nonce, signature, "", 1700001000); }, "expired request rejected");
            File.WriteAllText(args[0], Wire.Json.Serialize(Wire.Map("key", key, "time", time, "nonce", nonce, "path", path, "signature", signature,
                "response", "{\"errcode\":\"0\"}", "response_signature", auth.ResponseSignature(nonce, "{\"errcode\":\"0\"}"))), new UTF8Encoding(false));
            string root = Path.Combine(Path.GetTempPath(), "u8-om-api-tests-" + Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(root);
            // 测试记录留在系统临时目录，不访问生产保护标记。
            AuditStore store = new AuditStore(Path.Combine(root, "success"));
            int loads = 0, writes = 0;
            AuditEngine engine = new AuditEngine(Config(), store, delegate(string code) {
                loads++; return new FakeHandle { Snapshot = loads == 1 ? open : audited, Write = delegate { writes++; } };
            });
            AuditRecord first = engine.Execute("0000000062", 55);
            Assert(first.status == "audited" && writes == 1 && loads == 2, "one native audit plus independent post-load");
            Assert(engine.Execute("0000000062", 55).status == "audited" && writes == 1 && loads == 2, "duplicate callback returns durable result without U8 calls");
            Reject(delegate { engine.Execute("0000000062", 56); }, "new OA run cannot bypass document lock");
            CheckHistoricalCodes(root);
            int alreadyWrites = 0;
            AuditEngine existing = new AuditEngine(Config(), new AuditStore(Path.Combine(root, "existing")), delegate(string code) { return new FakeHandle { Snapshot = audited, Write = delegate { alreadyWrites++; } }; });
            Assert(existing.Execute("0000000062", 57).status == "already_audited" && alreadyWrites == 0, "already audited skips native method");
            int unknownWrites = 0;
            AuditStore unknownStore = new AuditStore(Path.Combine(root, "unknown"));
            AuditEngine uncertain = new AuditEngine(Config(), unknownStore, delegate(string code) { return new FakeHandle { Snapshot = open, Write = delegate { unknownWrites++; throw new Exception("fixture"); } }; });
            Assert(uncertain.Execute("0000000062", 58).status == "outcome_unknown" && unknownWrites == 1, "native exception retained as unknown");
            uncertain.Execute("0000000062", 58);
            Assert(unknownWrites == 1, "unknown outcome never retries");
            AuditStore crash = new AuditStore(Path.Combine(root, "crash"));
            crash.Claim(new AuditRecord { code = "0000000062", moid = "1001", app_id = 6, oa_run_id = 59, status = "pending" });
            Assert(crash.Read("0000000062").status == "outcome_unknown", "crash after durable claim remains locked");
            Console.WriteLine("CORE_TESTS_PASS=" + count + " LIVE_U8_CALLS=0");
            return 0;
        }
    }
}
