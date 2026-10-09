<?php
define('OM_AUTO_AUDIT_TEST',true);
require __DIR__.'/../../oa/auto-audit/AutoAudit.php';
$ok=['flow_id'=>2001,'run_id'=>9002,'outsend_id'=>4001];$cfg=['enabled'=>true,'outsend_id'=>4001,'after_run_id'=>9001];
if(omAutoEnvelope($ok,$cfg)!==9002)throw new Exception('valid failed');
foreach([['flow_id'=>2009],['run_id'=>9001],['run_id'=>[]],['outsend_id'=>4002]] as $change){$failed=false;try{omAutoEnvelope(array_replace($ok,$change),$cfg);}catch(Throwable $e){$failed=true;}if(!$failed)throw new Exception('bad accepted');}
$failed=false;try{omAutoEnvelope($ok,array_replace($cfg,['enabled'=>false]));}catch(Throwable $e){$failed=true;}if(!$failed)throw new Exception('disabled accepted');
echo "AUTO_ENVELOPE_TESTS_OK=6\n";
