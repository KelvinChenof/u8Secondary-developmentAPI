# U8 委外订单二开接口

由于 U8 未提供可供 OA 对接的委外订单接口，本项目通过二次开发封装委外订单查询与审核能力，让委外订单能够进入 OA 审批流程，并将审批结果回写 U8。

U8 端使用 C# 调用原生 SDK，提供 HTTP 接口；OA 端使用 PHP 完成订单同步、表单映射和审核外发。OA 适配代码基于 e-office，接入其他 OA 系统时可复用 U8 接口并实现对应的流程适配。

## 业务流程

1. 从 U8 读取开立状态的委外加工订单及明细。
2. 将订单、供应商联系人和金额等字段映射到 OA，创建审批草稿。
3. OA 流程审批后，通过外发回调校验订单与流程身份，并调用 U8 审核接口。
4. 重新读取 U8 单据确认审核结果，并保留操作记录供重复回调和异常复核使用。

## 实现要点

- **原生调用隔离**：HTTP 主进程与 x86 工作进程分离，每次原生请求单独登录、执行并释放资源。
- **接口鉴权**：来源 IP 限制、HMAC 请求及响应验签、时间窗口和 nonce 防重放。
- **审核防重**：按单据状态和持久化领取记录处理重复请求，审核结果未知时保留记录等待复核。
- **流程适配**：包含 OA 草稿同步、表单映射、供应商联系人查询和审核外发代码。

## 运行环境

- Windows、.NET Framework 4.x，以及已安装注册的 32 位 U8 原生组件。
- 对应版本的用友 U8 SDK。
- e-office OA 的 PHP 运行环境及数据库、Redis、OpenAPI 等依赖。

仓库中的地址、主机名、操作员和 OA OpenAPI 凭据均为示例，使用前需按目标环境配置。用友 SDK 二进制、OA 商业框架、数据库和运行凭据不包含在本仓库中。

## 目录

| 目录 | 用途 |
| --- | --- |
| `u8/` | C#、.NET Framework、x86 接口与模拟测试 |
| `u8/bin/` | 运行配置和公开测试向量；SDK 由本机授权环境导入 |
| `oa/server/` | OA 扩展、调度入口和表单映射 |
| `oa/sync/` | 委外草稿同步任务及供应商联系人适配 |
| `oa/auto-audit/` | 审核外发回调及本机密钥读取 |
| `deploy/` | 先检查、再备份替换的 U8 更新脚本 |
| `tests/` | OA 回调校验、任务选择与部署恢复模拟测试 |
| `tools/` | SDK 导入、统一验证、校验清单更新及发布包构建 |
| `docs/` | 示例环境、接口协议、更新说明与源码组成 |

## 拉取和验证

在 Windows PowerShell 中执行：

```powershell
git clone --branch main https://github.com/KelvinChenof/u8Secondary-developmentAPI.git
cd u8Secondary-developmentAPI
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tools\Import-U8Sdk.ps1 -SdkBin '<本机已授权的 U8 SDK 目录>'
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tools\Test-Repository.ps1 -Php 'D:\DevTools\php\php.exe'
```

PHP 路径按本机安装位置调整。编译器使用 Windows 自带的 .NET Framework 4.x x86 编译器。测试使用模拟数据，不登录或审核真实 U8 单据，也不停止生产任务。

后续源码更新：

```powershell
git switch main
git pull --ff-only origin main
```

`git pull` 更新维护目录中的源码。构建、发布包传输及程序替换见 [更新说明](docs/UPDATE.md)。

## 文档

- [接口协议与保护逻辑](docs/API.md)
- [示例环境与路径](docs/ENVIRONMENT.md)
- [构建、部署与更新](docs/UPDATE.md)
- [源码组成与依赖](docs/PROVENANCE.md)
