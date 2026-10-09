<?php
namespace ExampleOrg\OmProduction;
require_once __DIR__.'/OmDraftOpenApi.php';
require_once __DIR__.'/SupplierContact.php';

/** 此适配器仅用于应用 6、流程 31 的委外调度。 */
final class DraftSync extends \Ext\U8Open\service\U8ScheduleService
{
 private $supplierContact;
 protected function createOpenApiService(){return new OmDraftOpenApi();}
 public function buildFormData($type,$orderDetail,$flowId,$flowConfig)
 {
  if($type!=='outsourceOrder'||$flowId!==31)throw new \RuntimeException('OM_DRAFT_SCOPE_INVALID');
  if(($flowConfig['u8MainField']['cVenPhone'] ?? '')!=='DATA_175'||($flowConfig['u8MainField']['cVenPerson'] ?? '')!=='DATA_174')throw new \RuntimeException('OM_CONTACT_MAPPING_CHANGED');
  if(!$this->supplierContact)$this->supplierContact=new SupplierContact();
  $contact=$this->supplierContact->lookup($orderDetail);
  $orderDetail['cVenPhone']=$contact['mobile'];
  $orderDetail['cVenPerson']=$contact['name'];
  $payload=parent::buildFormData($type,$orderDetail,$flowId,$flowConfig);
  self::initializeContractName($payload['form_data']);
  $payload['create_submit']=0;
  return $payload;
 }
 /** 首次渲染草稿前填入公式依赖的基础字段。 */
 public static function initializeContractName(array &$form)
 {
  $total=0.0;
  foreach($form['DATA_152'] ?? [] as $entry){
   $value=$entry['DATA_158'] ?? null;
   if(!is_numeric($value))throw new \RuntimeException('OM_CONTRACT_AMOUNT_INVALID');
   $total+=(float)$value;
  }
  if(!is_finite($total))throw new \RuntimeException('OM_CONTRACT_AMOUNT_INVALID');
  $amount=rtrim(rtrim(number_format($total,8,'.',''),'0'),'.');
  if($amount==='-0')$amount='0';
  $form['DATA_163']=$amount;
  $form['DATA_161']=($form['DATA_229'] ?? '').','.$amount.','.($form['DATA_193'] ?? '');
 }
}
