# HTTP 协议与保护逻辑

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| GET | `/api/omorder/list?app_id=6&after=0` | 分页读取委外开立单据 |
| GET | `/api/omorder/detail?app_id=6&id=<单号>` | 读取指定订单；单号保留前导零 |
| GET | `/api/omorder/operation?app_id=6&id=<单号>&oa_run_id=<运行号>` | 查询原审核操作的持久化记录，不登录 U8 |
| POST | `/api/omorder/audit` | 审核；正文包含 `app_id`、`code`、`oa_run_id` |

请求须通过来源 IP 限制及 HMAC-SHA256 验签。请求头为 `X-OM-Time`、`X-OM-Nonce`、`X-OM-Signature`；时间为 Unix 秒，时间误差最多五分钟，同一 nonce 不允许重放。

签名输入为以下字段以 LF 拼接，最后一项为原始正文的 SHA256 小写十六进制：

```text
om-v1
HTTP_METHOD
原始路径及查询字符串
时间
nonce
正文SHA256
```

响应头 `X-OM-Response-Signature` 使用 `om-response-v1\n<请求 nonce>\n<原始响应正文 SHA256>` 的 HMAC。OA 验签成功后才解释响应。公开测试向量在 `u8/bin/test-signature-vector.json`，其中密钥仅用于测试。

只读列表和详情仍需启动原生工作进程并登录 U8。操作结果查询只访问本地日志。审核请求超时可能已写入 U8，因此 OA 仅查询原操作，不再发送 POST。

`NATIVE_WORKER_RECONCILIATION_REQUIRED` 表示遗留原生任务需要复核；`AUDIT_RESULT_UNKNOWN` 等未知状态不能直接重试。先核对订单、OA 运行号、审核日志和保护标记，再处理对应记录。
