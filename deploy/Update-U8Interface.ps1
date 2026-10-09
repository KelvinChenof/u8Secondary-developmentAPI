# 在 .214 管理员 PowerShell 执行；只替换已验证的接口 EXE，不调用 U8 审核。
param(
 [ValidateSet('Inspect','Apply')][string]$Mode='Inspect',
 [Parameter(Mandatory=$true)][ValidatePattern('^[0-9A-Fa-f]{64}$')][string]$ExpectedCurrentHash,
 [Parameter(Mandatory=$true)][ValidatePattern('^[0-9A-Fa-f]{64}$')][string]$CandidateHash,
 [string]$ProductionRoot='D:\u8-om-production',
 [string]$ExpectedServer='U8-DEMO-SERVER'
)
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
function Confirm-StartupCheck([string]$Path){
 $info=New-Object Diagnostics.ProcessStartInfo
 $info.FileName=$Path;$info.Arguments='--startup-check'
 $info.UseShellExecute=$false;$info.CreateNoWindow=$true
 $info.RedirectStandardOutput=$true;$info.RedirectStandardError=$true
 $process=New-Object Diagnostics.Process;$process.StartInfo=$info
 try{
  if(!$process.Start()){throw 'STARTUP_CHECK_START_FAILED'}
  $null=$process.Handle
  $out=$process.StandardOutput.ReadToEndAsync();$err=$process.StandardError.ReadToEndAsync()
  if(!$process.WaitForExit(15000)){throw 'STARTUP_CHECK_TIMEOUT_INSPECT_PROCESS'}
  $process.WaitForExit();$process.Refresh()
  if($process.ExitCode -ne 0 -or $out.Result.Trim() -cne 'ISOLATED_BUILD_READY; login=not_called' -or $err.Result.Trim().Length -ne 0){throw ('STARTUP_CHECK_FAILED exit='+$process.ExitCode)}
 }finally{$process.Dispose()}
}
function Test-HostHttpReady($Settings){
 # 故意不签名，只确认 HTTP 鉴权入口就绪；不能进入原生登录或审核。
 $request=[Net.HttpWebRequest]::Create($Settings.listen_prefix.TrimEnd('/')+'/api/omorder/operation?app_id='+$Settings.oa_app_id)
 $request.Method='GET';$request.Proxy=$null;$request.AllowAutoRedirect=$false;$request.Timeout=2500
 $response=$null;$reader=$null
 try{
  try{$response=$request.GetResponse()}catch [Net.WebException]{$response=$_.Exception.Response}
  if(!$response -or [int]$response.StatusCode -ne 403 -or $response.Headers['X-OM-Response-Signature'] -notmatch '^[0-9a-fA-F]{64}$'){return $false}
  $reader=New-Object IO.StreamReader($response.GetResponseStream())
  $body=$reader.ReadToEnd();if($body.Length -gt 4096){return $false}
  $data=$body|ConvertFrom-Json
  return $data.app_id -eq $Settings.oa_app_id -and $data.errcode -cin @('AUTH_FAILED','SOURCE_IP_DENIED') -and $null -eq $data.data
 }catch{return $false}finally{if($reader){$reader.Dispose()};if($response){$response.Dispose()}}
}
$root=[IO.Path]::GetFullPath($ProductionRoot).TrimEnd('\')
$exe=Join-Path $root 'bin\U8OmApi.exe'
$manifestPath=Join-Path $root 'manifest.json'
$backup=Join-Path $root ('backups\'+$CandidateHash.ToUpperInvariant())
$candidate=Join-Path $PSScriptRoot 'U8OmApi.exe'
$newHash=$CandidateHash.ToUpperInvariant()
$oldHash=$ExpectedCurrentHash.ToUpperInvariant()
$latch=Join-Path $env:ProgramData 'ExampleOrg\U8OmApi\001-2026\native-worker-pending.json'
$identity=[Security.Principal.WindowsIdentity]::GetCurrent()
if(!(New-Object Security.Principal.WindowsPrincipal($identity)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){throw 'RUN_AS_ADMINISTRATOR'}
if($env:COMPUTERNAME -ine $ExpectedServer){throw 'WRONG_U8_SERVER'}
if((Get-FileHash -LiteralPath $candidate).Hash -cne $newHash){throw 'CANDIDATE_HASH_MISMATCH'}
if((Get-FileHash -LiteralPath $exe).Hash -cne $oldHash){throw 'CURRENT_VERSION_CHANGED_INSPECT_FIRST'}
$settingsPath=Join-Path $root 'settings.json'
$settings=Get-Content -LiteralPath $settingsPath -Raw|ConvertFrom-Json
if($settings.audit_enabled -ne $true -or $settings.oa_app_id -ne 6 -or $settings.listen_prefix -cne 'http://192.0.2.10:18766/'){throw 'PRODUCTION_CONFIG_MISMATCH'}
$settingsHash=(Get-FileHash -LiteralPath $settingsPath).Hash
$manifest=Get-Content -LiteralPath $manifestPath -Raw|ConvertFrom-Json
$exeEntries=@($manifest|Where-Object {$_.path -ceq 'bin/U8OmApi.exe' -or $_.path -ceq 'bin\U8OmApi.exe'})
if($exeEntries.Count -ne 1){throw 'EXE_MANIFEST_ENTRY_INVALID'}
foreach($entry in $manifest){
 if($entry.path -notmatch '^(bin[\\/][A-Za-z0-9._-]+|[A-Za-z0-9._-]+\.(json|ps1))$'){throw 'MANIFEST_PATH_INVALID'}
 if((Get-FileHash -LiteralPath (Join-Path $root $entry.path)).Hash -ine $entry.sha256){throw 'PRODUCTION_MANIFEST_MISMATCH'}
}
foreach($entry in (Get-Content -LiteralPath (Join-Path $PSScriptRoot 'runtime-dependencies.json') -Raw|ConvertFrom-Json)){
 if($entry.path -notmatch '^bin/[A-Za-z0-9._-]+\.(dll|config)$'){throw 'DEPENDENCY_PATH_INVALID'}
 if((Get-FileHash -LiteralPath (Join-Path $root $entry.path)).Hash -ine $entry.sha256){throw 'RUNTIME_DEPENDENCY_CHANGED'}
}
# 从当前加载路径查找唯一启动任务，不猜测或重建任务。
$rootPattern=[regex]::Escape($root)
$rootSlashPattern=[regex]::Escape($root.Replace('\','/'))
$tasks=@(Get-ScheduledTask|Where-Object {
 $taskText=($_.Actions|ForEach-Object {$_.Execute+' '+$_.Arguments+' '+$_.WorkingDirectory}) -join ' '
 ($_.State -eq 'Running') -and ($taskText -match $rootPattern -or $taskText -match $rootSlashPattern)
})
if($tasks.Count -ne 1 -or $tasks[0].State -ne 'Running'){throw 'UNIQUE_RUNNING_PRODUCTION_TASK_REQUIRED'}
$task=$tasks[0]
$hosts=@(Get-WmiObject Win32_Process -Filter "Name='U8OmApi.exe'"|Where-Object {$_.ExecutablePath -ieq $exe -and $_.CommandLine -match '\bserve-unattended\b' -and $_.CommandLine -notmatch '--native-worker'})
if($hosts.Count -ne 1){throw 'UNIQUE_PRODUCTION_HOST_REQUIRED'}
$hostProcess=$hosts[0]
if(Test-Path -LiteralPath $latch){throw 'PENDING_NATIVE_OPERATION_REQUIRES_REVIEW'}
if(@(Get-WmiObject Win32_Process -Filter "Name='U8OmApi.exe'"|Where-Object {$_.CommandLine -match '--native-worker'}).Count){throw 'NATIVE_WORKER_ACTIVE_TRY_MAINTENANCE_WHEN_IDLE'}
$candidateCheck=Confirm-StartupCheck $candidate
$exeStage=Join-Path $root 'bin\U8OmApi.update-staged.exe'
$manifestStage=Join-Path $root 'manifest.update-staged.json'
$exeReplaceBackup=Join-Path $backup 'U8OmApi.replaced-original.exe'
$manifestReplaceBackup=Join-Path $backup 'manifest.replaced-original.json'
$attemptPath=Join-Path $backup 'deployment-attempt.json'
foreach($path in @($manifestStage,$exeReplaceBackup,$manifestReplaceBackup,$attemptPath)){
 if(Test-Path -LiteralPath $path){throw 'LATER_DEPLOYMENT_ATTEMPT_EXISTS_INSPECT_FIRST'}
}
# 只允许恢复“旧版本仍在、原备份一致、新 EXE 暂存完整”的已知失败状态。
$resume=Test-Path -LiteralPath $backup
if($resume){
 if((Get-FileHash -LiteralPath (Join-Path $backup 'U8OmApi.exe')).Hash -cne $oldHash){throw 'ORIGINAL_BACKUP_HASH_MISMATCH'}
 foreach($name in @('manifest.json','settings.json','run-background.ps1')){
  if((Get-FileHash -LiteralPath (Join-Path $backup $name)).Hash -cne (Get-FileHash -LiteralPath (Join-Path $root $name)).Hash){throw 'BACKUP_AND_CURRENT_FILES_DIFFER'}
 }
 if(!(Test-Path -LiteralPath (Join-Path $backup 'startup-task.xml'))){throw 'ORIGINAL_TASK_BACKUP_MISSING'}
 if(!(Test-Path -LiteralPath $exeStage) -or (Get-FileHash -LiteralPath $exeStage).Hash -cne $newHash){throw 'EXPECTED_FAILED_ATTEMPT_STAGE_MISSING_OR_CHANGED'}
}elseif(Test-Path -LiteralPath $exeStage){throw 'UNEXPECTED_EXE_STAGE_WITHOUT_BACKUP'}
if($Mode -eq 'Inspect'){
 [ordered]@{status='DEPLOYMENT_PREFLIGHT_OK';server=$env:COMPUTERNAME;task=$task.TaskName;host_pid=[int]$hostProcess.ProcessId;current_sha256=$oldHash;candidate_sha256=$newHash;verified_prior_replace_failure=[bool]$resume;startup_check='PASS';pending_present=$false;production_modified=$false;login_called=$false;audit_called=$false}|ConvertTo-Json -Compress
 exit 0
}
if(!$resume){
 New-Item -ItemType Directory -Path $backup|Out-Null
 foreach($name in @('manifest.json','settings.json','run-background.ps1','host-launch.json')){
  $path=Join-Path $root $name
  if(Test-Path -LiteralPath $path){Copy-Item -LiteralPath $path -Destination (Join-Path $backup $name)}
 }
 Copy-Item -LiteralPath $exe -Destination (Join-Path $backup 'U8OmApi.exe')
 [IO.File]::WriteAllText((Join-Path $backup 'startup-task.xml'),(Export-ScheduledTask -TaskName $task.TaskName -TaskPath $task.TaskPath),(New-Object Text.UTF8Encoding($true)))
 if((Get-FileHash -LiteralPath (Join-Path $backup 'U8OmApi.exe')).Hash -cne $oldHash){throw 'BACKUP_VERIFICATION_FAILED'}
}
$attemptJson=@{at=(Get-Date).ToString('o');mode='AUTHORIZED_DEPLOYMENT';resumed_verified_replace_failure=[bool]$resume}|ConvertTo-Json -Compress
$attemptBytes=[Text.Encoding]::UTF8.GetBytes($attemptJson)
$attemptStream=[IO.File]::Open($attemptPath,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::Read)
try{$attemptStream.Write($attemptBytes,0,$attemptBytes.Length);$attemptStream.Flush($true)}finally{$attemptStream.Dispose()}
$mutex=New-Object Threading.Mutex($false,'Global\ExampleOrg-U8OmApi-001-2026')
$owned=$false;$taskDisabled=$false;$hostStopped=$false;$exeReplaced=$false;$manifestReplaced=$false
try{
 # 持有与 worker 相同的原生锁，保证替换期间不能进入原生审核。
 try{$owned=$mutex.WaitOne(0)}catch [Threading.AbandonedMutexException]{$owned=$true}
 if(!$owned){throw 'NATIVE_MUTEX_BUSY_NOT_DEPLOYED'}
 if((Test-Path -LiteralPath $latch) -or @(Get-WmiObject Win32_Process -Filter "Name='U8OmApi.exe'"|Where-Object {$_.CommandLine -match '--native-worker'}).Count){throw 'NATIVE_REQUEST_STARTED_NOT_DEPLOYED'}
 Disable-ScheduledTask -TaskName $task.TaskName -TaskPath $task.TaskPath|Out-Null
 $taskDisabled=$true
 Stop-ScheduledTask -TaskName $task.TaskName -TaskPath $task.TaskPath
 $still=Get-WmiObject Win32_Process -Filter ('ProcessId='+[int]$hostProcess.ProcessId)
 if($still){
  if($still.ExecutablePath -ine $hostProcess.ExecutablePath -or $still.CreationDate -cne $hostProcess.CreationDate -or $still.CommandLine -match '--native-worker'){throw 'HOST_IDENTITY_CHANGED'}
  Stop-Process -Id ([int]$hostProcess.ProcessId) -Force
 }
 $hostStopped=$true
 if((Test-Path -LiteralPath $latch) -or @(Get-WmiObject Win32_Process -Filter "Name='U8OmApi.exe'").Count){throw 'REQUEST_OR_PROCESS_REMAINS_NO_REPLACE'}
 if((Get-FileHash -LiteralPath $exe).Hash -cne $oldHash -or (Get-FileHash -LiteralPath $settingsPath).Hash -cne $settingsHash){throw 'PRODUCTION_CHANGED_BEFORE_REPLACE'}
 if(!(Test-Path -LiteralPath $exeStage)){Copy-Item -LiteralPath $candidate -Destination $exeStage}
 if((Get-FileHash -LiteralPath $exeStage).Hash -cne $newHash){throw 'STAGED_EXE_HASH_MISMATCH'}
 [IO.File]::Replace($exeStage,$exe,$exeReplaceBackup);$exeReplaced=$true
 if((Get-FileHash -LiteralPath $exeReplaceBackup).Hash -cne $oldHash){throw 'REPLACED_ORIGINAL_HASH_MISMATCH'}
 $exeEntries[0].sha256=$newHash
 if(Test-Path -LiteralPath $manifestStage){throw 'MANIFEST_STAGE_EXISTS_INSPECT_FIRST'}
 [IO.File]::WriteAllText($manifestStage,($manifest|ConvertTo-Json -Depth 8),(New-Object Text.UTF8Encoding($false)))
 [IO.File]::Replace($manifestStage,$manifestPath,$manifestReplaceBackup);$manifestReplaced=$true
 foreach($entry in (Get-Content -LiteralPath $manifestPath -Raw|ConvertFrom-Json)){
  if((Get-FileHash -LiteralPath (Join-Path $root $entry.path)).Hash -ine $entry.sha256){throw 'NEW_MANIFEST_VERIFICATION_FAILED'}
 }
 Confirm-StartupCheck $exe
 $mutex.ReleaseMutex();$owned=$false
 Enable-ScheduledTask -TaskName $task.TaskName -TaskPath $task.TaskPath|Out-Null;$taskDisabled=$false
 Start-ScheduledTask -TaskName $task.TaskName -TaskPath $task.TaskPath
 $deadline=(Get-Date).AddSeconds(45);$ready=$false
 do{
  Start-Sleep -Seconds 1
  # 按当前进程身份和实际 HTTP 响应判断，不依赖可能过期的 host-launch.json。
  $newHosts=@(Get-WmiObject Win32_Process -Filter "Name='U8OmApi.exe'"|Where-Object {$_.ExecutablePath -ieq $exe -and $_.CreationDate -cne $hostProcess.CreationDate -and $_.CommandLine -match '\bserve-unattended\b' -and $_.CommandLine -notmatch '--native-worker'})
  if($newHosts.Count -eq 1){
   $newHost=$newHosts[0]
   $ready=(Get-ScheduledTask -TaskName $task.TaskName -TaskPath $task.TaskPath).State -eq 'Running' -and (Test-HostHttpReady $settings)
  }
 }while(!$ready -and (Get-Date) -lt $deadline)
 if(!$ready){throw 'NEW_HOST_NOT_READY_INSPECT_NO_RETRY'}
 if((Get-FileHash -LiteralPath $exe).Hash -cne $newHash -or (Get-FileHash -LiteralPath $settingsPath).Hash -cne $settingsHash){throw 'DEPLOYMENT_READBACK_FAILED'}
 $report=[ordered]@{at=(Get-Date).ToString('o');server=$env:COMPUTERNAME;status='U8_INTERFACE_DEPLOYED';sha256=$newHash;backup_sha256=$oldHash;task=$task.TaskName;host_pid=[int]$newHost.ProcessId;settings_unchanged=$true;claims_modified=$false;login_called=$false;audit_called=$false}
 $json=$report|ConvertTo-Json -Compress
 [IO.File]::WriteAllText((Join-Path $root 'last-deployment.json'),$json,(New-Object Text.UTF8Encoding($false)))
 $json
}catch{
 # 新主机若已启动，不强杀或自动回滚，以免中断真实原生任务。
 if($hostStopped -and !(Get-WmiObject Win32_Process -Filter "Name='U8OmApi.exe'")){
  if($exeReplaced){Copy-Item -LiteralPath (Join-Path $backup 'U8OmApi.exe') -Destination $exe -Force}
  if($manifestReplaced){Copy-Item -LiteralPath (Join-Path $backup 'manifest.json') -Destination $manifestPath -Force}
  if($owned){$mutex.ReleaseMutex();$owned=$false}
  if($taskDisabled){Enable-ScheduledTask -TaskName $task.TaskName -TaskPath $task.TaskPath|Out-Null;$taskDisabled=$false}
  Start-ScheduledTask -TaskName $task.TaskName -TaskPath $task.TaskPath
  Write-Warning 'OLD_HOST_RESTART_REQUESTED; KEEP_ALL_PENDING_RECORDS; INSPECT_BEFORE_RETRY'
 }elseif($taskDisabled){Enable-ScheduledTask -TaskName $task.TaskName -TaskPath $task.TaskPath|Out-Null;$taskDisabled=$false}
 throw
}finally{if($owned){$mutex.ReleaseMutex()};$mutex.Dispose()}
