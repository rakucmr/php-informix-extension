@echo off
setlocal enabledelayedexpansion
rem ============================================================================
rem  Build php_pdo_informix.dll for PHP 8.5.x (NTS, x64, VS17) on Windows.
rem
rem  Usage:
rem     build.bat [devel-pack-dir] [csdk-dir] [output-dll] [dm]
'
rem
rem  Defaults:
rem     devel pack : C:\php-devel\devel\php-8.5.11-devel-vs17-x64
rem     CSDK       : C:\IBM\Informix_Client_SDK
rem
rem  Requirements:
rem     - Visual Studio 2022 with the "Desktop development with C++" workload
rem     - PHP devel pack matching the target PHP (php-devel-pack-8.5.x-nts-Win32-vs17-x64)
rem     - Informix Client SDK (headers in incl\cli + incl\esql, iclit09b.lib in lib)
rem ============================================================================

set "DEVEL=%~1"
if "%DEVEL%"=="" set "DEVEL=C:\php-devel\devel\php-8.5.11-devel-vs17-x64"
set "CSDK=%~2"
if "%CSDK%"=="" set "CSDK=C:\IBM\Informix_Client_SDK"
set "OUTDLL=%~3"
if "%OUTDLL%"=="" set "OUTDLL=php_pdo_informix.dll"
set "USEDM=%~4"

for %%I in ("%DEVEL%") do set "DEVEL=%%~fI"
for %%I in ("%CSDK%") do set "CSDK=%%~fI"

set "HERE=%~dp0"
set "SRC=%HERE%..\src"
set "WORK=%TEMP%\pdo_informix_build"

if not exist "%DEVEL%\phpize.bat" (
    echo ERROR: PHP devel pack not found at "%DEVEL%"
    exit /b 1
)
if "%USEDM%"=="dm" (
    set "LINKLIBS=odbc32.lib odbccp32.lib"
    set "DMFLAG=/DPDO_INFORMIX_USE_ODBC_DM=1"
) else (
    if not exist "%CSDK%\lib\iclit09b.lib" (
        echo ERROR: iclit09b.lib not found under "%CSDK%\lib"
        exit /b 1
    )
    set "LINKLIBS=iclit09b.lib"
    set "DMFLAG="
)
if not exist "%CSDK%\incl\cli\infxcli.h" (
    echo ERROR: infxcli.h not found under "%CSDK%\incl\cli"
    exit /b 1
)

rem --- locate Visual Studio -------------------------------------------------
set "VSWHERE=%ProgramFiles(x86)%\Microsoft Visual Studio\Installer\vswhere.exe"
set "VSDIR="
if exist "%VSWHERE%" (
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

rem --- stage sources --------------------------------------------------------
if exist "%WORK%" rmdir /s /q "%WORK%"
mkdir "%WORK%" || exit /b 1
copy /y "%SRC%\*.c" "%WORK%" >nul || exit /b 1
copy /y "%SRC%\*.h" "%WORK%" >nul || exit /b 1

> "%WORK%\config.h" (
    echo #ifndef PHP_EXT_CONFIG_H
    echo #define PHP_EXT_CONFIG_H
    echo #include "main/config.w32.h"
    echo #define COMPILE_DL_PDO_INFORMIX 1
    echo #define HAVE_PDO_INFORMIX 1
    echo #endif
)

rem --- compile + link -------------------------------------------------------
cd /d "%WORK%"
cl /nologo /LD /MD /O2 /W3 /DNDEBUG /DHAVE_CONFIG_H /DCOMPILE_DL_PDO_INFORMIX=1 /DZEND_DEBUG=0 ^
   /DZEND_WIN32 /DPHP_WIN32 /D_CRT_SECURE_NO_WARNINGS /D_CRT_NONSTDC_NO_DEPRECATE ^
   /I"%WORK%" /I"%DEVEL%\include" /I"%DEVEL%\include\main" /I"%DEVEL%\include\Zend" ^
   /I"%DEVEL%\include\TSRM" /I"%DEVEL%\include\win32" /I"%DEVEL%\include\ext" ^
   /I"%CSDK%\incl\cli" /I"%CSDK%\incl\esql" !DMFLAG! ^
   pdo_informix.c informix_driver.c informix_statement.c ^
   /link /DLL /OUT:"%HERE%%OUTDLL%" ^
   /LIBPATH:"%DEVEL%\lib" php8.lib /LIBPATH:"%CSDK%\lib" !LINKLIBS!
if errorlevel 1 (
    echo ERROR: build failed
    exit /b 1
)

copy /y "%WORK%\pdo_informix.lib" "%HERE%pdo_informix.lib" >nul 2>&1

echo.
echo Built: %HERE%%OUTDLL%
exit /b 0


