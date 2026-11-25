@echo off
cd /d "%~dp0..\..\.."
set XAMPP_PATH=%CD%
set XAMPP_WIN_PATH=%XAMPP_PATH:\=/%

rem Perbarui path Apache
if exist "%XAMPP_PATH%\apache\conf\httpd.conf" (
    powershell -Command "(Get-Content '%XAMPP_PATH%\apache\conf\httpd.conf') -replace 'C:/xamppp', '%XAMPP_WIN_PATH%' | Set-Content '%XAMPP_PATH%\apache\conf\httpd.conf'"
)

rem Perbarui path MySQL
if exist "%XAMPP_PATH%\mysql\bin\my.ini" (
    powershell -Command "(Get-Content '%XAMPP_PATH%\mysql\bin\my.ini') -replace 'C:/xamppp', '%XAMPP_WIN_PATH%' -replace 'C:\\xamppp', '%XAMPP_PATH:\=\\%' | Set-Content '%XAMPP_PATH%\mysql\bin\my.ini'"
)