# 拉取、构建与更新

## U8 接口

1. 在维护目录执行 `git pull --ff-only origin main` 并检查变更。
2. 在 U8 服务器管理员 PowerShell 只读查询当前版本：

   ```powershell
   Get-FileHash -LiteralPath 'D:\u8-om-production\bin\U8OmApi.exe' -Algorithm SHA256
   ```

3. 在有对应 U8 SDK 的 Windows 构建机生成发布包，传入刚查询的完整哈希：

   ```powershell
   powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tools\New-U8Release.ps1 -ExpectedCurrentHash '<当前 EXE 的 SHA256>'
   ```

   该脚本运行 U8 模拟测试、编译 x86 程序和启动自检，不登录 U8。生成目录为 `release/<候选 EXE 哈希>`，文件名不加日期。发布包记录源码提交及工作区是否有未提交修改，交付前应使用干净且已验证的 `main`。

4. 将整个发布包复制到目标 U8 接口服务器的维护目录，在管理员 PowerShell 进入发布包目录，执行包内 `README.txt` 的 `-Mode Inspect` 命令。按目标环境显式传入 `-ProductionRoot` 和 `-ExpectedServer`，并调整环境配置及源码身份限制。
5. 检查通过并进入维护窗口后，将同一命令改为 `-Mode Apply`。

更新脚本核对预期旧版本、候选版本、依赖、配置清单、唯一运行任务及进程身份。存在原生工作进程或保护标记时拒绝替换。备份保存在生产目录 `backups/<候选 EXE 哈希>`，只替换 EXE 和相应 manifest 条目，复用现有 SDK、配置、凭据和启动任务。

准备检查 `--startup-check` 不调用 U8；启动后的 HTTP 探测故意不签名，仅确认鉴权入口响应。就绪判定使用当前进程及 HTTP 响应，不依赖可能过期的 `host-launch.json`。

更新失败时先读日志和实际进程状态。没有任何 U8OmApi 进程时，脚本尝试恢复旧文件并启动原任务；新主机已运行时不自动强杀或回滚。不要重复执行 Apply，也不要删除领取记录或保护标记来恢复。先核对实际订单状态及本次备份中的 `deployment-attempt.json`。

程序升级后，需要验证原生登录时先安排签名 GET；真实审核在业务流程授权下触发。

## OA 文件

OA 代码依赖原有 e-office 安装。仓库中的源码与生产目标对应如下：

| 仓库路径 | 生产目标 |
| --- | --- |
| `oa/server/ext/u8open/` | `D:\oa-server\www\eoffice\server\ext\u8open\` |
| `oa/server/app/Console/Schedules/Third/U8OpenSchedule.php` | OA 根目录同一路径 |
| `oa/sync/` | `D:\u8-om-production-sync\` |
| `oa/auto-audit/` | `D:\u8-om-auto-audit\` |

1. 修改源码后执行 `tools/Update-SourceHashes.ps1`，将校验清单与源码一并提交；随后执行统一验证。
2. 进入维护窗口，暂停实际委外同步任务及相关审核外发入口，确认无执行中的请求。
3. 备份本次涉及的源码及两个校验清单，核对现有 `openApi.ini`、`activation.json`、外发规则、唯一索引和环境变量。只复制本次审阅的文件，不整目录覆盖 `claims`、`hmac.dpapi`、日志或其它流程配置。
4. 源码和依赖它们的 `hashes.json` / `source-hashes.json` 作为同一批次替换；逐项核对 SHA256 后恢复入口。
5. 首先验证签名 GET 读取和草稿同步；真实审核按业务流程执行。回滚时源码与两个校验清单一起恢复，保留所有运行记录。

源码、注释或换行变化都会影响文件校验值，发布前应重算关联清单。Git 属性固定 LF，Windows PowerShell 脚本带 UTF-8 BOM，兼容旧服务器中文注释解析。

公开副本的配置均为示例，部署前需填写目标地址、操作员和 OA OpenAPI 配置，并导入有授权的 SDK：

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tools\Import-U8Sdk.ps1 -SdkBin '<本机已授权的 U8 运行库目录>'
```

U8 COM 组件须在目标服务器正确安装注册。复制 DLL 仅满足编译及运行库引用，不能代替 U8 客户端安装。
