# 在本机 Windows PowerShell 执行；服务及进程操作全部使用模拟对象。
$ErrorActionPreference='Stop'
$sourcePath=Join-Path $PSScriptRoot '../../deploy/Update-U8Interface.ps1'
$source=[IO.File]::ReadAllText($sourcePath,[Text.Encoding]::UTF8)
$tokens=$null;$errors=$null
$ast=[Management.Automation.Language.Parser]::ParseInput($source,[ref]$tokens,[ref]$errors)
if($errors.Count){throw 'MAINTAINED_SCRIPT_PARSE_FAILED'}
$probeDefinition=$ast.Find({param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Test-HostHttpReady'},$true).Extent.Text
if(!$probeDefinition){throw 'READINESS_PROBE_FUNCTION_MISSING'}
$fixtureBase=Join-Path (Join-Path $PSScriptRoot 'deployment-fixtures') ([guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $fixtureBase -Force|Out-Null
$originalHostName=$env:COMPUTERNAME
$env:COMPUTERNAME='U8-DEMO-SERVER'
# 两个测试程序只响应启动自检，不含 U8 SDK 调用。
$fixtureOld=Join-Path $fixtureBase 'old.exe'
$fixtureNew=Join-Path $fixtureBase 'new.exe'
foreach($pair in @(@('OldFixture',$fixtureOld),@('NewFixture',$fixtureNew))){
 $cs=Join-Path $fixtureBase ($pair[0]+'.cs')
 [IO.File]::WriteAllText($cs,('[assembly:System.Reflection.AssemblyTitle("'+$pair[0]+'")] class Fixture { static void Main(){System.Console.WriteLine("ISOLATED_BUILD_READY; login=not_called");}}'))
 & 'C:\Windows\Microsoft.NET\Framework\v4.0.30319\csc.exe' /nologo ('/out:'+$pair[1]) $cs
 if($LASTEXITCODE){throw 'FIXTURE_BUILD_FAILED'}
}
$oldHash=(Get-FileHash -LiteralPath $fixtureOld).Hash
$newHash=(Get-FileHash -LiteralPath $fixtureNew).Hash
function Get-ScheduledTask{param($TaskName,$TaskPath) if($TaskName){$script:fixtureTasks|Where-Object {$_.TaskName -eq $TaskName}}else{$script:fixtureTasks}}
function Get-WmiObject{param($Class,$Filter) if($Filter -match '^ProcessId=(\d+)$'){$script:fixtureHosts|Where-Object {$_.ProcessId -eq [int]$matches[1]}}else{$script:fixtureHosts}}
function Disable-ScheduledTask{param($TaskName,$TaskPath) $script:taskActions++;$script:productionTask.State='Disabled'}
function Enable-ScheduledTask{param($TaskName,$TaskPath) $script:taskActions++;$script:productionTask.State='Ready'}
function Stop-ScheduledTask{param($TaskName,$TaskPath) $script:taskActions++;$script:fixtureHosts=@()}
function Stop-Process{param($Id,[switch]$Force) throw 'UNEXPECTED_FIXTURE_STOP_PROCESS'}
function Export-ScheduledTask{param($TaskName,$TaskPath) '<Task><FixtureOnly>true</FixtureOnly></Task>'}
function Start-ScheduledTask{
 param($TaskName,$TaskPath)
 $script:taskActions++;$script:startCalls++
 if($script:injectStartFailure -and $script:startCalls -eq 1){throw 'START_FAILURE_INJECTED'}
 $script:productionTask.State='Running'
 $script:fixtureHosts=@([pscustomobject]@{ProcessId=2000;ExecutablePath=(Join-Path $script:fixtureRoot 'bin\U8OmApi.exe');CreationDate='NEW';CommandLine='U8OmApi.exe serve-unattended'})
 $log=Join-Path $script:fixtureRoot 'fixture-host.out.log'
 [IO.File]::WriteAllText($log,'HTTP_READY app_id=1001 audit_enabled=True native_mode=isolated')
 # 保留过期启动记录，确认新主机就绪判定不依赖该记录。
}
try{
 foreach($scenario in @('fresh-apply','resume-apply','bad-backup','rollback','fresh-inspect','resume-inspect')){
  $script:fixtureRoot=Join-Path (Join-Path $fixtureBase $scenario) 'production with spaces'
  $package=Join-Path (Split-Path $script:fixtureRoot -Parent) 'package with spaces'
  New-Item -ItemType Directory -Path (Join-Path $script:fixtureRoot 'bin'),$package -Force|Out-Null
  Copy-Item -LiteralPath $fixtureOld -Destination (Join-Path $script:fixtureRoot 'bin\U8OmApi.exe')
  Copy-Item -LiteralPath $fixtureNew -Destination (Join-Path $package 'U8OmApi.exe')
  [IO.File]::WriteAllText((Join-Path $script:fixtureRoot 'settings.json'),'{"audit_enabled":true,"oa_app_id":1001,"listen_prefix":"http://192.0.2.10:18080/"}')
  [IO.File]::WriteAllText((Join-Path $script:fixtureRoot 'run-background.ps1'),'# 仅用于模拟测试')
  [IO.File]::WriteAllText((Join-Path $script:fixtureRoot 'bin\fixture.dll'),'FAKE_DEPENDENCY_NOT_LOADED')
  [IO.File]::WriteAllText((Join-Path $script:fixtureRoot 'preserved-claim.json'),'DO_NOT_MODIFY_OR_RELEASE')
  [IO.File]::WriteAllText((Join-Path $script:fixtureRoot 'host-launch.json'),'{"pid":7001,"stdout":"stale-host.out.log","started":"2026-01-01T00:00:00"}')
  $entries=@(foreach($path in @('bin/U8OmApi.exe','settings.json','run-background.ps1','bin/fixture.dll')){@{path=$path;sha256=(Get-FileHash -LiteralPath (Join-Path $script:fixtureRoot $path)).Hash}})
  [IO.File]::WriteAllText((Join-Path $script:fixtureRoot 'manifest.json'),($entries|ConvertTo-Json))
  [IO.File]::WriteAllText((Join-Path $package 'runtime-dependencies.json'),(ConvertTo-Json -InputObject @($entries|Where-Object {$_.path -eq 'bin/fixture.dll'})))
  $manifestBefore=(Get-FileHash -LiteralPath (Join-Path $script:fixtureRoot 'manifest.json')).Hash
  $settingsBefore=(Get-FileHash -LiteralPath (Join-Path $script:fixtureRoot 'settings.json')).Hash
  $script:productionTask=[pscustomobject]@{TaskName='ExampleOrg-U8OM-Isolated-Production';TaskPath='\';State='Running';Actions=@([pscustomobject]@{Execute='powershell.exe';Arguments='-File run-background.ps1';WorkingDirectory=$script:fixtureRoot})}
  $script:fixtureTasks=@($script:productionTask,[pscustomobject]@{TaskName='Diagnostic';State='Disabled';Actions=$script:productionTask.Actions},[pscustomobject]@{TaskName='Startup';State='Ready';Actions=$script:productionTask.Actions})
  $script:fixtureHosts=@([pscustomobject]@{ProcessId=1000;ExecutablePath=(Join-Path $script:fixtureRoot 'bin\U8OmApi.exe');CreationDate='OLD';CommandLine='U8OmApi.exe serve-unattended'})
  $script:taskActions=0;$script:startCalls=0;$script:probeCalls=0;$script:injectStartFailure=($scenario -eq 'rollback')
  $priorFailure=$scenario -in @('resume-apply','resume-inspect','bad-backup')
  if($priorFailure){
   $back=Join-Path $script:fixtureRoot ('backups\'+$newHash);New-Item -ItemType Directory -Path $back|Out-Null
   Copy-Item -LiteralPath (Join-Path $script:fixtureRoot 'bin\U8OmApi.exe') -Destination (Join-Path $back 'U8OmApi.exe')
   foreach($name in @('manifest.json','settings.json','run-background.ps1')){Copy-Item -LiteralPath (Join-Path $script:fixtureRoot $name) -Destination (Join-Path $back $name)}
   [IO.File]::WriteAllText((Join-Path $back 'startup-task.xml'),'<Task><OriginalFailure>true</OriginalFailure></Task>')
   Copy-Item -LiteralPath (Join-Path $package 'U8OmApi.exe') -Destination (Join-Path $script:fixtureRoot 'bin\U8OmApi.update-staged.exe')
   if($scenario -eq 'bad-backup'){[IO.File]::WriteAllText((Join-Path $back 'U8OmApi.exe'),'CHANGED_BACKUP')}
  }
  # 仅重定位测试副本，保留生产脚本中的保护条件。
  $fixtureCode=$source
  $fixtureCode=$fixtureCode.Replace('$candidate=Join-Path $PSScriptRoot ''U8OmApi.exe''',('$candidate='''+(Join-Path $package 'U8OmApi.exe')+''''))
  $fixtureCode=$fixtureCode.Replace('(Join-Path $PSScriptRoot ''runtime-dependencies.json'')',(''''+(Join-Path $package 'runtime-dependencies.json')+''''))
  $fixtureCode=[regex]::Replace($fixtureCode,'(?m)^\$latch=.*$',('$latch='''+(Join-Path $script:fixtureRoot 'native-worker-pending.json')+''''))
  $fixtureCode=[regex]::Replace($fixtureCode,'(?m)^if\(!\(New-Object Security\.Principal\.WindowsPrincipal\(\$identity\)\).*RUN_AS_ADMINISTRATOR.*$','# 仅测试副本跳过本机提权校验')
  $fixtureCode=$fixtureCode.Replace('Global\ExampleOrg-U8OmApi-999-2026',('Local\U8DeploymentFixture-'+[guid]::NewGuid().ToString('N'))).Replace('exit 0','return')
  $fixtureCode=$fixtureCode.Replace($probeDefinition,'function Test-HostHttpReady($Settings){$script:probeCalls++;return $true}')
  $mode='Apply';if($scenario -like '*-inspect'){$mode='Inspect'}
  $failure=$null;$output=$null
  try{$output=& ([ScriptBlock]::Create($fixtureCode)) -Mode $mode -ExpectedCurrentHash $oldHash -CandidateHash $newHash -ProductionRoot $script:fixtureRoot}catch{$failure=$_.Exception.Message}
  $exeAfter=(Get-FileHash -LiteralPath (Join-Path $script:fixtureRoot 'bin\U8OmApi.exe')).Hash
  if($scenario -eq 'bad-backup'){
   if($failure -cne 'ORIGINAL_BACKUP_HASH_MISMATCH' -or $script:taskActions -ne 0 -or $exeAfter -cne $oldHash){throw 'BAD_BACKUP_NOT_REJECTED_BEFORE_DISRUPTION'}
  }elseif($scenario -eq 'rollback'){
   if($failure -cne 'START_FAILURE_INJECTED' -or $exeAfter -cne $oldHash -or (Get-FileHash -LiteralPath (Join-Path $script:fixtureRoot 'manifest.json')).Hash -cne $manifestBefore -or $script:productionTask.State -ne 'Running'){throw ('ROLLBACK_NOT_CONFIRMED error='+$failure)}
  }elseif($mode -eq 'Inspect'){
   if($failure -or ($output|ConvertFrom-Json).status -cne 'DEPLOYMENT_PREFLIGHT_OK' -or $script:taskActions -ne 0 -or $exeAfter -cne $oldHash){throw ('INSPECT_CHANGED_PRODUCTION error='+$failure)}
  }else{
   if($failure -or ($output|ConvertFrom-Json).status -cne 'U8_INTERFACE_DEPLOYED' -or $exeAfter -cne $newHash -or $script:productionTask.State -ne 'Running'){throw ('DEPLOYMENT_FIXTURE_FAILED '+$scenario+' error='+$failure)}
   if((Get-FileHash -LiteralPath (Join-Path $script:fixtureRoot ('backups\'+$newHash+'\U8OmApi.exe'))).Hash -cne $oldHash -or (Get-FileHash -LiteralPath (Join-Path $script:fixtureRoot ('backups\'+$newHash+'\U8OmApi.replaced-original.exe'))).Hash -cne $oldHash){throw 'ORIGINAL_BACKUP_CHANGED'}
   if(($output|ConvertFrom-Json).host_pid -ne 2000 -or $script:probeCalls -lt 1 -or (Get-Content -LiteralPath (Join-Path $script:fixtureRoot 'host-launch.json') -Raw|ConvertFrom-Json).pid -ne 7001){throw 'STALE_LAUNCH_MARKER_STILL_CONTROLS_READINESS'}
  }
  if((Get-FileHash -LiteralPath (Join-Path $script:fixtureRoot 'settings.json')).Hash -cne $settingsBefore -or [IO.File]::ReadAllText((Join-Path $script:fixtureRoot 'preserved-claim.json')) -cne 'DO_NOT_MODIFY_OR_RELEASE'){throw 'SETTINGS_OR_CLAIM_CHANGED'}
  'PASS='+$scenario
 }
 'DEPLOYMENT_WORKFLOW_TESTS_OK=6; LIVE_U8_CALLS=0; REAL_SERVICE_ACTIONS=0'
 'FIXTURE_RECORDS='+$fixtureBase
}finally{$env:COMPUTERNAME=$originalHostName}
