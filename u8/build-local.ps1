$ErrorActionPreference='Stop'
$root=Split-Path -Parent $MyInvocation.MyCommand.Path
$bin=Join-Path $root 'bin'
# 引用既有 U8 SDK 库，以下列表是本次实际编译的源码。
$references=@('Interop.U8Login.dll','Interop.MSXML2.dll','UFIDA.U8.U8APIFramework.dll','UFIDA.U8.U8MOMAPIFramework.dll','UFIDA.U8.MomServiceCommon.dll')|ForEach-Object{'/reference:'+(Join-Path $bin $_)}
$sources=@('Core.cs','U8Runtime.cs','Program.cs','Credentials.cs','ResourceGuard.cs','WorkerTransport.cs','Worker.cs','IsolatedHost.cs')|ForEach-Object{Join-Path $root $_}
# U8 COM 组件要求 x86；仅编译到本地 bin，不执行或部署接口。
& 'C:\Windows\Microsoft.NET\Framework\v4.0.30319\csc.exe' /nologo /target:exe /platform:x86 /codepage:65001 ('/out:'+(Join-Path $bin 'U8OmApi.exe')) /reference:System.Security.dll /reference:System.Drawing.dll /reference:System.Windows.Forms.dll /reference:System.Web.Extensions.dll $references $sources
if($LASTEXITCODE -ne 0){throw 'BUILD_FAILED'}
Write-Output 'BUILD_OK'
