param(
    [string]$Php = $(if ($env:IFX_PHP) { $env:IFX_PHP } else { 'php' }),
    [string]$ExtensionDir = $env:IFX_PDO_EXTENSION_DIR,
    [string]$PdoDll = 'php_pdo_informix',
    [string]$InformixDir = $env:INFORMIXDIR,
    [string]$Server = $env:INFORMIXSERVER,
    [string]$SqlHosts = $env:INFORMIXSQLHOSTS,
    [string]$Dsn = $env:IFX_TEST_DSN,
    [string]$PdoDsn = $env:IFX_TEST_PDO_DSN,
    [string]$User = $env:IFX_TEST_USER,
    [string]$Password = $env:IFX_TEST_PASSWORD,
    [string]$Dll = (Join-Path $PSScriptRoot '..\ifx_pdo.dll'),
    [string]$Devel = $env:IFX_PHP_DEVEL,
    [switch]$Build
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $Php)) {
    throw "PHP nu a fost găsit: $Php"
}
if (-not $ExtensionDir -or -not (Test-Path -LiteralPath $ExtensionDir)) {
    throw "Setați IFX_PDO_EXTENSION_DIR către directorul cu php_pdo_informix."
}
if (-not $InformixDir -or -not (Test-Path -LiteralPath $InformixDir)) {
    throw "Setați INFORMIXDIR către Informix Client SDK."
}
if (-not $Server -or -not $SqlHosts -or -not $Dsn -or -not $PdoDsn -or $null -eq $User -or $null -eq $Password) {
    throw "Setați INFORMIXSERVER, INFORMIXSQLHOSTS, IFX_TEST_DSN, IFX_TEST_PDO_DSN, IFX_TEST_USER și IFX_TEST_PASSWORD."
}

$env:INFORMIXDIR = $InformixDir
$env:INFORMIXSERVER = $Server
$env:INFORMIXSQLHOSTS = $SqlHosts
$env:IFX_TEST_DSN = $Dsn
$env:IFX_TEST_PDO_DSN = $PdoDsn
$env:IFX_TEST_USER = $User
$env:IFX_TEST_PASSWORD = $Password
$env:PATH = (Join-Path $InformixDir 'bin') + ';' + $env:PATH

if ($Build) {
    & (Join-Path $PSScriptRoot '..\build-ifx.bat') $Devel (Split-Path -Leaf $Dll)
    if ($LASTEXITCODE -ne 0) {
        throw "Compilarea a eșuat."
    }
}

if (-not (Test-Path -LiteralPath $Dll)) {
    throw "ifx_pdo.dll nu există: $Dll (rulați cu -Build)"
}

$suite = Join-Path $PSScriptRoot 'native-suite.php'
Write-Host "PHP:     $Php"
Write-Host "pdo:     $ExtensionDir\$PdoDll"
Write-Host "ifx_pdo: $Dll"
Write-Host "baza:    $Dsn"
Write-Host ''

& $Php -n `
    -d "extension_dir=$ExtensionDir" `
    -d "extension=$PdoDll" `
    -d "extension=$Dll" `
    $suite

exit $LASTEXITCODE
