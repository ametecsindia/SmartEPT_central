@echo off
setlocal
title SmartEPT - Generate Licence Signing Keys (run ONCE)
cd /d "%~dp0"
echo ============================================================
echo  SmartEPT - Generate Licence Signing Key Pair  (run ONCE)
echo ============================================================
echo.
set "PHP="
for /d %%p in ("C:\laragon\bin\php\php-*") do set "PHP=%%p\php.exe"
if not defined PHP for /f "delims=" %%w in ('where php 2^>nul') do set "PHP=%%w"
if not defined PHP ( echo [ERROR] PHP not found. Start Laragon. & pause & exit /b 1 )

if not exist "storage\app\keys" mkdir "storage\app\keys"

"%PHP%" -r "$k=openssl_pkey_new(array('private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA)); openssl_pkey_export($k,$priv); $d=openssl_pkey_get_details($k); file_put_contents('storage/app/keys/license_private.pem',$priv); file_put_contents('storage/app/keys/license_public.pem',$d['key']); echo $d['key'];"

echo.
echo ============================================================
echo  DONE. Keys written to  storage\app\keys\
echo    license_private.pem  = KEEP SECRET. Never ship it. Stays on Central.
echo    license_public.pem   = paste (the block above) into the PRODUCT file
echo                           app\Services\LicenseFile.php  ->  PUBLIC_KEY
echo  Then SourceGuardian-encrypt the product and rebuild the installer zip.
echo ============================================================
pause
