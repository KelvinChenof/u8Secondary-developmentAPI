$ErrorActionPreference='Stop'
$source=[IO.File]::ReadAllText((Join-Path $PSScriptRoot '../../deploy/Update-U8Interface.ps1'),[Text.Encoding]::UTF8)
$selection=[regex]::Match($source,'(?ms)^\$tasks=@\(Get-ScheduledTask\|Where-Object \{.*?^\}\)').Value
if(!$selection){throw 'TASK_SELECTION_NOT_FOUND'}
$select=[ScriptBlock]::Create($selection+'; $tasks')
$root='D:\u8-om-production'
$rootPattern=[regex]::Escape($root);$rootSlashPattern=[regex]::Escape($root.Replace('\','/'))
function New-TestTask($name,$state,$directory){[pscustomobject]@{TaskName=$name;State=$state;Actions=@([pscustomobject]@{Execute='powershell.exe';Arguments='-File runner.ps1';WorkingDirectory=$directory})}}
function Get-ScheduledTask{$script:fixture}
$production=New-TestTask 'ExampleOrg-U8OM-Isolated-Production' 'Running' $root
$diagnostic=New-TestTask 'ExampleOrg-U8OM-Login-Diagnostic' 'Disabled' $root
$startup=New-TestTask 'ExampleOrg-U8OM-System-StartupCheck' 'Ready' $root
$legacy=New-TestTask 'ExampleOrg-U8OM-Api-Unattended' 'Running' 'D:\u8-om-unattended'
$script:fixture=@($production,$diagnostic,$startup,$legacy)
$actual=@(& $select)
if($actual.Count -ne 1 -or $actual[0].TaskName -cne $production.TaskName){throw ('PRODUCTION_TASK_SELECTION_FAILED count='+$actual.Count)}
$script:fixture=@($production,(New-TestTask 'Another-Production' 'Running' $root),$diagnostic)
if(@(& $select).Count -ne 2){throw 'DUPLICATE_RUNNING_TASK_MUST_REMAIN_AMBIGUOUS'}
$script:fixture=@($diagnostic,$startup,$legacy)
if(@(& $select).Count -ne 0){throw 'NO_RUNNING_PRODUCTION_TASK_MUST_FAIL_CLOSED'}
$script:fixture=@(New-TestTask 'Forward-Slash-Production' 'Running' ($root.Replace('\','/')))
if(@(& $select).Count -ne 1){throw 'FORWARD_SLASH_PRODUCTION_NOT_FOUND'}
'DEPLOYMENT_TASK_SELECTION_TESTS_OK=4; PRODUCTION_ACTIONS=0'
