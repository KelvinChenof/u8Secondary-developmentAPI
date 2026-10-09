<?php
namespace ExampleOrg\OmProduction;

/** 只读查询 U8 供应商联系人，仅限应用 6、账套 001。 */
final class SupplierContact
{
 private $connection;
 public function lookup(array $order)
 {
  if((string)($order['app_id'] ?? '')!=='6')throw new \RuntimeException('OM_CONTACT_APP_SCOPE');
  $vendor=trim((string)($order['vendorcode'] ?? ''));
  $code=trim((string)($order['code'] ?? ''));
  if($vendor===''||$code==='')throw new \RuntimeException('OM_CONTACT_IDENTITY_MISSING');
  if(!$this->connection){
   $config=\Illuminate\Support\Facades\DB::table('external_database')->where('database_id',2)->whereNull('deleted_at')->first(['driver','db_database']);
   if(!$config||$config->driver!=='sqlsrv'||$config->db_database!=='UFMeta_001')throw new \RuntimeException('OM_CONTACT_DATABASE_SCOPE');
   $this->connection=app(\App\EofficeApp\System\ExternalDatabase\Services\ExternalDatabaseService::class)->getExternalDatabasesConnect(2);
   if(!$this->connection)throw new \RuntimeException('OM_CONTACT_DATABASE_UNAVAILABLE');
  }
  try{
   $identity=$this->connection->select('SELECT TOP 2 cCode,cVenCode,dDate FROM UFDATA_001_2020.dbo.OM_MOMain WHERE cCode = ? AND cVenCode = ?',[$code,$vendor]);
   if(count($identity)!==1||substr((string)$identity[0]->dDate,0,10)!==substr((string)($order['date'] ?? ''),0,10))throw new \RuntimeException('OM_CONTACT_ORDER_MISMATCH');
   $rows=$this->connection->select('SELECT TOP 101 cContactCode,cVenCode,cContactName,cMobilePhone,bMajor FROM UFDATA_001_2020.dbo.Ven_Contact WHERE cVenCode = ?',[$vendor]);
  }catch(\Throwable $e){
   if($e instanceof \RuntimeException && $e->getMessage()==='OM_CONTACT_ORDER_MISMATCH')throw $e;
   throw new \RuntimeException('OM_CONTACT_READ_FAILED');
  }
  return self::choose($rows,$vendor,trim((string)($order['cVenPerson'] ?? '')));
 }
 public static function choose(array $rows,$vendor,$name)
 {
  if(count($rows)>100)throw new \RuntimeException('OM_CONTACT_TOO_MANY');
  $candidates=[];
  foreach($rows as $row){$r=(array)$row;if(trim((string)$r['cVenCode'])!==$vendor)throw new \RuntimeException('OM_CONTACT_VENDOR_MISMATCH');$candidates[]=$r;}
  if($name!==''){
   $candidates=array_values(array_filter($candidates,function($r)use($name){return trim((string)$r['cContactName'])===$name;}));
  }else{
   $major=array_values(array_filter($candidates,function($r){return (string)$r['bMajor']==='1';}));
   if($major)$candidates=$major;
  }
  if(count($candidates)!==1)throw new \RuntimeException(count($candidates)?'OM_CONTACT_AMBIGUOUS':'OM_CONTACT_NOT_FOUND');
  $selected=$candidates[0];$mobile=trim((string)$selected['cMobilePhone']);
  if($mobile==='')throw new \RuntimeException('OM_CONTACT_MOBILE_EMPTY');
  if(trim((string)$selected['cContactName'])==='')throw new \RuntimeException('OM_CONTACT_NAME_EMPTY');
  return ['name'=>trim((string)$selected['cContactName']),'mobile'=>$mobile];
 }
}
