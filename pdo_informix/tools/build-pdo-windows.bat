@echo off
setlocal enabledelayedexpansion
rem ============================================================================
rem  Build pdo_informix.dll for a given PHP devel pack.
rem
rem  Usage:
rem      build-pdo-windows.bat <devel-pack-dir> <output-dll> [source-dir] [csdk-dir]
rem
rem  Example:
rem      build-pdo-windows.bat ^
rem          C:\php-devel\devel\php-7.4.33-devel-vc15-x64 ^
rem          php_pdo_informix_74_ts.dll ^
rem          C:\path\to\pdo_informix\src ^
rem          C:\IBM\Informix_Client_SDK
rem ============================================================================

set "DEVEL=%~1"
set "OUTDLL=%~2"
set "SRC=%~3"
set "CSDK=%~4"
if "%DEVEL%"=="" (
    echo Usage: build-pdo-windows.bat ^<devel-pack^> ^<output-dll^> [source-dir] [csdk-dir]
    exit /b 1
)
if "%OUTDLL%"=="" set "OUTDLL=php_pdo_informix.dll"
if "%SRC%"=="" set "SRC=..\..\pdo_informix\src"
if "%CSDK%"=="" set "CSDK=C:\IBM\Informix_Client_SDK"
for %%I in ("%DEVEL%") do set "DEVEL=%%~fI"
for %%I in ("%SRC%") do set "SRC=%%~fI"
set "HERE=%~dp0"
set "OUTPATH=%HERE%%OUTDLL%"
if "%OUTDLL:~1,1%"==":" set "OUTPATH=%OUTDLL%"
set "WORK=%TEMP%\pdo_informix_build"

if not exist "%DEVEL%\phpize.bat" (
    echo ERROR: PHP devel pack not found at "%DEVEL%"
    exit /b 1
)

set "VSWHERE=%ProgramFiles(x86)%\Microsoft Visual Studio\Installer\vswhere.exe"
set "VSDIR="
if exist "%VSWHERE%" (
    for /f "usebackq tokens=*" %%i in (`"%VSWHERE%" -latest -products * -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath`) do set "VSDIR=%%i"
)
if not defined VSDIR (
    echo ERROR: Visual Studio C++ tools not found.
    exit /b 1
)
call "%VSDIR%\VC\Auxiliary\Build\vcvars64.bat" >nul
if errorlevel 1 exit /b 1

if exist "%WORK%" rmdir /s /q "%WORK%"
mkdir "%WORK%" || exit /b 1
copy /y "%SRC%\*.c" "%WORK%" >nul || exit /b 1
copy /y "%SRC%\*.h" "%WORK%" >nul || exit /b 1
> "%WORK%\config.h" (
    echo #ifndef PHP_EXT_CONFIG_H
    echo #define PHP_EXT_CONFIG_H
    echo #include "main/config.w32.h"
    echo #define SIZEOF_LONG_LONG 8
    echo #define COMPILE_DL_PDO_INFORMIX 1
    echo #define HAVE_PDO_INFORMIX 1
    echo #endif
)

set "PHPLIB="
set "ZTSFLAG="
if exist "%DEVEL%\lib\php8.lib" (
    set "PHPLIB=php8.lib"
) else if exist "%DEVEL%\lib\php7.lib" (
    set "PHPLIB=php7.lib"
) else if exist "%DEVEL%\lib\php7ts.lib" (
    set "PHPLIB=php7ts.lib"
    set "ZTSFLAG=/DZTS=1"
) else if exist "%DEVEL%\lib\php8ts.lib" (
    set "PHPLIB=php8ts.lib"
    set "ZTSFLAG=/DZTS=1"
) else (
    echo ERROR: nu gasesc biblioteca PHP in "%DEVEL%\lib"
    exit /b 1
)

cd /d "%WORK%"
cl /nologo /LD /MD /O2 /W3 /DNDEBUG /DHAVE_CONFIG_H /DCOMPILE_DL_PDO_INFORMIX=1 /DZEND_DEBUG=0 ^
   /DZEND_WIN32 /DPHP_WIN32 /D_CRT_SECURE_NO_WARNINGS /D_CRT_NONSTDC_NO_DEPRECATE !ZTSFLAG! ^
   /I"%WORK%" /I"%DEVEL%\include" /I"%DEVEL%\include\main" /I"%DEVEL%\include\Zend" ^
   /I"%DEVEL%\include\TSRM" /I"%DEVEL%\include\win32" /I"%DEVEL%\include\ext" ^
   /I"%CSDK%\incl\cli" /I"%CSDK%\incl\esql" ^
   pdo_informix.c informix_driver.c informix_statement.c ^
   /link /DLL /OUT:"%OUTPATH%" ^
   /LIBPATH:"%DEVEL%\lib" !PHPLIB! /LIBPATH:"%CSDK%\lib" iclit09b.lib
if errorlevel 1 exit /b 1

echo Built: %OUTPATH%
exit /b 0
