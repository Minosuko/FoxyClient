@echo off
pushd "%~dp0"
php SignClient.php
if errorlevel 1 (
    popd
    exit /b 1
)
php SignExecutable.php
set "exit_code=%errorlevel%"
popd
exit /b %exit_code%
