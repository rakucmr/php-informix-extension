#Requires -Version 5.1
<#
.SYNOPSIS
    Rulează suita de integrare ifx-compat pe Windows.

.DESCRIPTION
    Setează mediul Informix Client SDK, găsește un PHP pe care se poate
    încărca pdo_informix și rulează tests/run-tests.php.

.PARAMETER Php
    Calea către php.exe. Dacă lipsește, scriptul încearcă IFX_PHP, apoi
    build-urile PHP 8.5 din folderul local, apoi php din PATH.

.PARAMETER ExtensionDir
    Directorul cu php_pdo_informix.dll. Implicit: IFX_PDO_EXTENSION_DIR sau
    C:\path\to\pdo_informix\extension.

.PARAMETER InformixDir
    Directorul Client SDK. Implicit: INFORMIXDIR sau
    C:\IBM\Informix_Client_SDK.

.PARAMETER Server
    Numele serverului Informix. Implicit: INFORMIXSERVER.

.PARAMETER SqlHosts
    Fișierul sqlhosts. Implicit: INFORMIXSQLHOSTS sau
    C:\path\to\sqlhosts.

.PARAMETER Dsn
    DSN pentru teste ("baza@server" sau "informix:..."). Implicit:
    IFX_TEST_DSN.

.PARAMETER Filter
    Rulează doar fișierele care conțin textul dat (ex. "05" sau "lobs").

.EXAMPLE
    .\tools\test.ps1

.EXAMPLE
    .\tools\test.ps1 -Filter 05 -Php 'C:\php\php-8.5.11-nts-Win32-vs17-x64\php.exe'
#>
[CmdletBinding()]
param(
    [string]$Php = $env:IFX_PHP,
    [string]$ExtensionDir = $env:IFX_PDO_EXTENSION_DIR,
    [string]$PdoDll = 'php_pdo_informix',
    [string]$InformixDir = $env:INFORMIXDIR,
    [string]$Server = $env:INFORMIXSERVER,
    [string]$SqlHosts = $env:INFORMIXSQLHOSTS,
    [string]$Dsn = $env:IFX_TEST_DSN,
    [string]$User = $env:IFX_TEST_USER,
    [string]$Password = $env:IFX_TEST_PASSWORD,
    [string]$Filter
)

$ErrorActionPreference = 'Stop'

$root = Split-Path $PSScriptRoot -Parent
$runner = Join-Path $root 'tests\run-tests.php'
if (-not (Test-Path $runner)) {
    throw "Nu găsesc $runner"
}
if (-not (Test-Path $ExtensionDir)) {
    throw "Directorul de extensii nu există: $ExtensionDir. Treceți -ExtensionDir."
}
if (-not (Test-Path (Join-Path $InformixDir 'bin'))) {
    throw "INFORMIXDIR nu pare valid: $InformixDir"
}

# Candidate PHP executables, în ordinea preferinței.
$candidates = [System.Collections.Generic.List[string]]::new()
if ($Php) {
    $candidates.Add($Php)
} else {
    $fromPath = Get-Command php -ErrorAction SilentlyContinue
    if ($fromPath) {
        $candidates.Add($fromPath.Source)
    }
    foreach ($base in @('C:\php')) {
        if (Test-Path $base) {
            Get-ChildItem $base -Directory -Filter 'php-8.5*' -ErrorAction SilentlyContinue |
                Sort-Object Name -Descending |
                ForEach-Object { $candidates.Add((Join-Path $_.FullName 'php.exe')) }
        }
    }
}

$probe = "echo extension_loaded('pdo_informix') ? '1' : '0';"
$selected = $null
foreach ($candidate in ($candidates | Select-Object -Unique)) {
    if (-not (Test-Path $candidate)) {
        continue
    }
    $result = & $candidate -n -d "extension_dir=$ExtensionDir" -d "extension=$PdoDll" -r $probe 2>$null
    if ($LASTEXITCODE -eq 0 -and ($result -join '') -eq '1') {
        $selected = $candidate
        break
    }
}

if (-not $selected) {
    throw @"
Nu am găsit un PHP pe care să se încarce pdo_informix din $ExtensionDir.

Verificați:
  - că DLL-ul corespunde versiunii PHP (ABI, NTS/TS, php7/php8);
  - că %INFORMIXDIR%\bin este în PATH;
  - că ați trecut -Php către un php.exe compatibil.

Candidați încercați:
$($candidates | Select-Object -Unique | ForEach-Object { "  - $_" } | Out-String)
"@
}

$env:INFORMIXDIR = $InformixDir
$env:INFORMIXSERVER = $Server
if ($SqlHosts -and (Test-Path $SqlHosts)) {
    $env:INFORMIXSQLHOSTS = $SqlHosts
} elseif ($SqlHosts) {
    Write-Warning "sqlhosts nu există: $SqlHosts (continui fără INFORMIXSQLHOSTS)"
}
$env:PATH = (Join-Path $InformixDir 'bin') + ';' + $env:PATH

if (-not $Server -or -not $Dsn -or -not $User -or -not $Password) {
    throw "Setați INFORMIXSERVER, IFX_TEST_DSN, IFX_TEST_USER și IFX_TEST_PASSWORD."
}
$env:IFX_TEST_DSN = $Dsn
$env:IFX_TEST_USER = $User
$env:IFX_TEST_PASSWORD = $Password

Write-Host "PHP:            $selected"
Write-Host "pdo_informix:   $ExtensionDir\$PdoDll"
Write-Host "INFORMIXDIR:    $InformixDir"
Write-Host "INFORMIXSERVER: $Server"
Write-Host "DSN teste:      $Dsn"
if ($Filter) {
    Write-Host "Filtru:         $Filter"
}
Write-Host ''

$arguments = @(
    '-n',
    '-d', "extension_dir=$ExtensionDir",
    '-d', "extension=$PdoDll",
    $runner
)
if ($Filter) {
    $arguments += "--filter=$Filter"
}

& $selected @arguments
exit $LASTEXITCODE
