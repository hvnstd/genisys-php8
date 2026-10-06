<#
.SYNOPSIS
    genisys-php8 Windows 端 PHP 环境准备脚本（推荐路径：官方预编译包）

.DESCRIPTION
    下载并配置 Windows 官方 PHP 8.x NTS 运行时，启用服务端所需扩展，
    并做启动前自检。服务端只需要：sockets（官方包自带 dll）、zlib（内置）、
    Fiber（8.1+ 内置）。无需 composer install（bootstrap.php 自带引导器）。

    用法（PowerShell）：
      powershell -ExecutionPolicy Bypass -File setup-php.ps1              # PHP 8.3 默认
      powershell -ExecutionPolicy Bypass -File setup-php.ps1 -Version 8.2

    如确需从源码编译 PHP，参见同目录 build-php-from-source.ps1。
#>
param(
    [ValidateSet('8.1', '8.2', '8.3')]
    [string]$Version = '8.3',

    # PHP 解压目录（默认 genisys-php8\php）
    [string]$InstallDir = (Join-Path $PSScriptRoot '..\php')
)

$ErrorActionPreference = 'Stop'

# Windows PowerShell 5.1 需要 TLS1.2
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$InstallDir = [System.IO.Path]::GetFullPath($InstallDir)
$phpExe = Join-Path $InstallDir 'php.exe'

Write-Host '=== genisys-php8 PHP 环境准备 ===' -ForegroundColor Cyan

# 1. 已装则跳过下载
if (Test-Path $phpExe) {
    $existing = & $phpExe -v | Select-Object -First 1
    Write-Host "检测到已有 PHP：$existing（如需重装请先删除 $InstallDir）" -ForegroundColor Yellow
} else {
    # 官方"最新稳定版"固定链接：PHP 8.1-8.3 为 vs16 x64
    $url = "https://windows.php.net/downloads/releases/php-$Version-nts-Win32-vs16-x64-latest.zip"
    $zip = Join-Path $env:TEMP "php-$Version-nts-x64.zip"

    Write-Host "下载 $url ..."
    Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing

    Write-Host "解压到 $InstallDir ..."
    if (Test-Path $InstallDir) { Remove-Item $InstallDir -Recurse -Force }
    Expand-Archive -Path $zip -DestinationPath $InstallDir -Force
    Remove-Item $zip -Force
}

# 2. 生成 php.ini（启用服务端所需扩展）
$iniDir = $InstallDir
$ini = Join-Path $iniDir 'php.ini'
if (-not (Test-Path $ini)) {
    Write-Host '生成 php.ini（CLI + sockets/mbstring/curl/openssl）...'
    @"
; genisys-php8 最小配置（CLI 专用）
extension_dir = "ext"

; 服务端必需
extension = sockets

; 常用（可选，官方包自带 dll）
extension = mbstring
extension = curl
extension = openssl

; 内存与超时（常驻服务进程）
memory_limit = 512M
max_execution_time = 0
"@ | Set-Content -Path $ini -Encoding ASCII
} else {
    Write-Host 'php.ini 已存在，跳过配置' -ForegroundColor Yellow
}

# 3. 自检：版本 / sockets / zlib / Fiber
Write-Host ''
Write-Host '=== 自检 ===' -ForegroundColor Cyan
$ver = & $phpExe -r 'echo PHP_VERSION;'
Write-Host "PHP 版本：$ver"

$check = & $phpExe -d display_errors=0 -r '
$missing = [];
if (!extension_loaded("sockets")) $missing[] = "sockets";
if (!extension_loaded("zlib")) $missing[] = "zlib";
if (!class_exists("Fiber")) $missing[] = "Fiber";
echo $missing === [] ? "OK" : implode(",", $missing);
exit($missing === [] ? 0 : 1);
'
if ($LASTEXITCODE -ne 0) {
    Write-Host "自检失败，缺少组件：$check" -ForegroundColor Red
    exit 1
}
Write-Host '自检通过：sockets / zlib / Fiber 全部可用' -ForegroundColor Green

# 4. 提示
Write-Host ''
Write-Host '=== 环境就绪 ===' -ForegroundColor Green
Write-Host "PHP 路径：$phpExe"
Write-Host '启动服务端：'
Write-Host '  powershell -ExecutionPolicy Bypass -File windows\start-server.ps1' -ForegroundColor White
Write-Host '或直接：'
Write-Host "  & `"$phpExe`" index.php" -ForegroundColor White
Write-Host ''
Write-Host '说明：'
Write-Host '  - 无需 composer install（bootstrap.php 自带引导器）'
Write-Host '  - 世界格式默认 Anvil（无需 ext-leveldb；ext-leveldb 无官方 Windows dll）'
Write-Host '  - 防火墙首次启动会询问放行 UDP 19132，选择允许'
