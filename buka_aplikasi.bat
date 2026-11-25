@echo off
title Menjalankan Aplikasi Proyek Sekolah...

:: ----------------------------------------------------
:: 1. SAPU BERSIH SEMUA PATH DI SEMUA FILE CONF & INI
:: ----------------------------------------------------
pushd "%~dp0..\.."
set XAMPP_PATH=%CD%
set XAMPP_WIN_PATH=%XAMPP_PATH:\=/%

rem Mengubah path di SELURUH file .conf di folder apache (httpd.conf, httpd-xampp.conf, dll)
powershell -Command "Get-ChildItem -Path '%XAMPP_PATH%\apache\conf' -Recurse -Filter *.conf | ForEach-Object { (Get-Content $_.FullName) -replace 'C:/xamppp', '%XAMPP_WIN_PATH%' -replace 'C:\\xamppp', '%XAMPP_PATH:\=\\%' | Set-Content $_.FullName }"

rem Mengubah path di file my.ini (MySQL)
if exist "%XAMPP_PATH%\mysql\bin\my.ini" (
    powershell -Command "(Get-Content '%XAMPP_PATH%\mysql\bin\my.ini') -replace 'C:/xamppp', '%XAMPP_WIN_PATH%' -replace 'C:\\xamppp', '%XAMPP_PATH:\=\\%' | Set-Content '%XAMPP_PATH%\mysql\bin\my.ini'"
)

rem Mengubah path di file php.ini (PHP)
if exist "%XAMPP_PATH%\php\php.ini" (
    powershell -Command "(Get-Content '%XAMPP_PATH%\php\php.ini') -replace 'C:/xamppp', '%XAMPP_WIN_PATH%' -replace 'C:\\xamppp', '%XAMPP_PATH:\=\\%' | Set-Content '%XAMPP_PATH%\php\php.ini'"
)
popd

:: ----------------------------------------------------
:: 2. JALANKAN APACHE & MYSQL
:: ----------------------------------------------------
echo Menyalakan Web Server dan Database...
pushd "%~dp0..\.."

start /B apache\bin\httpd.exe
start /B mysql\bin\mysqld.exe --defaults-file="mysql\bin\my.ini" --standalone

echo Menunggu koneksi server siap...
timeout /t 4 /nobreak > nul

echo Membuka aplikasi...
start http://localhost/rev_projek_sekolah/login.php

popd
exit