<#
.SYNOPSIS
    启动 genisys-php8 服务端（Windows）

.DESCRIPTION
    自动定位 PHP（优先 .\php\php.exe，其次 PATH），校验 sockets 扩展后启动。
    用法：
      powershell -ExecutionPolicy Bypass -File start-server.ps1
      powershell -ExecutionPolicy Bypass -File start-server.ps1 -Port 19132 -WorldDir D:\worlds\main
#>
param(
    [int]$Port = 19132,
    [string]$Motd = 'Genisys PHP8 Server',
    [string]$WorldDir = '',
    [string]$PhpPath = ''
)

$ErrorActionPreference = 'Stop'

$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))

# 1. 定位 PHP
if ($PhpPath -eq '') {
    $candidates = @(
        (Join-Path $repoRoot 'php\php.exe'),
        'php.exe' # PATH
    )
    foreach ($c in $candidates) {
        $cmd = Get-Command $c -ErrorAction SilentlyContinue
        if ($cmd -ne $null) { $PhpPath = $cmd.Source; break }
    }
}
if ($PhpPath -eq '' -or -not (Test-Path $PhpPath)) {
    Write-Host '未找到 PHP。请先运行 windows\setup-php.ps1 安装运行时。' -ForegroundColor Red
    exit 1
}

# 2. 校验 sockets
$ok = & $PhpPath -r 'exit(extension_loaded("sockets") ? 0 : 1);'
if ($LASTEXITCODE -ne 0) {
    Write-Host 'PHP 缺少 sockets 扩展：请检查 php.ini 中 extension=sockets 是否启用。' -ForegroundColor Red
    exit 1
}

# 3. 启动（世界目录通过环境变量传入，index.php 使用 /tmp/genisys-php8/world 或默认值）
$env:GENISYS_PORT = $Port
$env:GENISYS_MOTD = $Motd
if ($WorldDir -ne '') { $env:GENISYS_WORLD_DIR = $WorldDir }

Write-Host "使用 PHP：$PhpPath" -ForegroundColor Cyan
Write-Host "仓库根：$repoRoot"
Write-Host "UDP 端口：$Port（请确认防火墙放行）" -ForegroundColor Cyan
Write-Host '按 Ctrl+C 停止服务端'
Write-Host ''

Set-Location $repoRoot
& $PhpPath index.php
exit $LASTEXITCODE
