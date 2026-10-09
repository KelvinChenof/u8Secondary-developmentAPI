$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$root=Split-Path -Parent $MyInvocation.MyCommand.Path
$mutex=New-Object Threading.Mutex($false,'Global\ExampleOrg-OmDraftSync-App6-Flow31')
$owned=$false
try {
 try{$owned=$mutex.WaitOne(0)}catch [Threading.AbandonedMutexException]{$owned=$true}
 if(!$owned){exit 0}
 Add-Type -AssemblyName System.Security
 $bytes=[IO.File]::ReadAllBytes((Join-Path $root 'hmac.dpapi'))
 $plain=[Security.Cryptography.ProtectedData]::Unprotect($bytes,$null,[Security.Cryptography.DataProtectionScope]::LocalMachine)
 $key=[Text.Encoding]::UTF8.GetString($plain)
 if($key.Length -lt 32 -or $key.Length -gt 256){throw 'OM_KEY_INVALID'}
 $info=New-Object Diagnostics.ProcessStartInfo
 $info.FileName='D:/oa-server/php/php.exe'
 $info.Arguments='-d display_errors=0 -d log_errors=0 "'+(Join-Path $root 'sync.php')+'"'
 $info.WorkingDirectory='D:/oa-server/www/eoffice/server'
 $info.UseShellExecute=$false;$info.CreateNoWindow=$true;$info.RedirectStandardOutput=$true;$info.RedirectStandardError=$true
 $info.EnvironmentVariables['U8_OM_API_APP_ID']='6';$info.EnvironmentVariables['U8_OM_API_URL']='http://192.0.2.10:18766'
 $info.EnvironmentVariables['U8_OM_API_HMAC_KEY']=$key;$info.EnvironmentVariables['U8_OM_API_READ_ENABLED']='1';$info.EnvironmentVariables['U8_OM_SYNC_ENABLED']='1';$info.EnvironmentVariables['U8_OM_API_AUDIT_ENABLED']='0'
 $proc=New-Object Diagnostics.Process;$proc.StartInfo=$info
 if(!$proc.Start()){throw 'OM_PHP_START_FAILED'}
 $key=$null;[Array]::Clear($plain,0,$plain.Length)
 $stdout=$proc.StandardOutput.ReadToEndAsync();$stderr=$proc.StandardError.ReadToEndAsync()
 $proc.WaitForExit()
 $text=$stdout.Result.Trim()
 try{$status=$text|ConvertFrom-Json}catch{throw 'OM_CHILD_OUTPUT_INVALID'}
 $safe=$status|ConvertTo-Json -Compress
 [IO.File]::AppendAllText((Join-Path $root 'runs.jsonl'),$safe+[Environment]::NewLine,[Text.Encoding]::UTF8)
 [IO.File]::WriteAllText((Join-Path $root 'last-run.json'),$safe,[Text.Encoding]::UTF8)
 Write-Output $safe
 $code=$proc.ExitCode;$proc.Dispose();exit $code
}catch{Write-Output 'OM_DRAFT_SYNC_STOPPED';exit 1}finally{if($owned){$mutex.ReleaseMutex()};$mutex.Dispose()}
