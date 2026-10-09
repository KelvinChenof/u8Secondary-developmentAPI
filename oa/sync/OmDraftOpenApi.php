<?php
namespace ExampleOrg\OmProduction;
class OmDraftOpenApi extends \Ext\U8Open\service\OpenApiService {
 public function initUser($id){
  $cfg=parse_ini_file('D:/oa-server/www/eoffice/server/ext/u8open/config/openApi.ini');
  $app=\Illuminate\Support\Facades\DB::table('open_application')->where('agent_id',$cfg['agent_id'])->where('is_use',1)->whereNull('deleted_at')->first(['user_basis_field']);
  if(!$app||$app->user_basis_field!=='user_accounts')throw new \RuntimeException('OM_LOGIN_BASIS_CHANGED');
  $user=\Illuminate\Support\Facades\DB::table('user_infos')->where('user_id',$id)->where('allow_login',1)->whereNull('deleted_at')->first(['user_accounts']);
  if(!$user||!is_string($user->user_accounts)||$user->user_accounts==='')throw new \RuntimeException('OM_LOGIN_ACCOUNT_MISSING');
  if(\Illuminate\Support\Facades\DB::table('user_infos')->where('user_accounts',$user->user_accounts)->whereNull('deleted_at')->count()!==1)throw new \RuntimeException('OM_LOGIN_ACCOUNT_AMBIGUOUS');
  parent::initUser($user->user_accounts);
 }
 public function buildFlowParam(&$params,$data){
  if((string)($data['flow_id']??'')!=='2001'||($data['create_submit']??null)!==0)throw new \RuntimeException('OM_DRAFT_SCOPE_INVALID');
  parent::buildFlowParam($params,$data);$params['create_submit']=0;
 }
 public function createFlow($data,?callable $callback=null){
  $token=$this->getToken();if(!$token)throw new \RuntimeException('OM_LOGIN_FAILED_BEFORE_CREATE');
  $params=['api_token'=>$token];$this->buildFlowParam($params,$data);
  $cfg=parse_ini_file('D:/oa-server/www/eoffice/server/ext/u8open/config/openApi.ini');
  // 令牌失效或超时后也不重发流程创建请求。
  $res=$this->request('POST',$cfg['url'].'flow/new-page/flow-save',$params);
  if($callback)$callback($res);return $res;
 }
}
