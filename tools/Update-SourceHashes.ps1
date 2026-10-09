# 按仓库实际字节更新 OA 运行校验清单，不访问生产环境。
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
foreach($relative in @('oa/auto-audit/hashes.json','oa/sync/source-hashes.json')){
 $path=Join-Path $root $relative
 $old=Get-Content -LiteralPath $path -Raw -Encoding UTF8|ConvertFrom-Json
 $next=[ordered]@{}
 foreach($property in $old.PSObject.Properties){
  $next[$property.Name]=(Get-FileHash -LiteralPath (Join-Path $root ('oa/server/'+$property.Name)) -Algorithm SHA256).Hash.ToLowerInvariant()
 }
 [IO.File]::WriteAllText($path,(($next|ConvertTo-Json)+'`n').Replace('`n',"`n"),(New-Object Text.UTF8Encoding($false)))
}
'OA_SOURCE_HASHES_UPDATED'
