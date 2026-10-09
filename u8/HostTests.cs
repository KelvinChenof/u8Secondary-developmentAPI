using System;
using System.IO;

namespace U8OmApi
{
    // 子进程运行本测试 EXE 的模拟入口，不调用 U8；主接口和传输使用真实代码。
    internal static class Worker
    {
        internal static string JournalRoot;
        internal static Settings ReadSettings() { return new Settings { oa_app_id = 1001 }; }
    }
    internal static class HostTests
    {
        private static int checks;
        private static string TraceFile { get { return Environment.GetEnvironmentVariable("U8_OM_HOST_TEST_TRACE"); } }
        private static int WorkerCalls { get { return File.Exists(TraceFile) ? File.ReadAllLines(TraceFile).Length : 0; } }
        private static void Check(bool value, string name) { if (!value) throw new Exception(name); checks++; }
        private static void Reject(Action action, string code, string name)
        {
            try { action(); } catch (ApiError error) { Check(error.Code == code, name); return; }
            throw new Exception("Expected rejection: " + name);
        }
        private static void Scope(string root, string name)
        {
            Worker.JournalRoot = Path.Combine(root, name); Directory.CreateDirectory(Worker.JournalRoot);
            Environment.SetEnvironmentVariable("U8_OM_HOST_TEST_TRACE", Path.Combine(Worker.JournalRoot, "worker-trace.txt"));
        }
        private static int Main(string[] args)
        {
            try { return Run(args); }
            catch (ApiError error) { Console.Error.WriteLine("HOST_TEST_FAILED code=" + error.Code); return 1; }
            catch (Exception error) { Console.Error.WriteLine("HOST_TEST_FAILED type=" + error.GetType().Name + " message=" + error.Message); return 1; }
        }
        private static int Run(string[] args)
        {
            if (args.Length == 1 && args[0] == "--native-worker")
            {
                var request = Wire.Json.Deserialize<WorkerRequest>(Console.ReadLine());
                File.AppendAllText(TraceFile, request.Code + Environment.NewLine);
                Console.WriteLine("WORKER_RESULT=" + Wire.Json.Serialize(new WorkerReply { Id = request.Id, Error = "0", Failure = null, Payload = Wire.Json.Serialize(new AuditRecord {
                    code = request.Code, moid = "1001", app_id = 1001, oa_run_id = request.Run, status = "audited"
                }) }));
                return 0;
            }
            string root = Path.Combine(Path.GetTempPath(), "u8-om-host-tests-" + Guid.NewGuid().ToString("N"));
            foreach (string code in new[] { "0000009001", "0000009002" })
            {
                Scope(root, "new-" + code);
                var request = new WorkerRequest { Operation = "audit", Code = code, Run = 101 };
                var result = (AuditRecord)IsolatedHost.Dispatch(request);
                Check(result.status == "audited" && result.code == code && WorkerCalls == 1, "historical code dispatched normally " + code);
                new NativeLatch(Worker.JournalRoot).Check(); Check(true, "confirmed success releases latch " + code);

                Scope(root, "result-" + code);
                var store = new AuditStore(Worker.JournalRoot);
                store.Claim(new AuditRecord { code = code, moid = "1001", app_id = 1001, oa_run_id = 101, status = "pending" });
                store.Complete(new AuditRecord { code = code, moid = "1001", app_id = 1001, oa_run_id = 101, status = "audited" });
                Check(((AuditRecord)IsolatedHost.Dispatch(request)).status == "audited" && WorkerCalls == 0, "existing result skips worker " + code);
                Reject(delegate { IsolatedHost.Dispatch(new WorkerRequest { Operation = "audit", Code = code, Run = 102 }); }, "DOCUMENT_ALREADY_CLAIMED", "cross-run claim remains blocked " + code);

                Scope(root, "pending-" + code);
                new AuditStore(Worker.JournalRoot).Claim(new AuditRecord { code = code, moid = "1001", app_id = 1001, oa_run_id = 101, status = "pending" });
                Check(((AuditRecord)IsolatedHost.Dispatch(request)).status == "outcome_unknown" && WorkerCalls == 0, "pending claim skips worker " + code);

                Scope(root, "latch-" + code);
                new NativeLatch(Worker.JournalRoot).Claim(code, 101);
                Reject(delegate { IsolatedHost.Dispatch(request); }, "NATIVE_WORKER_RECONCILIATION_REQUIRED", "unfinished task remains blocked " + code);
                Check(WorkerCalls == 0, "blocked task never starts worker " + code);
            }
            Console.WriteLine("HOST_TESTS_OK=" + checks + " LIVE_U8_CALLS=0"); return 0;
        }
    }
}
