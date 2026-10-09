# 示例环境位置

以下地址使用文档专用网段，路径和身份为示例；不代表实际生产环境。

| 组件 | 服务器或位置 |
| --- | --- |
| U8 HTTP 接口 | `192.0.2.10:18080` |
| U8 原生登录目标 | `192.0.2.11`，账套 `999`，年度 `2026`，子系统 `AS` |
| U8 登录操作员 | `1示例操作员`；身份校验也接受 U8 返回的操作员编号 `1` |
| U8 生产加载目录 | `D:\u8-om-production` |
| U8 接口程序 | `D:\u8-om-production\bin\U8OmApi.exe` |
| U8 启动任务 | `ExampleOrg-U8OM-Isolated-Production` |
| U8 服务器主机名 | `U8-DEMO-SERVER` |
| U8 原生保护记录 | `C:\ProgramData\ExampleOrg\U8OmApi\999-2026` |
| OA 服务器 | `198.51.100.10` |
| OA 框架根目录 | `D:\oa-server\www\eoffice\server` |
| OA 扩展目录 | OA 框架下的 `ext\u8open` |
| OA PHP | `D:\oa-server\php\php.exe` |
| OA 草稿同步 | `D:\u8-om-production-sync` |
| OA 自动审核 | `D:\u8-om-auto-audit` |

仓库目录和生产目录有意分开：后续可在 `D:\u8-outsourced-order-interface` 拉取源码，生产任务继续从既有目录加载。

`u8/U8Runtime.cs` 的登录目标与 `u8/Core.cs` 的身份检查必须同时维护。当前固定账套、年度、操作员是环境身份限制，不是固定订单号限制。

U8 `settings.json`、`credentials.dpapi`、任务 XML、OA `.env`、数据库及双方领取记录留在各自服务器，更新不覆盖。OA `hmac.dpapi` 使用本机 DPAPI，不能直接跨机器迁移。

现有 OA 框架需提供数据库、Redis、Guzzle、OpenAPI、流程 2001/表单 3001、应用 1001 及对应外发规则。`schedule.php` 包含其他业务任务，不将本仓库视为完整 e-office 安装包。
