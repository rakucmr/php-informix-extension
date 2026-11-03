@echo off
setlocal enabledelayedexpansion
rem ============================================================================
rem  Build ifx_pdo.dll (extensia C ifx_pdo, peste PDO) pentru PHP 7.0-8.5 x64.
rem  Alege automat php7.lib/php8.lib (NTS) sau php7ts.lib/php8ts.lib (TS).
rem
rem  Usage:
rem      build-ifx.bat [devel-pack-dir] [output-dll]
rem
rem  Default:
rem      devel pack: C:\php-devel\devel\php-8.5.11-devel-vs17-x64
rem ============================================================================

set "DEVEL=%~1"
if "%DEVEL%"=="" set "DEVEL=C:\php-devel\devel\php-8.5.11-devel-vs17-x64"
set "OUTDLL=%~2"
if "%OUTDLL%"=="" set "OUTDLL=ifx_pdo.dll"

for %%I in ("%DEVEL%") do set "DEVEL=%%~fI"
set "HERE=%~dp0"
set "WORK=%TEMP%\ifx_pdo_build"

if not exist "%DEVEL%\phpize.bat" (
    echo ERROR: PHP devel pack not found at "%DEVEL%"
    exit /b 1
)

set "VSWHERE=%ProgramFiles(x86)%\Microsoft Visual Studio\Installer\vswhere.exe"
set "VSDIR=%PHP_IFX_VS%"
if not defined VSDIR if exist "%VSWHERE%" (
    for /f "usebackq tokens=*" %%i in (`"%VSWHERE%" -latest -products * -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath`) do set "VSDIR=%%i"
)
if not defined VSDIR (
    if exist "%ProgramFiles%\Microsoft Visual Studio\2022\Preview\VC\Auxiliary\Build\vcvars64.bat" set "VSDIR=%ProgramFiles%\Microsoft Visual Studio\2022\Preview"
)
if not defined VSDIR (
    echo ERROR: Visual Studio C++ tools not found.
    exit /b 1
)

call "%VSDIR%\VC\Auxiliary\Build\vcvars64.bat" >nul
if errorlevel 1 (
    echo ERROR: vcvars64.bat failed
    exit /b 1
)

if exist "%WORK%" rmdir /s /q "%WORK%"
mkdir "%WORK%" || exit /b 1
copy /y "%HERE%*.c" "%WORK%" >nul || exit /b 1
copy /y "%HERE%*.h" "%WORK%" >nul || exit /b 1

> "%WORK%\config.h" (
    echo #ifndef PHP_EXT_CONFIG_H
    echo #define PHP_EXT_CONFIG_H
    echo #include "main/config.w32.h"
    echo #define SIZEOF_LONG_LONG 8
    echo #define COMPILE_DL_IFX_PDO 1
    echo #define HAVE_IFX_PDO 1
    echo #endif
)

set "PHPLIB=php8.lib"
set "ZTSFLAG="
if not exist "%DEVEL%\lib\php8.lib" (
    if exist "%DEVEL%\lib\php7.lib" (
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
)

cd /d "%WORK%"
cl /nologo /LD /MD /O2 /W3 /DNDEBUG /DHAVE_CONFIG_H /DCOMPILE_DL_IFX_PDO=1 /DZEND_DEBUG=0 ^
   /DZEND_WIN32 /DPHP_WIN32 /D_CRT_SECURE_NO_WARNINGS /D_CRT_NONSTDC_NO_DEPRECATE !ZTSFLAG! ^
   /I"%WORK%" /I"%DEVEL%\include" /I"%DEVEL%\include\main" /I"%DEVEL%\include\Zend" ^
   /I"%DEVEL%\include\TSRM" /I"%DEVEL%\include\win32" /I"%DEVEL%\include\ext" ^
   ifx.c ifx_php.c /link /DLL /OUT:"%HERE%%OUTDLL%" ^
   /LIBPATH:"%DEVEL%\lib" !PHPLIB!
if errorlevel 1 (
    echo ERROR: build failed
    exit /b 1
)

echo.
echo Built: %HERE%%OUTDLL%
exit /b 0
