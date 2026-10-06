@echo off
rem genisys-php8 启动入口（双击即可）
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-server.ps1" %*
pause
