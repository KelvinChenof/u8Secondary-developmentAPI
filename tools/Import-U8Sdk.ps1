# 从本机有授权的生产运行库或 U8 SDK 目录导入依赖，不注册 COM 或执行 U8。
param([Parameter(Mandatory=$true)][string]$SdkBin)
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$target=Join-Path $root 'u8/bin'
$source=[IO.Path]::GetFullPath($SdkBin)
$names=Get-Content -LiteralPath (Join-Path $root 'docs/sdk-files.json') -Raw|ConvertFrom-Json
foreach($name in $names){
 if($name -notmatch '^[A-Za-z0-9._-]+\.dll$'){throw 'SDK_FILENAME_INVALID'}
 if(!(Test-Path -LiteralPath (Join-Path $source $name))){throw ('SDK_FILE_MISSING: '+$name)}
}
New-Item -ItemType Directory -Path $target -Force|Out-Null
foreach($name in $names){Copy-Item -LiteralPath (Join-Path $source $name) -Destination (Join-Path $target $name) -Force}
'SDK_IMPORTED'
