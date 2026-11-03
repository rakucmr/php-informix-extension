param(
    [string]$Image = 'pdo-informix-linux:latest',
    [string]$PdoSo = '',
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [string]$OutDir = (Join-Path (Resolve-Path (Join-Path $PSScriptRoot '..')).Path 'linux-build'),
    [string]$SqlHosts = '',
    [string]$Dsn = $env:IFX_TEST_DSN,
    [string]$PdoDsn = $env:IFX_TEST_PDO_DSN,
    [string]$User = $env:IFX_TEST_USER,
    [string]$Password = $env:IFX_TEST_PASSWORD,
    [ValidateSet('both', 'native', 'pure')]
    [string]$Suite = 'both',
    [switch]$Build
)

$ErrorActionPreference = 'Stop'

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker nu este disponibil în PATH.'
}
if (-not (Test-Path -LiteralPath $ProjectRoot)) {
    throw "ProjectRoot nu există: $ProjectRoot"
}
if (-not $Dsn -or -not $PdoDsn -or -not $User -or -not $Password) {
    throw "Setați IFX_TEST_DSN, IFX_TEST_PDO_DSN, IFX_TEST_USER și IFX_TEST_PASSWORD."
}

$extDir = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$pureDir = Join-Path $ProjectRoot 'php_informix'
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

if ([string]::IsNullOrWhiteSpace($SqlHosts)) {
    $SqlHosts = Join-Path $OutDir 'sqlhosts'
}
if (-not (Test-Path -LiteralPath $SqlHosts)) {
    throw "Lipsește fișierul sqlhosts: $SqlHosts. Creați o intrare pentru serverul Informix accesibil din container."
}

$so = Join-Path $OutDir 'ifx_pdo.so'

if ($Build -or -not (Test-Path -LiteralPath $so)) {
    Write-Host "Compilez ifx_pdo.so în $Image ..."
    & docker run --rm `
        -v "${extDir}:/src:ro" `
        -v "${OutDir}:/out" `
        $Image sh -lc '
            set -e
            rm -rf /tmp/ifxbuild && mkdir -p /tmp/ifxbuild
            cp -a /src/. /tmp/ifxbuild/
            cd /tmp/ifxbuild
            phpize
            ./configure --enable-ifx-pdo
            make -j2
            cp modules/ifx_pdo.so /out/ifx_pdo.so
        '
    if ($LASTEXITCODE -ne 0) {
        throw 'Compilarea Linux a eșuat.'
    }
}

if (-not (Test-Path -LiteralPath $so)) {
    throw "Nu există $so."
}

$common = @(
    '--rm',
    '-v', "${OutDir}:/out:ro",
    '-e', 'INFORMIXSQLHOSTS=/out/sqlhosts',
    '-e', "IFX_TEST_DSN=$Dsn",
    '-e', "IFX_TEST_PDO_DSN=$PdoDsn",
    '-e', "IFX_TEST_USER=$User",
    '-e', "IFX_TEST_PASSWORD=$Password"
)

if ($Suite -eq 'both' -or $Suite -eq 'native') {
    Write-Host 'Rulez suita nativă pe Linux ...'
    $nativeArgs = @('php')
    if ($PdoSo) {
        $nativeArgs += @('-d', "extension=$PdoSo")
    }
    $nativeArgs += @('-d', 'extension=/out/ifx_pdo.so', '/tests/native-suite.php')
    & docker run @common `
        -v "${extDir}\tests:/tests:ro" `
        $Image @nativeArgs
    if ($LASTEXITCODE -ne 0) {
        throw 'Suita nativă Linux a eșuat.'
    }
}

if ($Suite -eq 'both' -or $Suite -eq 'pure') {
    Write-Host 'Rulez suita PHP pe Linux ...'
    $pure = 'cd /pure && php'
    if ($PdoSo) {
        $pure += " -d extension=$PdoSo"
    }
    $pure += ' tests/run-tests.php'
    & docker run @common `
        -v "${pureDir}:/pure:ro" `
        $Image sh -lc $pure
    if ($LASTEXITCODE -ne 0) {
        throw 'Suita PHP Linux a eșuat.'
    }
}

Write-Host 'Linux: OK'
