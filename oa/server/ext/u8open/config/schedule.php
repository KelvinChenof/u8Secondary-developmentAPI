<?php

/**
 * U8 OpenAPI 定时同步配置
 *
 * times: 每日触发时间
 * jobs:  同步任务列表（多应用由 runAllApps 查询应用表后依次执行）
 */
return [
    // 每日触发时间
    'times' => [
        '09:00',
		'11:00',
		'14:00',
		'14:15',
		'16:00',
		'17:00'
    ],

    // 同步任务
    'jobs' => [
        [
            'type' => 'purchaseOrder',
            'flow_id' => 1,
            'enabled' => true,
            'param' => [
                'state' => '开立',
                'limit' => 100,
            ],
            'extra' => [
                'codeField' => 'code',
                'excludeDetailFilters' => [
                    'operation_type_code' => '委外加工',
                ],
            ],
            // 为空表示所有启用应用都执行；指定则只跑这些 app_id
            'app_ids' => [],
        ],
        [
            'type' => 'outsourceOrder',
            'flow_id' => 31,
            'enabled' => false,
            'param' => [
                'state' => '开立',
                'limit' => 100,
            ],
            'extra' => [
                'codeField' => 'code',
                'detailFilters' => [
                    'operation_type_code' => '委外加工',
                    'state' => '开立',
                ],
                'forceCodeFromList' => true,
            ],
            'app_ids' => [],
        ],
        [
            'type' => 'payrequest',
            'flow_id' => 10,  // 付款申请流程 ID
            'enabled' => true,
            'param' => [
                'iverifystate' => 1,
                'limit' => 100,
            ],
            'extra' => [
                'codeField' => 'cvouchid',
            ],
            'app_ids' => [],
        ],
    ],
];
