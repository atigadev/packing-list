@echo off
rem ==========================================================================
rem  Membuat sertifikat HTTPS lokal untuk Packing List (XAMPP / Apache)
rem  Pemakaian : buat-sertifikat-https.bat [IP-komputer-server] [/test]
rem  Contoh    : buat-sertifikat-https.bat 192.168.1.10
rem  /test     : hanya membuat file sertifikat, tidak memasang ke Apache
rem
rem  Hasil:
rem   - CA lokal (tools\ssl\ca.key + ca.crt) dibuat sekali dan dipakai ulang
rem   - Sertifikat server untuk localhost + IP komputer, ditandatangani CA lokal
rem   - Dipasang ke C:\xampp\apache\conf\ssl.crt & ssl.key (file lama dibackup)
rem   - sertifikat-ca.crt disalin ke folder aplikasi agar mudah dipasang di HP
rem ==========================================================================
setlocal EnableDelayedExpansion

set "XAMPP=C:\xampp"
set "OPENSSL=%XAMPP%\apache\bin\openssl.exe"
set "OPENSSL_CONF=%XAMPP%\apache\conf\openssl.cnf"
if "%OUTDIR%"=="" (set "OUT=%~dp0ssl") else (set "OUT=%OUTDIR%")
set "APP=%~dp0.."
set "IP=%~1"
set "MODE=%~2"
if /i "%IP%"=="/test" (set "MODE=/test" & set "IP=")

if not exist "%OPENSSL%" (
  echo [GAGAL] openssl tidak ditemukan di %OPENSSL%
  exit /b 1
)

rem Ambil IPv4 pertama jika IP tidak diberikan
if "%IP%"=="" (
  for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do if not defined IP set "IP=%%a"
)
set "IP=%IP: =%"
if "%IP%"=="" (
  echo [GAGAL] IP komputer tidak terdeteksi. Jalankan: %~nx0 192.168.x.x
  exit /b 1
)

echo IP server   : %IP%
echo Folder hasil: %OUT%
if not exist "%OUT%" mkdir "%OUT%"

rem ---- 1. CA lokal (sekali saja) ----
if not exist "%OUT%\ca.key" (
  echo Membuat CA lokal...
  "%OPENSSL%" genrsa -out "%OUT%\ca.key" 2048 || goto :fail
  "%OPENSSL%" req -x509 -new -key "%OUT%\ca.key" -sha256 -days 3650 -subj "/CN=Packing List Local CA" -out "%OUT%\ca.crt" || goto :fail
) else (
  echo CA lokal sudah ada, dipakai ulang.
)

rem ---- 2. Sertifikat server ----
(
  echo basicConstraints=CA:FALSE
  echo keyUsage=digitalSignature,keyEncipherment
  echo extendedKeyUsage=serverAuth
  echo subjectAltName=DNS:localhost,DNS:%COMPUTERNAME%,IP:127.0.0.1,IP:%IP%
) > "%OUT%\server.ext"

"%OPENSSL%" genrsa -out "%OUT%\server.key" 2048 || goto :fail
"%OPENSSL%" req -new -key "%OUT%\server.key" -subj "/CN=%IP%" -out "%OUT%\server.csr" || goto :fail
"%OPENSSL%" x509 -req -in "%OUT%\server.csr" -CA "%OUT%\ca.crt" -CAkey "%OUT%\ca.key" -CAcreateserial -out "%OUT%\server.crt" -days 825 -sha256 -extfile "%OUT%\server.ext" || goto :fail
"%OPENSSL%" verify -CAfile "%OUT%\ca.crt" "%OUT%\server.crt" || goto :fail

if /i "%MODE%"=="/test" (
  echo [TEST] Sertifikat dibuat, tidak dipasang ke Apache.
  exit /b 0
)

rem ---- 3. Pasang ke Apache (backup file lama) ----
for /f %%t in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmmss"') do set "TS=%%t"
set "CRT=%XAMPP%\apache\conf\ssl.crt\server.crt"
set "KEY=%XAMPP%\apache\conf\ssl.key\server.key"
if exist "%CRT%" copy /y "%CRT%" "%CRT%.bak-%TS%" >nul
if exist "%KEY%" copy /y "%KEY%" "%KEY%.bak-%TS%" >nul
copy /y "%OUT%\server.crt" "%CRT%" >nul || goto :fail
copy /y "%OUT%\server.key" "%KEY%" >nul || goto :fail
copy /y "%OUT%\ca.crt" "%APP%\sertifikat-ca.crt" >nul

echo.
echo ==========================================================================
echo  SELESAI. Langkah berikutnya:
echo   1. Restart Apache dari XAMPP Control Panel (Stop lalu Start).
echo   2. Di setiap HP, buka  http://%IP%/packing-list/sertifikat-ca.crt
echo      lalu pasang: Setelan ^> Keamanan ^> Enkripsi ^& kredensial ^>
echo      Instal sertifikat ^> Sertifikat CA. (Sekali saja per HP.)
echo   3. Buka aplikasi lewat  https://%IP%/packing-list/
echo      lalu pilih menu Chrome ^> "Instal aplikasi" / "Tambahkan ke layar utama".
echo  File lama Apache dibackup dengan akhiran .bak-%TS%
echo  JANGAN bagikan file tools\ssl\ca.key ke siapa pun.
echo ==========================================================================
exit /b 0

:fail
echo [GAGAL] Pembuatan sertifikat gagal. Periksa pesan di atas.
exit /b 1
