$ErrorActionPreference='Stop'
Push-Location $PSScriptRoot
try{
 $csc='C:\Windows\Microsoft.NET\Framework\v4.0.30319\csc.exe'
 & $csc /nologo /platform:x86 /r:System.Web.Extensions.dll /out:bin\CoreTests.exe CoreTests.cs Core.cs
 if($LASTEXITCODE){throw 'CORE_COMPILE_FAILED'}
 & .\bin\CoreTests.exe .\bin\test-signature-vector.json
 if($LASTEXITCODE){throw 'CORE_TEST_FAILED'}
 & $csc /nologo /platform:x86 /out:bin\ResourceTests.exe ResourceTests.cs ResourceGuard.cs
 if($LASTEXITCODE){throw 'RESOURCE_COMPILE_FAILED'}
 & .\bin\ResourceTests.exe
 if($LASTEXITCODE){throw 'RESOURCE_TEST_FAILED'}
 & $csc /nologo /platform:x86 /r:System.Web.Extensions.dll /out:bin\WorkerTests.exe WorkerTests.cs WorkerTransport.cs Core.cs ResourceGuard.cs
 if($LASTEXITCODE){throw 'WORKER_COMPILE_FAILED'}
 & .\bin\WorkerTests.exe
 if($LASTEXITCODE){throw 'WORKER_TEST_FAILED'}
 & $csc /nologo /platform:x86 /r:System.Web.Extensions.dll /out:bin\HostTests.exe HostTests.cs IsolatedHost.cs WorkerTransport.cs Core.cs ResourceGuard.cs
 if($LASTEXITCODE){throw 'HOST_COMPILE_FAILED'}
 & .\bin\HostTests.exe
 if($LASTEXITCODE){throw 'HOST_TEST_FAILED'}
 & .\build-local.ps1
 & .\bin\U8OmApi.exe --startup-check
 if($LASTEXITCODE){throw 'STARTUP_CHECK_FAILED'}
}finally{Pop-Location}
