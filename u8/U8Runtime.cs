using System;
using System.Collections.Generic;
using System.Globalization;
using System.Reflection;
using System.Runtime.InteropServices;
using UFIDA.U8.U8APIFramework;

namespace U8OmApi
{
    // 封装 COM 反射调用与释放，兼容 U8 接口中的引用参数。
    internal static class Com
    {
        internal static object Create(string progId) { return Activator.CreateInstance(Type.GetTypeFromProgID(progId, true)); }
        internal static object Clsid(string id) { return Activator.CreateInstance(Type.GetTypeFromCLSID(new Guid(id), true)); }
        internal static object Get(object target, string name, params object[] args)
        { return target.GetType().InvokeMember(name, BindingFlags.GetProperty, null, target, args); }
        internal static void Set(object target, string name, object value)
        { target.GetType().InvokeMember(name, BindingFlags.SetProperty, null, target, new[] { value }); }
        internal static object Call(object target, string name, object[] args, params int[] byref)
        {
            ParameterModifier[] modifiers = null;
            if (byref.Length > 0)
            {
                ParameterModifier modifier = new ParameterModifier(args.Length);
                foreach (int i in byref) modifier[i] = true;
                modifiers = new[] { modifier };
            }
            return target.GetType().InvokeMember(name, BindingFlags.InvokeMethod, null, target, args, modifiers, CultureInfo.InvariantCulture, null);
        }
        internal static void Release(object value)
        { try { if (value != null && Marshal.IsComObject(value)) Marshal.ReleaseComObject(value); } catch { } }
    }

