<#
.SYNOPSIS
    从源码编译 Windows 版 PHP（genisys-php8 定制运行时）——进阶路径

.DESCRIPTION
    仅当需要定制 PHP（裁剪扩展/调参/安全加固）时使用。日常运行请用
    setup-php.ps1（官方预编译包，5 分钟搞定）。

    源码编译 = Microsoft 官方 php-sdk 流程：
      php-sdk-binary-tools 提供构建环境（含依赖管理），
      php-src 源码 → buildconf → configure → nmake。

    前置条件（脚本会逐项检查）：
      1. Visual Studio 2022（勾选"使用 C++ 的桌面开发"工作负载）
         —— PHP 8.1-8.3 用 vs16 工具集，VS2022 自带
      2. git（PATH 中）
      3. 磁盘空间 ≥ 10GB，构建耗时 30-90 分钟（视机器）

    用法（PowerShell）：
      powershell -ExecutionPolicy Bypass -File build-php-from-source.ps1
      powershell -ExecutionPolicy Bypass -File build-php-from-source.ps1 -PhpTag php-8.3.13 -KeepSource

    产物：WorkDir\php-src\x64\Release\php.exe（NTS CLI）+ 相关 dll，
    构建完成后自动复制到 <仓库>\php-src-build\ 并跑服务端自检。
#>
param(
    [string]$PhpTag = 'php-8.3.13',            # php-src 标签
    [string]$WorkDir = "$env:USERPROFILE\php-build",  # 工作目录
    [switch]$KeepSource                        # 构建后保留源码目录
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

Write-Host '=== genisys-php8：Windows 源码编译 PHP ===' -ForegroundColor Cyan

# ---------- 前置检查 ----------
# 1. Visual Studio（vswhere 检测 C++ 工作负载）
$vswhere = "${env:ProgramFiles(x86)}\Microsoft Visual Studio\Installer\vswhere.exe"
if (-not (Test-Path $vswhere)) {
    Write-Host '未找到 Visual Studio 安装器（vswhere）。请先安装 VS2022 并勾选"使用 C++ 的桌面开发"。' -ForegroundColor Red
    exit 1
}
$vsPath = & $vswhere -latest -products * -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath
if (-not $vsPath) {
    Write-Host 'VS2022 已安装但缺少 C++ 工具集。请打开 VS Installer 补装"使用 C++ 的桌面开发"。' -ForegroundColor Red
    exit 1
}
Write-Host "VS 工具集：$vsPath"

# 2. git
if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    Write-Host 'PATH 中没有 git，请先安装：https://git-scm.com/download/win' -ForegroundColor Red
    exit 1
}

# ---------- 工作目录 ----------
New-Item -ItemType Directory -Force -Path $WorkDir | Out-Null

# 3. php-sdk-binary-tools（构建环境）
$sdkDir = Join-Path $WorkDir 'php-sdk-binary-tools'
if (-not (Test-Path (Join-Path $sdkDir 'bin\phpsdk_starter.bat'))) {
    Write-Host '克隆 php-sdk-binary-tools ...'
    git clone --depth 1 https://github.com/php/php-sdk-binary-tools $sdkDir
    if ($LASTEXITCODE -ne 0) { throw 'php-sdk 克隆失败' }
}

# 4. php-src 源码
$srcDir = Join-Path $WorkDir 'php-src'
if (-not (Test-Path $srcDir)) {
    Write-Host "克隆 php-src（tag $PhpTag，深度 1）..."
    git clone --depth 1 --branch $PhpTag https://github.com/php/php-src $srcDir
    if ($LASTEXITCODE -ne 0) { throw "php-src 克隆失败（检查 tag：$PhpTag）" }
}

# 5. 构建任务脚本（在 phpsdk 环境内执行）
$taskBat = Join-Path $WorkDir 'genisys-task.bat'
@"
@echo off
rem ---- 依赖下载（含 zlib/libxml2 等预编译包）----
call %SDK%\bin\phpsdk_deps.bat -c vs16 -a x64 -u -f
if errorlevel 1 exit /b 1

rem ---- 生成 configure 脚本 ----
cd /d %SRC%
call buildconf.bat --force
if errorlevel 1 exit /b 1

rem ---- genisys-php8 最小化配置：CLI + sockets + zlib + mbstring ----
rem --disable-zts 产 NTS（与官方 NTS 包一致，CLI 服务端足够）
call configure.bat --disable-all --enable-cli --disable-zts --disable-cgi ^
    --enable-sockets --with-zlib --enable-mbstring --without-pdo --disable-xmlwriter --disable-xmlreader
if errorlevel 1 exit /b 1

rem ---- 编译 ----
nmake
if errorlevel 1 exit /b 1
"@ | Set-Content -Path $taskBat -Encoding ASCII

Write-Host '开始编译（30-90 分钟）...' -ForegroundColor Cyan
$env:SDK = $sdkDir
$env:SRC = $srcDir
& cmd /c "`"$sdkDir\bin\phpsdk_starter.bat`" -c vs16 -a x64 -t `"$taskBat`""
if ($LASTEXITCODE -ne 0) {
    Write-Host '编译失败。常见原因：缺 VS C++ 工具集 / 磁盘不足 / 网络拉取依赖失败（重跑即可续传）。' -ForegroundColor Red
    exit 1
}

# 6. 收集产物并自检
$buildOut = Join-Path $srcDir 'x64\Release'
if (-not (Test-Path (Join-Path $buildOut 'php.exe'))) {
    Write-Host "未找到产物：$buildOut\php.exe（检查上方 nmake 输出）" -ForegroundColor Red
    exit 1
}
$dest = Join-Path $PSScriptRoot '..\php-src-build'
if (Test-Path $dest) { Remove-Item $dest -Recurse -Force }
Copy-Item $buildOut $dest -Recurse -Force

Write-Host ''
Write-Host '=== 编译完成 ===' -ForegroundColor Green
& (Join-Path $dest 'php.exe') -v
& (Join-Path $dest 'php.exe') -r 'exit(extension_loaded("sockets") && extension_loaded("zlib") ? 0 : 1);'
if ($LASTEXITCODE -ne 0) { Write-Host '自检失败：sockets/zlib 未启用' -ForegroundColor Red; exit 1 }
Write-Host '自检通过：sockets / zlib 可用' -ForegroundColor Green
Write-Host "使用 windows\start-server.ps1 -PhpPath `"$dest\php.exe`" 启动服务端"

# 7. 清理
if (-not $KeepSource) {
    Write-Host '清理源码与构建目录（-KeepSource 保留）...'
    Remove-Item $srcDir -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item (Join-Path $WorkDir 'php-sdk-binary-tools') -Recurse -Force -ErrorAction SilentlyContinue
}
