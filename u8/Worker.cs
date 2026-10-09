using System;
using System.IO;
using System.Text;
using System.Threading;
namespace U8OmApi {
 // 每次请求单独登录、执行并退出，隔离 U8 SDK 的进程内资源。
 internal static class Worker {
  internal static string JournalRoot {get{return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),"ExampleOrg","U8OmApi","999-2026");}}
  internal static Settings ReadSettings(){
   string path=Environment.GetEnvironmentVariable("U8_OM_SETTINGS_FILE");
   if(String.IsNullOrEmpty(path))path=Path.Combine(AppDomain.CurrentDomain.BaseDirectory,"..","settings.json");
   Settings settings=Wire.Json.Deserialize<Settings>(File.ReadAllText(path,Encoding.UTF8));
   if(settings==null)throw new ApiError("CONFIG_INVALID");settings.Validate();return settings;
  }
  internal static int Execute(){
   Console.OutputEncoding=new UTF8Encoding(false);WorkerRequest request=null;WorkerReply reply=null;
   bool owned=false;
   // 与主进程锁分开：此锁串行化同账套的实际原生操作。
   using(var mutex=new Mutex(false,@"Global\ExampleOrg-U8OmApi-999-2026")){
    try{
     string input=Console.ReadLine();if(input==null||input.Length>2048)throw new ApiError("WORKER_INPUT_INVALID");
     request=Wire.Json.Deserialize<WorkerRequest>(input);
     Guid guid;if(request==null||!Guid.TryParseExact(request.Id,"N",out guid))throw new ApiError("WORKER_INPUT_INVALID");
     if(request.Operation!="list"&&request.Operation!="detail"&&request.Operation!="audit")throw new ApiError("WORKER_OPERATION_INVALID");
     if(request.Operation=="list"){if(request.Cursor<0)throw new ApiError("INVALID_CURSOR");}else Wire.Code(request.Code);
     Settings settings=ReadSettings();
     if(request.Operation=="audit"&&(!settings.audit_enabled||request.Run<1))throw new ApiError("AUDIT_DISABLED");
     try{owned=mutex.WaitOne(0);}catch(AbandonedMutexException){owned=true;}
     if(!owned)throw new ApiError("ANOTHER_API_PROCESS_IS_RUNNING");
     if(DateTime.Today.Year!=2026)throw new ApiError("ACCOUNT_YEAR_REQUIRES_MANUAL_REVIEW");
     ResourceBudget.Sample("before_login");
     // 工作进程自行读取本机凭据，登录结束后清除临时引用。
     var credentials=Credentials.Read();U8Runtime runtime;
     try{runtime=new U8Runtime(credentials.password);}finally{credentials.Clear();}
     object data;
     using(runtime){
      ResourceBudget.Sample("after_login");
      if(request.Operation=="list")data=runtime.List(request.Cursor);
      else if(request.Operation=="detail"){
       using(NativeOrder order=runtime.Load(request.Code))data=Wire.Map("omorder",order.Snapshot.ToOa());
      }else{
       var engine=new AuditEngine(settings,new AuditStore(JournalRoot),delegate(string code){return runtime.Load(code);});
       data=engine.Execute(request.Code,request.Run);
      }
     }
     ResourceBudget.Sample("after_dispose");
     reply=new WorkerReply{Id=request.Id,Error="0",Payload=Wire.Json.Serialize(data)};
    }catch(Exception e){
     var known=e as ApiError;
     reply=new WorkerReply{Id=request==null?"":request.Id,Error=known==null?"WORKER_NATIVE_FAILED":known.Code,Payload="null",Failure=NativeFailure.Capture(e)};
     Console.Error.WriteLine("WORKER_FAILED type="+e.GetType().Name);
    }finally{if(owned)mutex.ReleaseMutex();}
   }
   Console.WriteLine("WORKER_RESULT="+Wire.Json.Serialize(reply));return 0;
  }
 }
}
