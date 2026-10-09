<?php

// 接口凭据从 OA 运行环境读取，不接受表单传入。
return [
    'app_id' => (int) (getenv('U8_OM_API_APP_ID') ?: 0),
    'base_url' => (string) (getenv('U8_OM_API_URL') ?: ''),
    'hmac_key' => (string) (getenv('U8_OM_API_HMAC_KEY') ?: ''),
    'read_enabled' => getenv('U8_OM_API_READ_ENABLED') === '1',
    // 字段预览只读，不自动启用流程创建。
    'sync_enabled' => getenv('U8_OM_SYNC_ENABLED') === '1',
    'audit_enabled' => getenv('U8_OM_API_AUDIT_ENABLED') === '1',
];
