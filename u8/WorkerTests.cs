using System;
using System.Diagnostics;
using System.IO;
using System.Threading;
using System.Runtime.InteropServices;
using System.Reflection;
namespace U8OmApi {
 internal static class WorkerTests {
  static int checks;
  static void Check(bool b,string n){if(!b)throw new Exception(n);checks++;}
  static void Reject(Action a,string n){try{a();}catch(ApiError){checks++;return;}throw new Exception(n);}
  public static int Main(string[] args){
   try{return RunTests(args);}catch(Exception e){Console.Error.WriteLine("WORKER_TEST_FAILED="+e.Message);return 1;}
  }
  static int RunTests(string[] args){
   if(args.Length==1 && args[0]=="--native-worker"){
    var r=Wire.Json.Deserialize<WorkerRequest>(Console.ReadLine());
    if(r.Operation=="timeout"){Thread.Sleep(1200);if(r.Code!=null)File.WriteAllText(r.Code,"completed");return 0;}
    if(r.Operation=="crash")return 17;
    if(r.Operation=="login-failure"){
     Console.Error.WriteLine("SDK_PASSWORD_DO_NOT_FORWARD");
     Console.WriteLine("WORKER_RESULT="+Wire.Json.Serialize(Wire.Map("Id",r.Id,"Error","LOGIN_RUNTIME_ERROR","Payload","null","Failure",new[]{Wire.Map("Type","System.Runtime.InteropServices.COMException","Stage","LoginInitialize","Code","LOGIN_RUNTIME_ERROR","HResult","0x80040154")})));return 0;
    }
    var reply=new WorkerReply{Id=r.Id,Error="0",Payload="{\"ok\":true}",Failure=null};
    if(r.Operation=="wrong-id")reply.Id="wrong";
    Console.WriteLine("native incidental output");
    Console.WriteLine("WORKER_RESULT="+Wire.Json.Serialize(reply));return 0;
   }
   string exe=Process.GetCurrentProcess().MainModule.FileName;
   var captured=new StringWriter();var previous=Console.Error;
   try{
    Console.SetError(captured);
    Check(WorkerTransport.Run(exe,new WorkerRequest{Operation="login-failure"},false,5000).Error=="LOGIN_RUNTIME_ERROR","stable error unchanged");
   }finally{Console.SetError(previous);}
   Check(captured.ToString().Contains("LoginInitialize")&&captured.ToString().Contains("0x80040154"),"native login stage and HRESULT reach host log");
   Check(!captured.ToString().Contains("SDK_PASSWORD_DO_NOT_FORWARD"),"raw SDK stderr never forwarded");
   var chain=NativeFailure.Capture(new TargetInvocationException(new ApiError("LOGIN_RUNTIME_ERROR","LoginInitialize",new COMException("SDK_PASSWORD_DO_NOT_FORWARD",unchecked((int)0x80040154)))));
   Check(chain.Length==3&&chain[1].Stage=="LoginInitialize"&&chain[2].HResult=="0x80040154","nested native exception chain retained");
   captured=new StringWriter();
   try{Console.SetError(captured);NativeFailure.Log(new WorkerRequest{Id="fixture",Operation="detail"},chain);NativeFailure.Log(new WorkerRequest{Id="fixture",Operation="detail"},new[]{new NativeFailure{Type="System.Exception",HResult="0x80004005",Code="SECRET\npassword",Stage="password=SECRET"}});}finally{Console.SetError(previous);}
   Check(!captured.ToString().Contains("SECRET")&&!captured.ToString().Contains("SDK_PASSWORD"),"messages and malformed metadata redacted");
   Check(WorkerTransport.Run(exe,new WorkerRequest{Operation="success",Cursor=0,Run=0},false,5000).Error=="0","worker exits before result accepted");
   Reject(delegate{WorkerTransport.Run(exe,new WorkerRequest{Operation="wrong-id"},false,5000);},"correlation checked");
   Reject(delegate{WorkerTransport.Run(exe,new WorkerRequest{Operation="crash"},false,5000);},"crash not successful");
   string readMarker=Path.Combine(Path.GetTempPath(),Guid.NewGuid().ToString("N"));
   string auditMarker=Path.Combine(Path.GetTempPath(),Guid.NewGuid().ToString("N"));
   Reject(delegate{WorkerTransport.Run(exe,new WorkerRequest{Operation="timeout",Code=readMarker},false,100);},"read timeout fails");
   Reject(delegate{WorkerTransport.Run(exe,new WorkerRequest{Operation="timeout",Code=auditMarker},true,100);},"audit timeout uncertain");
   Thread.Sleep(1600); // 模拟审核自行完成，传输超时不能强杀审核进程
   Check(!File.Exists(readMarker),"timed out read process was terminated");
   Check(File.Exists(auditMarker),"timed out audit was NOT terminated");File.Delete(auditMarker);
   string root=Path.Combine(Path.GetTempPath(),"om-latch-"+Guid.NewGuid().ToString("N"));Directory.CreateDirectory(root);
   var latch=new NativeLatch(root);latch.Check();latch.Claim("123",1);
   Reject(delegate{latch.Check();},"durable uncertainty blocks redispatch");
   Reject(delegate{new NativeLatch(root).Check();},"latch survives host restart");
   latch.ClearConfirmed();latch.Check();Directory.Delete(root);
   Console.WriteLine("WORKER_TESTS_OK="+checks);return 0;
  }
 }
}
