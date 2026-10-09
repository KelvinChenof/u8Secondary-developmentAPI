<?php
define('OM_AUTO_AUDIT_TEST',true);
require __DIR__.'/../../oa/auto-audit/AutoAudit.php';
$ok=['flow_id'=>31,'run_id'=>767,'outsend_id'=>50];$cfg=['enabled'=>true,'outsend_id'=>50,'after_run_id'=>766];
if(omAutoEnvelope($ok,$cfg)!==767)throw new Exception('valid failed');
foreach([['flow_id'=>30],['run_id'=>766],['run_id'=>[]],['outsend_id'=>51]] as $change){$failed=false;try{omAutoEnvelope(array_replace($ok,$change),$cfg);}catch(Throwable $e){$failed=true;}if(!$failed)throw new Exception('bad accepted');}
$failed=false;try{omAutoEnvelope($ok,array_replace($cfg,['enabled'=>false]));}catch(Throwable $e){$failed=true;}if(!$failed)throw new Exception('disabled accepted');
echo "AUTO_ENVELOPE_TESTS_OK=6\n";
