# 示例配置与字段映射

公开代码使用以下示例值，用于展示接口与 OA 流程的关联方式。部署时应同时修改配置、调用方、身份校验和路径，不应只替换单个编号。

| 项目 | 示例值 |
| --- | --- |
| 委外应用编号 | `1001` |
| 委外流程编号 | `2001` |
| 采购、付款申请流程编号 | `2002`、`2003` |
| 委外表单编号及表名 | `3001`、`form_data_3001` |
| 审核外发规则编号 | `4001` |
| OA 外部数据库连接编号 | `5001` |
| U8 示例账套及年度 | `999`、`2026` |
| 示例数据库 | `UFMeta_999`、`UFDATA_999_2026` |
| 接口示例地址 | `http://192.0.2.10:18080` |
| 委外字段映射文件 | `oa/server/ext/u8open/config/flow/2001.ini` |

OA 调度任务和审核回调默认关闭，先配置目标环境、表单映射与凭据，再由管理员启用。年度是示例年度，使用时应与实际 U8 账套一致。

## 控件映射

`DATA_` 后的编号均为示例控件编号。表单表查询、外发身份校验、字段映射、草稿公式输入及相关测试使用同一组编号。

| U8 字段或用途 | 示例 OA 控件 |
| --- | --- |
| `app_id` | `DATA_1032` |
| `code` | `DATA_1012` |
| `date` | `DATA_1019` |
| `operation_type_code` | `DATA_1033` |
| `outsource_flag` | `DATA_1040` |
| `purchase_type_name` | `DATA_1029` |
| `purchase_type_code` | `DATA_1030` |
| `vendorname` | `DATA_1025` |
| `vendorcode` | `DATA_1027` |
| `vendorabbname` | `DATA_1014` |
| `cVenPerson` | `DATA_1015` |
| `cVenPhone` | `DATA_1016` |
| `cVenAddress` | `DATA_1018` |
| `deptname` | `DATA_1024` |
| `deptcode` | `DATA_1028` |
| `personname` | `DATA_1026` |
| `personcode` | `DATA_1031` |
| `maker_raw` | `DATA_1034` |
| `maker_code` | `DATA_1039` |
| `maker` | `DATA_1023` |
| `currency_name` | `DATA_1038` |
| `currency_rate` | `DATA_1022` |
| `cvenpuomprotocolname` | `DATA_1020` |
| `remark` | `DATA_1021` |
| `detailField` | `DATA_1002` |
| `inventorycode` | `DATA_1003` |
| `inventoryname` | `DATA_1004` |
| `inventorystd` | `DATA_1005` |
| `unitname` | `DATA_1007` |
| `quantity` | `DATA_1006` |
| `arrivedate` | `DATA_1009` |
| `price` | `DATA_1035` |
| `taxprice` | `DATA_1010` |
| `money` | `DATA_1036` |
| `tax` | `DATA_1037` |
| `sum` | `DATA_1008` |
| `taxrate` | `DATA_1017` |
| 草稿合同金额合计 | `DATA_1013` |
| 草稿合同名称 | `DATA_1011` |

更换流程或控件编号时，检查 `oa/sync/`、`oa/auto-audit/`、`oa/server/ext/u8open/` 中的引用，并重算 OA 文件校验清单。应用编号还涉及 U8 设置、部署检查和签名测试向量。

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tools\Update-SourceHashes.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tools\Test-Repository.ps1 -Php '<本机 PHP 路径>'
```
