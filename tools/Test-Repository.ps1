# 统一验证源码；所有审核与部署测试均使用模拟对象。
param([string]$Php='php.exe')
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
foreach($file in (Get-ChildItem -LiteralPath $root -Recurse -File -Filter '*.ps1'|Where-Object {$_.FullName -notmatch '[\\/]release[\\/]|[\\/]deployment-fixtures[\\/]'})){
 $tokens=$null;$errors=$null
 $null=[Management.Automation.Language.Parser]::ParseFile($file.FullName,[ref]$tokens,[ref]$errors)
 if($errors.Count){throw ('POWERSHELL_PARSE_FAILED: '+$file.FullName)}
}
foreach($file in (Get-ChildItem -LiteralPath (Join-Path $root 'oa'),(Join-Path $root 'tests/oa') -Recurse -File -Filter '*.php')){
 & $Php -n -l $file.FullName
 if($LASTEXITCODE){throw ('PHP_LINT_FAILED: '+$file.FullName)}
}
foreach($name in @('context-tests.php','test-historical-order-context.php','test-example-mapping.php')){
 & $Php -n (Join-Path $root ('tests/oa/'+$name))
 if($LASTEXITCODE){throw ('PHP_TEST_FAILED: '+$name)}
}
foreach($relative in @('oa/auto-audit/hashes.json','oa/sync/source-hashes.json')){
 $manifest=Get-Content -LiteralPath (Join-Path $root $relative) -Raw|ConvertFrom-Json
 foreach($entry in $manifest.PSObject.Properties){
  if((Get-FileHash -LiteralPath (Join-Path $root ('oa/server/'+$entry.Name))).Hash -ine $entry.Value){throw 'OA_HASH_MANIFEST_MISMATCH'}
 }
}
& (Join-Path $root 'u8/run-tests.ps1')
foreach($name in @('Test-DeploymentTaskSelection.ps1','Test-DeploymentWorkflow.ps1')){& (Join-Path $root ('tests/deploy/'+$name))}
'REPOSITORY_CHECKS_OK; LIVE_U8_CALLS=0; REAL_DEPLOYMENTS=0'
