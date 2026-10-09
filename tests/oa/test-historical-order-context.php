<?php
// 独立执行真实回调身份校验，不启动 OA 框架或调用 U8。
$source=file_get_contents(__DIR__.'/../../oa/auto-audit/AutoAudit.php');
$start='$context=outsourceOrderBuildAuditContext($sync,$form);';
$end='$config=omAutoConfig();$service=';
$at=strpos($source,$start);$until=$at===false?false:strpos($source,$end,$at);
if($at===false||$until===false)throw new RuntimeException('CONTEXT_GUARD_NOT_FOUND');
$guard=eval('return static function(array $context):void {'.substr($source,$at+strlen($start),$until-$at-strlen($start)).'};');
$checks=0;
foreach(['0000000059','0000000060','0000000068'] as $code){
    try{$guard(['app_id'=>6,'voucher_code'=>$code]);}
    catch(Throwable $error){throw new RuntimeException('Expected normal order context for '.$code.', got '.$error->getMessage());}
    $checks++;
}
try{$guard(['app_id'=>7,'voucher_code'=>'0000000060']);throw new RuntimeException('WRONG_APP_ACCEPTED');}
catch(RuntimeException $error){if($error->getMessage()!=='OM_APP_ID_MISMATCH')throw $error;$checks++;}
echo 'OA_CONTEXT_TESTS_OK='.$checks.' LIVE_U8_CALLS=0',PHP_EOL;
