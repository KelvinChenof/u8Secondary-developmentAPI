<?php
// 使用示例映射执行实际外发身份校验，不启动 OA 框架或访问数据库。
define('OUTSOURCE_ORDER_OUTSEND_TEST_MODE', true);
require __DIR__.'/../../oa/server/ext/u8open/outsend/outsourceOrderOutsend.php';

$mapping = parse_ini_file(__DIR__.'/../../oa/server/ext/u8open/config/flow/2001.ini', true);
$main = $mapping['u8MainField'];
$sync = ['app_id' => 1001, 'order_no' => '0000009001'];
$form = [
    'flow_id' => 2001,
    $main['app_id'] => 1001,
    $main['code'] => '0000009001',
    $main['outsource_flag'] => '委外',
    $main['operation_type_code'] => '委外加工',
];
$context = outsourceOrderBuildAuditContext($sync, $form);
if ($context !== ['app_id' => 1001, 'voucher_code' => '0000009001']) {
    throw new RuntimeException('EXAMPLE_MAPPING_CONTEXT_INVALID');
}
if (outsourceOrderValidateEnvelope(['flow_id' => 2001, 'run_id' => 9002]) !== 9002) {
    throw new RuntimeException('EXAMPLE_FLOW_ENVELOPE_INVALID');
}
// 错误流程、单号、应用或业务类型仍应被拒绝，不能因示例化放宽保护。
foreach ([
    ['flow_id' => 2009],
    [$main['code'] => '0000009002'],
    [$main['app_id'] => 1002],
    [$main['outsource_flag'] => '普通', $main['operation_type_code'] => '普通采购'],
] as $change) {
    $rejected = false;
    try {
        outsourceOrderBuildAuditContext($sync, array_replace($form, $change));
    } catch (InvalidArgumentException $error) {
        $rejected = true;
    }
    if (!$rejected) {
        throw new RuntimeException('EXAMPLE_INVALID_CONTEXT_ACCEPTED');
    }
}
echo 'EXAMPLE_MAPPING_TESTS_OK=6 LIVE_U8_CALLS=0', PHP_EOL;
