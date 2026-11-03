@echo off
setlocal enabledelayedexpansion
rem ============================================================================
rem  Build ifx.dll - full direct ifx_* / ifxus_* API over Informix CSDK CLI.
rem
rem  This extension is NOT the production ifx_pdo.dll. It has the same PHP
rem  function names but a different backend, so load only one of the two.
rem
rem  Usage:
rem      build-ifx.bat [devel-pack-dir] [output-dll] [csdk-dir]
rem
rem  Default:
rem      devel pack: C:\php-devel\devel\php-8.5.11-devel-vs17-x64
rem      output:     ifx.dll
rem      csdk:       C:\IBM\Informix_Client_SDK
rem ============================================================================

set "DEVEL=%~1"
if "%DEVEL%"=="" set "DEVEL=C:\php-devel\devel\php-8.5.11-devel-vs17-x64"
set "OUTDLL=%~2"
if "%OUTDLL%"=="" set "OUTDLL=ifx.dll"
set "CSDK=%~3"
if "%CSDK%"=="" set "CSDK=C:\IBM\Informix_Client_SDK"

for %%I in ("%DEVEL%") do set "DEVEL=%%~fI"
for %%I in ("%CSDK%") do set "CSDK=%%~fI"
set "HERE=%~dp0"
set "WORK=%TEMP%\ifx_build"

if not exist "%DEVEL%\phpize.bat" (
    echo ERROR: PHP devel pack not found at "%DEVEL%"
    exit /b 1
)
if not exist "%CSDK%\incl\cli\infxcli.h" (
    echo ERROR: Informix Client SDK headers not found at "%CSDK%\incl\cli"
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
copy /y "%HERE%ifx.c" "%WORK%" >nul || exit /b 1

> "%WORK%\config.h" (
    echo #ifndef PHP_EXT_CONFIG_H
    echo #define PHP_EXT_CONFIG_H
    echo #include "main/config.w32.h"
    echo #define SIZEOF_LONG_LONG 8
    echo #define COMPILE_DL_IFX 1
    echo #define HAVE_IFX 1
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

set "OUTPATH=%HERE%%OUTDLL%"
if "%OUTDLL:~1,1%"==":" set "OUTPATH=%OUTDLL%"

cd /d "%WORK%"
cl /nologo /LD /MD /O2 /W3 /DNDEBUG /DHAVE_CONFIG_H /DCOMPILE_DL_IFX=1 /DZEND_DEBUG=0 ^
   /DZEND_WIN32 /DPHP_WIN32 /D_CRT_SECURE_NO_WARNINGS /D_CRT_NONSTDC_NO_DEPRECATE !ZTSFLAG! ^
   /I"%WORK%" /I"%DEVEL%\include" /I"%DEVEL%\include\main" /I"%DEVEL%\include\Zend" ^
   /I"%DEVEL%\include\TSRM" /I"%DEVEL%\include\win32" /I"%DEVEL%\include\ext" ^
   /I"%CSDK%\incl\cli" ^
   ifx.c ^
   /link /DLL /OUT:"%OUTPATH%" ^
   /LIBPATH:"%DEVEL%\lib" !PHPLIB! ^
   /LIBPATH:"%CSDK%\lib" iclit09b.lib
if errorlevel 1 (
    echo ERROR: build failed
    exit /b 1
)

echo Built: %OUTPATH%
exit /b 0
