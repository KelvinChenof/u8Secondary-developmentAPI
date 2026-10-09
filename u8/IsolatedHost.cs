using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Net;
using System.Text;
using System.Threading;
namespace U8OmApi {
 internal static class IsolatedHost {
  // 优先返回已有审核记录，避免重复回调再次进入 U8。
  internal static object Dispatch(WorkerRequest request){
   if(request.Operation=="audit"){
    AuditRecord prior=new AuditStore(Worker.JournalRoot).Read(request.Code);
    if(prior!=null){
     if(prior.oa_run_id!=request.Run||prior.app_id!=Worker.ReadSettings().oa_app_id)throw new ApiError("DOCUMENT_ALREADY_CLAIMED");
     return prior; // 重复或结果未知的回调直接返回已有记录，不再登录 U8。
    }
   }
   var latch=new NativeLatch(Worker.JournalRoot);latch.Check();
   // 读写任务都先落盘保护标记；主进程崩溃后仍能阻止重叠调用。
   latch.Claim(request.Code??"",request.Run);
   WorkerReply reply=WorkerTransport.Run(Process.GetCurrentProcess().MainModule.FileName,request,request.Operation=="audit",120000);
   if(request.Operation!="audit")latch.ClearConfirmed();
   if(reply.Error!="0")throw new ApiError(reply.Error);
   if(request.Operation=="audit"){
    AuditRecord record=Wire.Json.Deserialize<AuditRecord>(reply.Payload);
    if(record==null||record.code!=request.Code||record.oa_run_id!=request.Run)throw new ApiError("AUDIT_WORKER_RESULT_MISMATCH");
    // 审核结果未知时保留保护标记，等待人工核对实际单据状态。
    if(record.status=="audited"||record.status=="already_audited")latch.ClearConfirmed();
    return record;
   }
   return Wire.Json.DeserializeObject(reply.Payload);
  }
  internal static void Serve(Settings settings,Authenticator auth){
   var store=new AuditStore(Worker.JournalRoot);
   using(var listener=new HttpListener()){
    listener.Prefixes.Add(settings.listen_prefix);listener.Start();
    Console.WriteLine("HTTP_READY app_id="+settings.oa_app_id+" audit_enabled="+settings.audit_enabled+" native_mode=isolated");
    while(listener.IsListening){
     var context=listener.GetContext();object data=null;string error="0";int status=200;
     try{
      var request=context.Request;
      if(Array.IndexOf(settings.allowed_source_ips,request.RemoteEndPoint.Address.ToString())<0)throw new ApiError("SOURCE_IP_DENIED");
      if((request.HasEntityBody&&request.ContentLength64<0)||request.ContentLength64>8192||request.RawUrl.Length>2048)throw new ApiError("REQUEST_TOO_LARGE");
      string body;using(var reader=new StreamReader(request.InputStream,new UTF8Encoding(false,true)))body=reader.ReadToEnd();
      if(Encoding.UTF8.GetByteCount(body)>8192)throw new ApiError("REQUEST_TOO_LARGE");
      long now=(long)(DateTime.UtcNow-new DateTime(1970,1,1)).TotalSeconds;
      // 校验来源 IP 后验签，并检查时间窗口和 nonce 防止请求重放。
      auth.Check(request.HttpMethod,request.RawUrl,request.Headers["X-OM-Time"],request.Headers["X-OM-Nonce"],request.Headers["X-OM-Signature"],body,now);
      if(request.HttpMethod=="GET"){
       if(body.Length!=0||request.QueryString.GetValues("app_id")==null||request.QueryString.GetValues("app_id").Length!=1||request.QueryString["app_id"]!=settings.oa_app_id.ToString(CultureInfo.InvariantCulture))throw new ApiError("APP_ID_MISMATCH");
       if(request.Url.AbsolutePath=="/api/omorder/list"){
        CheckQuery(request,"app_id","after");string after=request.QueryString["after"]??"0";
        data=Dispatch(new WorkerRequest{Operation="list",Cursor=after=="0"?0:Wire.Positive(after)});
       }else if(request.Url.AbsolutePath=="/api/omorder/detail"){
        CheckQuery(request,"app_id","id");data=Dispatch(new WorkerRequest{Operation="detail",Code=Wire.Code(request.QueryString["id"])});
       }else if(request.Url.AbsolutePath=="/api/omorder/operation"){
        // 仅查询本地审核记录，不启动工作进程；保护未解除时仍可复核。
        CheckQuery(request,"app_id","id","oa_run_id");var record=store.Read(Wire.Code(request.QueryString["id"]));
        if(record==null)throw new ApiError("OPERATION_NOT_FOUND");
        if(record.app_id!=settings.oa_app_id||record.oa_run_id!=Wire.Positive(request.QueryString["oa_run_id"]))throw new ApiError("OPERATION_IDENTITY_MISMATCH");
        data=record;
       }else throw new ApiError("ROUTE_NOT_FOUND");
      }else if(request.HttpMethod=="POST"&&request.Url.AbsolutePath=="/api/omorder/audit"&&request.Url.Query.Length==0){
       if(!settings.audit_enabled)throw new ApiError("AUDIT_DISABLED");
       if(request.ContentType==null||!request.ContentType.StartsWith("application/json",StringComparison.OrdinalIgnoreCase))throw new ApiError("CONTENT_TYPE_INVALID");
       var input=Wire.Json.Deserialize<Dictionary<string,object>>(body);
       if(input==null||input.Count!=3||!input.ContainsKey("app_id")||!input.ContainsKey("code")||!input.ContainsKey("oa_run_id")||!(input["app_id"] is int)||(int)input["app_id"]!=settings.oa_app_id||!(input["code"] is string))throw new ApiError("AUDIT_REQUEST_INVALID");
       data=Dispatch(new WorkerRequest{Operation="audit",Code=Wire.Code((string)input["code"]),Run=Wire.Positive(Convert.ToString(input["oa_run_id"],CultureInfo.InvariantCulture))});
      }else throw new ApiError("ROUTE_NOT_FOUND");
     }catch(Exception e){var known=e as ApiError;error=known==null?"ISOLATED_HOST_FAILED":known.Code;status=error=="AUTH_FAILED"||error=="SOURCE_IP_DENIED"?403:400;Console.Error.WriteLine("REQUEST_FAILED code="+error);}
     try{
      string json=Wire.Json.Serialize(Wire.Map("errcode",error,"app_id",settings.oa_app_id,"data",data));byte[] bytes=Encoding.UTF8.GetBytes(json);
      context.Response.Headers["X-OM-Response-Signature"]=auth.ResponseSignature(context.Request.Headers["X-OM-Nonce"]??"",json);
      context.Response.StatusCode=status;context.Response.ContentType="application/json; charset=utf-8";context.Response.ContentLength64=bytes.Length;context.Response.OutputStream.Write(bytes,0,bytes.Length);
     }catch{Console.Error.WriteLine("RESPONSE_SEND_FAILED; audit must not be retried automatically.");}finally{context.Response.Close();}
    }
   }
  }
  static void CheckQuery(HttpListenerRequest request,params string[] keys){foreach(string key in request.QueryString.AllKeys)if(key==null||Array.IndexOf(keys,key)<0||request.QueryString.GetValues(key).Length!=1)throw new ApiError("QUERY_INVALID");}
 }
}
