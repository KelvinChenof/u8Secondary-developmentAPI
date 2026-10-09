# U8 委外订单二开接口

统一维护当前 U8 原生接口、OA 委外草稿同步、审核外发、测试和更新脚本。唯一分支为 `main`，注释使用中文。

本仓库为脱敏参考版本，地址、主机名、操作员及 OA OpenAPI 凭据均为示例；不包含用友 SDK 二进制。部署前按目标环境修改配置及身份校验，不能将示例值直接用于生产。

## 目录

| 目录 | 用途 |
| --- | --- |
| `u8/` | C#、.NET Framework、x86 接口与模拟测试 |
| `u8/bin/` | 运行配置和公开测试向量；SDK 由本机授权环境导入 |
| `oa/server/` | 从当前 OA 服务器导出的扩展、调度入口和表单映射 |
| `oa/sync/` | 线上独立委外草稿同步任务及供应商联系人适配 |
| `oa/auto-audit/` | 当前审核外发回调及本机密钥读取 |
| `deploy/` | 先检查、再备份替换的 U8 更新脚本 |
| `tests/` | OA 回调校验、任务选择与部署恢复模拟测试 |
| `tools/` | SDK 导入、统一验证、校验清单更新及发布包构建 |
| `docs/` | 环境位置、协议、更新说明与源码来源 |

## 拉取和验证

在 Windows PowerShell 中执行：

```powershell
git clone --branch main https://github.com/KelvinChenof/u8Secondary-developmentAPI.git
cd u8Secondary-developmentAPI
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tools\Import-U8Sdk.ps1 -SdkBin '<本机已授权的 U8 SDK 目录>'
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tools\Test-Repository.ps1 -Php 'D:\DevTools\php\php.exe'
```

PHP 路径按本机安装位置调整。编译器使用 Windows 自带的 .NET Framework 4.x x86 编译器；线上 PHP 使用 e-office 原有版本。测试不会登录或审核真实 U8 单据，也不会停止生产任务。

后续源码更新：

```powershell
git switch main
git pull --ff-only origin main
```

`git pull` 只更新维护目录。构建、发布包传输及生产替换见 [更新说明](docs/UPDATE.md)，生产加载路径见 [环境位置](docs/ENVIRONMENT.md)。

## 当前行为

OA 应用 6、流程 31 将 U8 开立的委外加工订单创建为 OA 草稿。流程外发按可信同步记录与表单身份校验执行审核。U8 HTTP 主进程与原生工作进程分离，每个工作进程单独登录、执行、释放资源并退出。

已删除固定订单 0000000059、0000000060 的拒绝逻辑；所有订单统一按真实状态、持久化领取及结果未知保护处理。测试中的历史单号仅用于验证普通订单行为。

重复、跨流程或结果未知的回调不会再次审核。U8 只有在原生审核成功且重新加载确认同一 MOID 已审核后，才记录成功。

源码来源与已部署基线见 [来源说明](docs/PROVENANCE.md)。仓库不包含 OA 商业框架、数据库、运行领取记录或 DPAPI 凭据文件；现有服务器继续复用这些运行依赖。
