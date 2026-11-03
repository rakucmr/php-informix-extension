@echo off
setlocal enabledelayedexpansion
rem ============================================================================
rem  Copies the ODBC headers that infxcli.h expects on Windows (sql.h,
rem  sqlext.h, sqltypes.h, sqlucode.h, odbcinst.h) from the installed Windows
rem  SDK into the Informix Client SDK include directory.
rem
rem  Usage: fix-csdk-odbc-headers.bat [csdk-dir]
rem ============================================================================

set "CSDK=%~1"
if "%CSDK%"=="" set "CSDK=C:\IBM\Informix_Client_SDK"
for %%I in ("%CSDK%") do set "CSDK=%%~fI"

if not exist "%CSDK%\incl\cli" (
    echo ERROR: "%CSDK%\incl\cli" not found
    exit /b 1
)

set "KITS=%ProgramFiles(x86)%\Windows Kits\10\Include"
if not exist "%KITS%" (
    echo ERROR: Windows SDK not found at "%KITS%"
    exit /b 1
)

rem pick the newest SDK version directory
set "UM="
for /f "delims=" %%d in ('dir /b /ad /o-n "%KITS%" 2^>nul') do (
    if not defined UM if exist "%KITS%\%%d\um\sql.h" set "UM=%KITS%\%%d\um"
)
if not defined UM (
    echo ERROR: no Windows SDK with um\sql.h found under "%KITS%"
    exit /b 1
)

echo Using headers from: %UM%
for %%h in (sql.h sqlext.h sqltypes.h sqlucode.h odbcinst.h) do (
    if exist "%UM%\%%h" (
        copy /y "%UM%\%%h" "%CSDK%\incl\cli\%%h" >nul && echo   copied %%h
    ) else (
        echo   warning: %%h not found in the Windows SDK
    )
)

echo.
echo Done. Now "%CSDK%\incl\cli" contains the headers required by infxcli.h.
exit /b 0
