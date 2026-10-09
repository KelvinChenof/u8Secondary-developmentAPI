$ErrorActionPreference='Stop';$ProgressPreference='SilentlyContinue'
Add-Type -AssemblyName System.Security
$plain=$null
try{
 $plain=[Security.Cryptography.ProtectedData]::Unprotect([IO.File]::ReadAllBytes('D:/u8-om-production-sync/hmac.dpapi'),$null,[Security.Cryptography.DataProtectionScope]::LocalMachine)
 [Console]::Out.Write([Convert]::ToBase64String($plain))
}catch{exit 1}finally{if($plain){[Array]::Clear($plain,0,$plain.Length)}}
