# 构建发布包；预期旧版本哈希由生产服务器只读查询后传入。
param([Parameter(Mandatory=$true)][ValidatePattern('^[A-Fa-f0-9]{64}$')][string]$ExpectedCurrentHash)
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
& (Join-Path $root 'u8/run-tests.ps1')
$exe=Join-Path $root 'u8/bin/U8OmApi.exe'
$newHash=(Get-FileHash -LiteralPath $exe).Hash
$package=Join-Path $root ('release/'+$newHash)
if(Test-Path -LiteralPath $package){throw 'RELEASE_EXISTS_INSPECT_FIRST'}
New-Item -ItemType Directory -Path $package|Out-Null
foreach($path in @($exe,(Join-Path $root 'deploy/Update-U8Interface.ps1'))){Copy-Item -LiteralPath $path -Destination $package}
$dependencies=@(Get-ChildItem -LiteralPath (Join-Path $root 'u8/bin') -File|Where-Object {$_.Extension -eq '.dll' -or $_.Name -eq 'U8OmApi.exe.config'}|ForEach-Object {[ordered]@{path=('bin/'+$_.Name);sha256=(Get-FileHash -LiteralPath $_.FullName).Hash}})
[IO.File]::WriteAllText((Join-Path $package 'runtime-dependencies.json'),(ConvertTo-Json -InputObject $dependencies),(New-Object Text.UTF8Encoding($false)))
$commit=(& git -C $root rev-parse HEAD).Trim();if($LASTEXITCODE){throw 'GIT_REVISION_UNAVAILABLE'}
$dirty=@(& git -C $root status --porcelain).Count -ne 0;if($LASTEXITCODE){throw 'GIT_STATUS_FAILED'}
[IO.File]::WriteAllText((Join-Path $package 'release.json'),([ordered]@{at=(Get-Date).ToString('o');source_commit=$commit;working_tree_dirty=$dirty;expected_current_sha256=$ExpectedCurrentHash.ToUpperInvariant();candidate_sha256=$newHash}|ConvertTo-Json),(New-Object Text.UTF8Encoding($false)))
$command="powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Update-U8Interface.ps1 -Mode Inspect -ExpectedCurrentHash $ExpectedCurrentHash -CandidateHash $newHash"
[IO.File]::WriteAllText((Join-Path $package 'README.txt'),("管理员进入本发布包目录，先执行：`r`n"+$command+"`r`n检查通过并进入维护窗口后，将 -Mode Inspect 改为 -Mode Apply。`r`n只替换接口 EXE，保留配置、凭据及所有领取记录。"),(New-Object Text.UTF8Encoding($true)))
'RELEASE_PACKAGE='+$package
