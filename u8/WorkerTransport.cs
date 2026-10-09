using System;
using System.Diagnostics;
using System.IO;
using System.Text;
using System.Threading.Tasks;
using System.Text.RegularExpressions;
using System.Globalization;
using System.Reflection;
namespace U8OmApi {
 internal sealed class WorkerRequest { public string Id;public string Operation;public string Code;public long Cursor;public long Run; }
 internal sealed class WorkerReply { public string Id;public string Error;public string Payload;public NativeFailure[] Failure; }
 // 只记录异常类型、阶段和 HRESULT，不输出可能含凭据的异常正文。
 internal sealed class NativeFailure {
  public string Type;public string Code;public string Stage;public string HResult;
  static string Token(string value){return value!=null&&Regex.IsMatch(value,@"\A[A-Za-z0-9_]{1,90}\z")?value:null;}
  internal static NativeFailure[] Capture(Exception error){
   var rows=new System.Collections.Generic.List<NativeFailure>();
   for(int depth=0;error!=null&&depth<8;depth++,error=error.InnerException){
    var known=error as ApiError;
    int hr=(int)typeof(Exception).GetProperty("HResult",BindingFlags.Instance|BindingFlags.Public|BindingFlags.NonPublic).GetValue(error,null);
    rows.Add(new NativeFailure{Type=error.GetType().FullName,Code=known==null?null:Token(known.Code),Stage=known==null?null:Token(known.Stage),HResult="0x"+hr.ToString("X8",CultureInfo.InvariantCulture)});
   }
   return rows.ToArray();
  }
  internal static void Log(WorkerRequest request,NativeFailure[] rows){
   if(rows==null||rows.Length>8)return;
   foreach(var row in rows){
    if(row==null||row.Type==null||!Regex.IsMatch(row.Type,@"\A[A-Za-z0-9_.+`]{1,160}\z")||row.HResult==null||!Regex.IsMatch(row.HResult,@"\A0x[0-9A-F]{8}\z"))continue;
    Console.Error.WriteLine("NATIVE_FAILURE id="+request.Id+" operation="+Token(request.Operation)+" type="+row.Type+" code="+Token(row.Code)+" stage="+Token(row.Stage)+" hresult="+row.HResult);
   }
  }
 }
 // 全局任务保护与订单审核日志独立；遗留标记要求人工复核。
 internal sealed class NativeLatch {
  readonly string path;
  internal NativeLatch(string root){path=Path.Combine(root,"native-worker-pending.json");}
  internal void Check(){if(File.Exists(path))throw new ApiError("NATIVE_WORKER_RECONCILIATION_REQUIRED");}
  internal void Claim(string code,long run){
   Directory.CreateDirectory(Path.GetDirectoryName(path));
   byte[] bytes=Encoding.UTF8.GetBytes(Wire.Json.Serialize(Wire.Map("code",code,"oa_run_id",run,"at",DateTime.UtcNow.ToString("o"))));
   // 独占新建并刷盘，拒绝覆盖另一任务或故障留下的标记。
   using(var f=new FileStream(path,FileMode.CreateNew,FileAccess.Write,FileShare.Read)){f.Write(bytes,0,bytes.Length);f.Flush(true);}
  }
  internal void ClearConfirmed(){File.Delete(path);}
 }
 internal static class WorkerTransport {
  static string BoundedRead(StreamReader reader,int max){
   // 超限后仍排空管道，避免子进程阻塞在输出写入。
   var result=new StringBuilder();char[] buffer=new char[4096];int n;bool overflow=false;
   while((n=reader.Read(buffer,0,buffer.Length))>0){if(result.Length+n<=max)result.Append(buffer,0,n);else overflow=true;}
   if(overflow)throw new ApiError("WORKER_OUTPUT_LIMIT");return result.ToString();
  }
  internal static WorkerReply Run(string exe,WorkerRequest request,bool audit,int timeoutMs){
   request.Id=Guid.NewGuid().ToString("N");
   using(var process=new Process()){
    process.StartInfo=new ProcessStartInfo(exe,"--native-worker"){
     UseShellExecute=false,CreateNoWindow=true,WorkingDirectory=Path.GetDirectoryName(exe),
     RedirectStandardInput=true,RedirectStandardOutput=true,RedirectStandardError=true,
     StandardOutputEncoding=Encoding.UTF8,StandardErrorEncoding=Encoding.UTF8};
    if(!process.Start())throw new ApiError("WORKER_START_FAILED");
    Task<string> stdout=Task.Factory.StartNew(delegate{return BoundedRead(process.StandardOutput,8*1024*1024);});
    Task<string> stderr=Task.Factory.StartNew(delegate{return BoundedRead(process.StandardError,128*1024);});
    // 管道只传固定操作和单据身份，不传凭据、任意命令或 SQL。
    process.StandardInput.WriteLine(Wire.Json.Serialize(request));process.StandardInput.Close();
    var timer=Stopwatch.StartNew();string stopReason=null;
    while(!process.WaitForExit(100)){
     if(timer.ElapsedMilliseconds>=timeoutMs){stopReason="WORKER_TIMEOUT";break;}
     try{process.Refresh();ResourceBudget.Check(TcpOwners.Count(process.Id),process.HandleCount,process.PrivateMemorySize64);}
     catch{if(!process.HasExited){stopReason="WORKER_RESOURCE_LIMIT";break;}}
    }
    if(stopReason!=null){
     if(!audit){
      if(!process.HasExited)process.Kill();
      if(!process.WaitForExit(5000))throw new ApiError("READ_WORKER_EXIT_UNCONFIRMED");
     }
     // 审核超时不强杀、不重试；结果未知时由持久化标记拦截后续任务。
     throw new ApiError(audit?"AUDIT_WORKER_OUTCOME_UNKNOWN":stopReason);
    }
    if(!Task.WaitAll(new Task[]{stdout,stderr},5000))throw new ApiError("WORKER_OUTPUT_INCOMPLETE");
    foreach(string line in stderr.Result.Split('\n'))if(line.StartsWith("RESOURCE ")&&line.Length<300)Console.Error.WriteLine(line.Trim());
    if(process.ExitCode!=0)throw new ApiError(audit?"AUDIT_WORKER_OUTCOME_UNKNOWN":"WORKER_EXIT_FAILED");
    WorkerReply reply=null;
    foreach(string line in stdout.Result.Split('\n'))if(line.StartsWith("WORKER_RESULT=")){
     if(reply!=null)throw new ApiError("WORKER_DUPLICATE_RESULT");
     reply=Wire.Json.Deserialize<WorkerReply>(line.Substring(14));
    }
    // 关联 ID 必须一致，防止将其他请求的输出当成本次结果。
    if(reply==null||reply.Id!=request.Id||String.IsNullOrEmpty(reply.Error))throw new ApiError("WORKER_RESULT_INVALID");
    if(reply.Error!="0")NativeFailure.Log(request,reply.Failure);
    return reply;
   }
  }
 }
}
