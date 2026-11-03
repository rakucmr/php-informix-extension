param(
    [string]$Php = $(if ($env:IFX_PHP) { $env:IFX_PHP } else { 'php' }),
    [string]$ExtensionDir = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path,
    [string]$InformixDir = $env:INFORMIXDIR,
    [string]$Server = $env:INFORMIXSERVER,
    [string]$SqlHosts = $env:INFORMIXSQLHOSTS,
    [string]$Dsn = $env:IFX_TEST_DSN,
    [string]$LongDsn = $env:IFX_TEST_PDO_DSN,
    [string]$User = $env:IFX_TEST_USER,
    [string]$Password = $env:IFX_TEST_PASSWORD,
    [string]$Dll = (Join-Path $PSScriptRoot '..\ifx.dll'),
    [string]$Devel = $env:IFX_PHP_DEVEL,
    [string]$Csdk = $env:IFX_CSDK,
    [switch]$Build
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $Php)) {
    throw "PHP nu a fost găsit: $Php"
}
if (-not (Test-Path -LiteralPath $ExtensionDir)) {
    throw "Directorul de extensii nu există: $ExtensionDir"
}
if (-not $InformixDir -or -not (Test-Path -LiteralPath $InformixDir)) {
    throw "Setați INFORMIXDIR către Informix Client SDK."
}
if (-not $Server -or -not $SqlHosts -or -not $Dsn -or $null -eq $User -or $null -eq $Password) {
    throw "Setați INFORMIXSERVER, INFORMIXSQLHOSTS, IFX_TEST_DSN, IFX_TEST_USER și IFX_TEST_PASSWORD."
}
if (-not $LongDsn) {
    $LongDsn = $Dsn
}

$env:INFORMIXDIR = $InformixDir
$env:INFORMIXSERVER = $Server
$env:INFORMIXSQLHOSTS = $SqlHosts
$env:IFX_TEST_DSN = $Dsn
$env:IFX_TEST_PDO_DSN = $LongDsn
$env:IFX_TEST_USER = $User
$env:IFX_TEST_PASSWORD = $Password
# PHP 7.0's putenv() is not visible to the C runtime used by the extension,
# so the no-argument ifx_pconnect() test needs these in the real process env.
$env:IFX_DEFAULT_DATABASE = $Dsn
$env:IFX_DEFAULT_USER = $User
$env:IFX_DEFAULT_PASSWORD = $Password
$env:PATH = (Join-Path $InformixDir 'bin') + ';' + $env:PATH

if ($Build) {
    $leaf = Split-Path -Leaf $Dll
    & (Join-Path $PSScriptRoot '..\build-ifx.bat') $Devel $leaf $Csdk
    if ($LASTEXITCODE -ne 0) {
        throw "Compilarea a eșuat (verificați -Devel și -Csdk)."
    }
    # PHP 7.0-7.4 verifică versiunea de linker din PE. VS2022 scrie 14.29+,
    # deci binarul trebuie marcat cu versiunea linkerului folosit de PHP.
    $Dll = Join-Path $ExtensionDir $leaf
    $versionId = [int](& $Php -n -r 'echo PHP_VERSION_ID;')
    if ($versionId -lt 80000) {
        $minor = if ($versionId -lt 70200) { 0 } else { 16 }
        & (Join-Path $PSScriptRoot '..\..\ifx_pdo_informix\tools\patch-pe-linker.ps1') `
            -Path $Dll -Major 14 -Minor $minor
    }
}

if (-not (Test-Path -LiteralPath $Dll)) {
    throw "ifx.dll nu există: $Dll (rulați cu -Build)"
}

$suite = Join-Path $PSScriptRoot 'native-suite.php'
Write-Host "PHP:    $Php"
Write-Host "ifx:    $Dll"
Write-Host "baza:   $Dsn"
Write-Host ''

& $Php -n `
    -d "extension_dir=$ExtensionDir" `
    -d "extension=$Dll" `
    $suite

exit $LASTEXITCODE