    internal sealed class Identity { internal string Code; internal string Moid; }
    internal sealed class U8Runtime : IDisposable
    {
        // 登录目标固定；999/2026 的身份校验必须与 Core.cs 保持一致。
        internal const string Server = "192.0.2.11", Account = "999", Year = "2026", Operator = "1示例操作员", Subsystem = "AS";
        private U8Login.clsLogin login;
        private readonly string loginDate;
        internal string Stage = "Login";
        internal U8Runtime(string password)
        {
            // U8 原生组件为 32 位，运行进程也必须为 x86。
            if (IntPtr.Size != 4) throw new ApiError("X86_REQUIRED");
            string sub = Subsystem, account = Account, year = Year, user = Operator, server = Server, serial = "";
            loginDate = DateTime.Today.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
            string date = loginDate;
            try
            {
                Stage = "LoginInitialize";
                login = new U8Login.clsLogin();
                Console.WriteLine("login_business_date=" + loginDate);
                Stage = "LoginInvoke";
                Console.WriteLine("login_invoke_entered=true");
                bool ok = login.Login(ref sub, ref account, ref year, ref user, ref password, ref date, ref server, ref serial);
                password = null;
                Console.WriteLine("login_invoke_returned=true");
                Console.WriteLine("login_authenticated=" + ok);
                if (!ok) throw new ApiError(LoginChecks.Failure(false, null), "LoginAuthentication");
                Stage = "LoginContextRead";
                // 登录返回成功后继续核对账套、年度、服务器和操作员。
                Dictionary<string, string> evidence = new Dictionary<string, string> {
                    {"returned_sub_id", sub}, {"returned_account_id", account}, {"returned_year", year},
                    {"returned_operator", user}, {"returned_business_date", date}, {"returned_server", server},
                    {"login_sub_id", ReadLoginField("login_sub_id", delegate { return login.cSub_Id; })},
                    {"login_account_id", ReadLoginField("login_account_id", delegate { return login.get_cAcc_Id(); })},
                    {"login_year", ReadLoginField("login_year", delegate { return login.cIYear; })},
                    {"login_server", ReadLoginField("login_server", delegate { return login.cServer; })}
                };
                Dictionary<string, bool> matches = LoginChecks.Compare(evidence, loginDate);
                foreach (KeyValuePair<string, bool> match in matches)
                    Console.WriteLine("LOGIN_CONTEXT_MATCH field=" + match.Key + " match=" + (match.Value ? "true" : "false"));
                string failure = LoginChecks.Failure(true, matches);
                if (failure != null) throw new ApiError(failure, "LoginContextValidation");
                Stage = "Ready";
            }
            catch (ApiError) { Dispose(); throw; }
            catch (Exception exception) { Dispose(); throw new ApiError("LOGIN_RUNTIME_ERROR", Stage, exception); }
            finally { password = null; }
        }
        private static string ReadLoginField(string name, Func<object> reader)
        {
            try { return Convert.ToString(reader(), CultureInfo.InvariantCulture); }
            catch (Exception exception)
            {
                Console.WriteLine("login_context_read_failed=" + name);
                throw new ApiError("LOGIN_CONTEXT_READ_FAILED", "LoginContextRead", exception);
            }
        }
        internal void CheckDate()
        {
            if (login == null || DateTime.Today.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture) != loginDate) throw new ApiError("LOGIN_EXPIRED_RESTART_REQUIRED");
        }
        internal object OpenConnection()
        {
            CheckDate();
            object connection = Com.Create("ADODB.Connection");
            try { Com.Call(connection, "Open", new object[] { login.UfDbName, "", "", -1 }); return connection; }
            catch { Com.Release(connection); throw; }
        }
        internal static void CloseConnection(object connection)
        {
            try { if (connection != null && Convert.ToInt32(Com.Get(connection, "State")) == 1) Com.Call(connection, "Close", new object[0]); } catch { }
            Com.Release(connection);
        }
        // 固定参数化 SELECT 只查单据身份，不接受调用方提供 SQL 或表名。
        internal const string ListSql = "SELECT TOP 11 MOID,cCode FROM dbo.OM_MOMain WHERE MOID > ? AND cState = 0 AND iVerifyStateNew = 0 AND IsWfControlled = 0 AND ISNULL(cVerifier, N'') = N'' AND dVerifyDate IS NULL AND ISNULL(cCloser, N'') = N'' AND dCloseDate IS NULL ORDER BY MOID";
        internal const string ResolveSql = "SELECT TOP 2 MOID,cCode FROM dbo.OM_MOMain WHERE cCode = ?";
        private List<Identity> Select(object connection, string sql, object value, bool codeParameter)
        {
            object command = null, parameter = null, parameters = null, rows = null;
            try
            {
                command = Com.Create("ADODB.Command");
                Com.Set(command, "ActiveConnection", connection);
                Com.Set(command, "CommandType", 1);
                Com.Set(command, "CommandTimeout", 30);
                Com.Set(command, "CommandText", sql);
                parameter = Com.Call(command, "CreateParameter", new object[] { codeParameter ? "code" : "after", codeParameter ? 202 : 20, 1, codeParameter ? 30 : 8, value });
                parameters = Com.Get(command, "Parameters");
                Com.Call(parameters, "Append", new[] { parameter });
                object[] args = { null, Type.Missing, -1 };
                rows = Com.Call(command, "Execute", args, 0);
                List<Identity> identities = new List<Identity>();
                while (!Convert.ToBoolean(Com.Get(rows, "EOF")))
                {
                    if (identities.Count >= (codeParameter ? 2 : 11)) throw new ApiError("QUERY_ROW_LIMIT");
                    string moid = Field(rows, 0), code = Field(rows, 1);
                    Wire.Positive(moid); Wire.Code(code);
                    identities.Add(new Identity { Moid = moid, Code = code });
                    Com.Call(rows, "MoveNext", new object[0]);
                }
                return identities;
            }
            finally
            {
                try { if (rows != null) Com.Call(rows, "Close", new object[0]); } catch { }
                Com.Release(rows); Com.Release(parameters); Com.Release(parameter); Com.Release(command);
            }
        }
        private static string Field(object rows, int index)
        {
            object fields = null, field = null;
            try { fields = Com.Get(rows, "Fields"); field = Com.Get(fields, "Item", index); return Convert.ToString(Com.Get(field, "Value"), CultureInfo.InvariantCulture); }
            finally { Com.Release(field); Com.Release(fields); }
        }
        internal Identity Resolve(object connection, string code)
        {
            // 单号必须唯一对应一个 MOID，避免同号或错误账套造成误操作。
            Stage = "ResolveOrderReadOnly";
            List<Identity> result = Select(connection, ResolveSql, Wire.Code(code), true);
            if (result.Count == 0) throw new ApiError("ORDER_NOT_FOUND");
            if (result.Count != 1 || result[0].Code != code) throw new ApiError("ORDER_IDENTITY_AMBIGUOUS");
            return result[0];
        }
        internal Dictionary<string, object> List(long after)
        {
            if (after < 0) throw new ApiError("INVALID_CURSOR");
            ResourceBudget.Sample("before_list");
            Stage = "ListIdentityReadOnly";
            object connection = OpenConnection();
            List<Identity> candidates;
            try { candidates = Select(connection, ListSql, after, false); }
            finally { CloseConnection(connection); }
            bool more = candidates.Count > 10;
            if (more) candidates.RemoveAt(10);
            List<Dictionary<string, object>> open = new List<Dictionary<string, object>>();
            string cursor = after.ToString(CultureInfo.InvariantCulture);
            foreach (Identity identity in candidates)
            {
                using (NativeOrder handle = Load(identity.Code))
                {
                    if (handle.Snapshot.Moid != identity.Moid) throw new ApiError("IDENTITY_MISMATCH");
                    if (handle.Snapshot.IsOpen) open.Add(Wire.Map("code", identity.Code));
                }
                cursor = identity.Moid;
            }
            return Wire.Map("orders", open, "next_cursor", more ? cursor : null);
        }
        internal NativeOrder Load(string code)
        {
            ResourceBudget.Sample("before_load");
            Stage = "OpenConnection";
            NativeOrder handle = new NativeOrder();
            MSXML2.IXMLDOMDocument2 head = null, body = null;
            try
            {
                handle.Connection = OpenConnection();
                Identity identity = Resolve(handle.Connection, code);
                Stage = "U8Load";
                // 用 U8 原生 load 获取完整单据，再初始化审核所需业务对象。
                U8EnvContext context = new U8EnvContext();
                context.U8Login = login;
                context.SetContext("dbconnectionObject", handle.Connection);
                handle.Broker = new U8ApiBroker(new U8ApiAddress("U8API/omOrder/VoucherOperate"), context);
                handle.Broker.GetBoParam("domhead"); handle.Broker.GetBoParam("dombody");
                handle.Broker.AssignNormalValue("actiontype", "load");
                handle.Broker.AssignNormalValue("error", String.Empty);
                handle.Broker.AssignNormalValue("voucherid", identity.Moid);
                handle.Broker.AssignNormalValue("strwhere", String.Empty);
                if (!handle.Broker.Invoke() || !Convert.ToBoolean(handle.Broker.GetReturnValue())) throw new ApiError("U8_LOAD_REJECTED");
                head = handle.Broker.GetResult("domhead") as MSXML2.IXMLDOMDocument2;
                body = handle.Broker.GetResult("dombody") as MSXML2.IXMLDOMDocument2;
                if (head == null || body == null) throw new ApiError("LOAD_DOM_MISSING");
                Stage = "InitCompleteVO";
                object adapter = handle.Broker.MomCallContext.EnvContext.GetContext("BizAPIAdapter");
                if (adapter == null) throw new ApiError("ADAPTER_MISSING");
                handle.Facade = Com.Call(adapter, "GetBusinessFacade", new object[0]);
                handle.Vo = Com.Clsid("c74844ad-49d9-41c3-a65a-fb408c0f4426");
                object[] init = { head, body, "om01" };
                if (!Convert.ToBoolean(Com.Call(handle.Vo, "InitVO", init, 0, 1, 2))) throw new ApiError("VO_INIT_REJECTED");
                handle.ReadSnapshot(identity.Code, identity.Moid);
                Stage = "Ready";
                return handle;
            }
            catch { handle.Dispose(); throw; }
            finally { Com.Release(body); Com.Release(head); }
        }
        public void Dispose()
        {
            if (login != null) { try { login.ShutDown(); } catch { } Com.Release(login); login = null; }
        }
    }

    internal sealed class NativeOrder : IOrderHandle
    {
        internal object Connection, Facade, Vo;
        internal U8ApiBroker Broker;
        private object errors;
#if !QUERY_ONLY
        private bool attempted;
#endif
        public Order Snapshot { get; private set; }
        internal void ReadSnapshot(string code, string moid)
        {
            object head = null, body = null;
            try {
                head = Com.Get(Vo, "headDom"); body = Com.Get(Vo, "bodyDom");
                if (head == null || body == null) throw new ApiError("VO_DOM_MISSING");
                Snapshot = Order.Parse(Convert.ToString(Com.Get(head, "xml")), Convert.ToString(Com.Get(body, "xml")), code, moid);
            } finally { Com.Release(body); Com.Release(head); }
        }
        public NativeResult Audit()
        {
#if QUERY_ONLY
            throw new ApiError("QUERY_ONLY_AUDIT_DISABLED");
#else
            // 一个单据句柄最多尝试审核一次，调用前重新检查开立状态。
            if (attempted || Snapshot == null) throw new ApiError("AUDIT_ALREADY_ATTEMPTED");
            attempted = true;
            ReadSnapshot(Snapshot.Code, Snapshot.Moid);
            if (!Snapshot.IsOpen || Convert.ToBoolean(Com.Get(Facade, "bNotTrans")) || Convert.ToInt32(Com.Get(Connection, "State")) != 1)
                return new NativeResult { Reason = "NATIVE_PREPARATION_REJECTED" };
            errors = Com.Clsid("89d691e5-cb6d-4f0e-b3a3-f978b2800fe8");
            object[] args = { Vo, Connection, errors };
            Console.WriteLine("native_audit_entered=true code=" + Snapshot.Code);
            // 唯一原生审核写入点，保留 U8 回传的对象和业务错误。
            object raw = Com.Call(Facade, "AuditVoucher", args, 0, 1, 2);
            Vo = args[0]; Connection = args[1]; errors = args[2];
            Console.WriteLine("native_audit_returned=true");
            if (!(raw is bool)) return new NativeResult { Returned = true, Reason = "NATIVE_RETURN_UNREADABLE" };
            bool success = (bool)raw;
            int count = Convert.ToInt32(Com.Get(errors, "Count"));
            string description = Convert.ToString(Com.Get(errors, "UserDescription"));
            Console.WriteLine("native_audit_raw_result=" + success + " native_error_count=" + count);
            return new NativeResult { Returned = true, Succeeded = success && count == 0 && String.IsNullOrWhiteSpace(description),
                Reason = count != 0 || !String.IsNullOrWhiteSpace(description) ? "NATIVE_BUSINESS_ERROR" : success ? "NATIVE_ACCEPTED" : "NATIVE_RETURN_FALSE" };
#endif
        }
        public void Dispose()
        {
            // 释放单据 COM 对象、Broker 和连接，缩短原生资源持有时间。
            Com.Release(errors); Com.Release(Vo); Com.Release(Facade);
            errors = null; Vo = null; Facade = null;
            try { if (Broker != null) Broker.Release(); } catch { }
            Broker = null;
            U8Runtime.CloseConnection(Connection); Connection = null;
        }
    }
}
