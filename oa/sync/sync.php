<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ob_start();$started=date(DATE_ATOM);
try {
 $root='D:/oa-server/www/eoffice/server';chdir($root);
 define('EOFFICE_ROOT',$root.'/public');define('LUMEN_START',microtime(true));$app=require $root.'/bootstrap/app.php';$app->boot();
 if(getenv('U8_OM_API_APP_ID')!=='6'||getenv('U8_OM_API_READ_ENABLED')!=='1'||getenv('U8_OM_SYNC_ENABLED')!=='1'||getenv('U8_OM_API_AUDIT_ENABLED')!=='0')throw new RuntimeException('OM_SYNC_ENV_INVALID');
 $hashes=json_decode(file_get_contents(__DIR__.'/source-hashes.json'),true,32,JSON_THROW_ON_ERROR);
 foreach($hashes as $file=>$hash)if(!hash_equals($hash,hash_file('sha256',$root.'/'.$file)))throw new RuntimeException('OM_PRODUCTION_SOURCE_CHANGED');
 if(!\Ext\U8Open\service\U8ScheduleService::hasOutsourceUniqueIndex(\Illuminate\Support\Facades\DB::select('SHOW INDEX FROM u8_openapi_sync_order')))throw new RuntimeException('OM_SYNC_UNIQUE_INDEX_REQUIRED');
 $appRow=\Illuminate\Support\Facades\DB::table('u8_openapi_application')->where('app_id',6)->first(['app_id','enabled_toggle']);
 if(!$appRow||(string)$appRow->enabled_toggle!=='1')throw new RuntimeException('OM_APPLICATION_DISABLED');
 $flow=\Illuminate\Support\Facades\DB::table('flow_type')->where('flow_id',31)->first(['flow_id','form_id','is_using']);
 if(!$flow||(string)$flow->form_id!=='429'||(string)$flow->is_using!=='1')throw new RuntimeException('OM_FLOW_NOT_READY');
 require __DIR__.'/DraftSync.php';
 $service=new \Ext\U8Open\service\OutsourceOrderService(6);
 $orders=$service->getList(['state'=>'开立']);
 if(!is_array($orders)||isset($orders['errcode']))throw new RuntimeException('OM_LIST_FAILED');
 $before=(int)\Illuminate\Support\Facades\DB::table('u8_openapi_sync_order')->where('app_id',6)->where('order_type','outsourceOrder')->where('flow_id',31)->count();
 if($orders){
  $sync=new \ExampleOrg\OmProduction\DraftSync();
  $sync->scheduleOaFlow('outsourceOrder',31,['state'=>'开立','limit'=>100],['app_id'=>6,'codeField'=>'code','detailFilters'=>['operation_type_code'=>'委外加工','state'=>'开立'],'forceCodeFromList'=>true]);
 }
 $after=(int)\Illuminate\Support\Facades\DB::table('u8_openapi_sync_order')->where('app_id',6)->where('order_type','outsourceOrder')->where('flow_id',31)->count();
 ob_end_clean();echo json_encode(['started'=>$started,'finished'=>date(DATE_ATOM),'status'=>'SCAN_COMPLETED','open_orders'=>count($orders),'new_claims'=>$after-$before,'create_submit'=>0,'audit_enabled'=>false],JSON_UNESCAPED_SLASHES),PHP_EOL;
}catch(Throwable $e){ob_end_clean();$message=$e->getMessage();echo json_encode(['started'=>$started,'finished'=>date(DATE_ATOM),'status'=>'FAILED','reason'=>preg_match('/\AOM_[A-Z0-9_]{1,90}\z/D',$message)?$message:'OM_SYNC_RUNTIME_FAILED']),PHP_EOL;exit(1);}
