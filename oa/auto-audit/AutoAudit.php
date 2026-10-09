<?php
function omAutoEnvelope(array $request,array $cfg):int {
 if(($cfg['enabled']??false)!==true)throw new RuntimeException('OM_CALLBACK_DISABLED');
 foreach(['flow_id','run_id','outsend_id'] as $key)if(!isset($request[$key])||(!is_int($request[$key])&&!is_string($request[$key])))throw new RuntimeException('OM_ENVELOPE_INVALID');
 $run=filter_var($request['run_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if((string)$request['flow_id']!=='31'||(string)$request['outsend_id']!==(string)$cfg['outsend_id']||!$run||$run<=(int)$cfg['after_run_id'])throw new RuntimeException('OM_ENVELOPE_INVALID');
 return $run;
}
function omAutoConfig():array {
 $pipes=[];$proc=proc_open(['C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',__DIR__.'/read-key.ps1'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
 if(!is_resource($proc))throw new RuntimeException('OM_KEY_PROCESS_FAILED');
 fclose($pipes[0]);$encoded=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($proc);
 $key=base64_decode($encoded,true);$encoded=null;
 if($exit!==0||$key===false||strlen($key)<32||strlen($key)>256)throw new RuntimeException('OM_KEY_LOAD_FAILED');
 return ['app_id'=>6,'base_url'=>'http://192.0.2.10:18766','hmac_key'=>$key,'read_enabled'=>true,'sync_enabled'=>false,'audit_enabled'=>true];
}
function omAutoRun():void {
 $claimed=false;$claim=null;
 try{
  if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){http_response_code(403);echo 'OM_LOCAL_ONLY';return;}
  $root='D:/oa-server/www/eoffice/server';chdir($root);
  if(!defined('EOFFICE_ROOT'))define('EOFFICE_ROOT',$root.'/public');if(!defined('LUMEN_START'))define('LUMEN_START',microtime(true));
  $app=require $root.'/bootstrap/app.php';$app->boot();
  $hashes=json_decode(file_get_contents(__DIR__.'/hashes.json'),true,32,JSON_THROW_ON_ERROR);
  foreach($hashes as $file=>$hash)if(!hash_equals($hash,hash_file('sha256',$root.'/'.$file)))throw new RuntimeException('OM_SOURCE_CHANGED');
  define('OUTSOURCE_ORDER_OUTSEND_TEST_MODE',true);require $root.'/ext/u8open/outsend/outsourceOrderOutsend.php';
  $cfg=json_decode(file_get_contents(__DIR__.'/activation.json'),true,32,JSON_THROW_ON_ERROR);
  if(($_SERVER['REQUEST_METHOD']??'')==='GET' && ($_GET['health']??'')==='1'){
   $service=new \Ext\U8Open\service\OutsourceOrderService(6,omAutoConfig());$list=$service->getList(['state'=>'开立']);
   if(!is_array($list)||isset($list['errcode']))throw new RuntimeException('OM_HEALTH_READ_FAILED');
   header('Content-Type: application/json');echo json_encode(['status'=>'OM_CALLBACK_READ_READY','enabled'=>$cfg['enabled'],'open_orders'=>count($list),'audit_called'=>false]);return;
  }
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('OM_POST_REQUIRED');
  if((int)($_SERVER['CONTENT_LENGTH']??0)>4194304)throw new RuntimeException('OM_BODY_TOO_LARGE');
  $media=strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]));
  $request=$media==='application/json'?json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR):$_POST;
  if(!is_array($request))throw new RuntimeException('OM_ENVELOPE_INVALID');$run=omAutoEnvelope($request,$cfg);
  $rule=\Illuminate\Support\Facades\DB::table('flow_outsend')->where('id',$cfg['outsend_id'])->where('flow_id',31)->where('enable_outsend',1)->where('trigger_timing',4)->whereNull('deleted_at')->first();
  if(!$rule)throw new RuntimeException('OM_RULE_NOT_ACTIVE');
  $sync=outsourceOrderLoadSyncRecord($run);$form=outsourceOrderLoadFormRecord($run);$context=outsourceOrderBuildAuditContext($sync,$form);
  // 所有单号按 U8 实际状态及持久化领取记录防重，保留应用身份校验。
  if($context['app_id']!==6)throw new RuntimeException('OM_APP_ID_MISMATCH');
  $config=omAutoConfig();$service=new \Ext\U8Open\service\OutsourceOrderService(6,$config);$config=null;
  $claim=__DIR__.'/claims/'.hash('sha256','6:'.$context['voucher_code']).'.json';
  $fh=@fopen($claim,'x');
  if(!$fh){$saved=json_decode(file_get_contents($claim),true);if(($saved['run_id']??null)===$run&&($saved['status']??null)==='audited'){echo '1';return;}throw new RuntimeException('OM_CALLBACK_ALREADY_CLAIMED_REVIEW_REQUIRED');}
  $record=['run_id'=>$run,'code'=>$context['voucher_code'],'status'=>'pending','at'=>date(DATE_ATOM)];
  $encoded=json_encode($record,JSON_THROW_ON_ERROR);if(fwrite($fh,$encoded)!==strlen($encoded)||!fflush($fh)){fclose($fh);throw new RuntimeException('OM_CLAIM_WRITE_FAILED');}if(function_exists('fsync')&&!fsync($fh)){fclose($fh);throw new RuntimeException('OM_CLAIM_FLUSH_FAILED');}fclose($fh);$claimed=true;
  set_time_limit(240);ignore_user_abort(true);
  $result=$service->audit(['voucher_code'=>$context['voucher_code']],['oa_run_id'=>$run]);
  $record['status']=(is_array($result)&&(string)($result['errcode']??'')==='0')?'audited':'review_required';$record['finished']=date(DATE_ATOM);
  $temp=$claim.'.result';file_put_contents($temp,json_encode($record,JSON_THROW_ON_ERROR),LOCK_EX);
  // 单独保存结果文件，回写领取记录失败时仍可复核原尝试。
  if($record['status']!=='audited')throw new RuntimeException('OM_AUDIT_REVIEW_REQUIRED');
  file_put_contents($claim,json_encode($record,JSON_THROW_ON_ERROR),LOCK_EX);echo '1';
 }catch(Throwable $e){http_response_code(400);$code=$e->getMessage();echo preg_match('/\AOM_[A-Z0-9_]+\z/D',$code)?$code:'OM_CALLBACK_FAILED';}
}
if(!defined('OM_AUTO_AUDIT_TEST'))omAutoRun();
