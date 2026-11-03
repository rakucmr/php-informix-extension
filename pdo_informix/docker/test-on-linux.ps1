# Construiește imaginea Linux și rulează suita de teste în container,
# conectându-se la serverul Informix configurat prin variabile de mediu.
#
#   pwsh -File docker\test-on-linux.ps1
#   pwsh -File docker\test-on-linux.ps1 -SkipBuild
#   pwsh -File docker\test-on-linux.ps1 -Report        # scrie și în tabela de rezultate
#
# -Report rulează report.php în container după suită. Credențialele nu sunt
# stocate în repository; sunt preluate din PDO_INFORMIX_*.

param(
    [string]$Image = 'pdo-informix-linux',
    [string]$Dsn = $env:PDO_INFORMIX_DSN,
    [string]$User = $env:PDO_INFORMIX_USER,
    [string]$Password = $env:PDO_INFORMIX_PASS,
    [switch]$SkipBuild,
    [switch]$Report
)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent

if (-not $Dsn -or -not $User -or -not $Password) {
    throw 'Setați PDO_INFORMIX_DSN, PDO_INFORMIX_USER și PDO_INFORMIX_PASS.'
}

if (-not $SkipBuild) {
    Write-Host "== docker build ==" -ForegroundColor Cyan
    docker build -f "$root\docker\Dockerfile" -t $Image "$root"
    if ($LASTEXITCODE -ne 0) { throw "docker build failed" }
}

Write-Host "== docker run (tests) ==" -ForegroundColor Cyan
# --add-host fixează host.docker.internal pe IPv4 (gateway-ul Docker Desktop);
# fără el DNS-ul poate întoarce o adresă IPv6, iar CSDK se conectează doar IPv4
# (simptom: -908 Attempt to connect to database server failed).
docker run --rm `
    --add-host host.docker.internal:host-gateway `
    -e "PDO_INFORMIX_DSN=$Dsn" `
    -e "PDO_INFORMIX_REPORT_DSN=$Dsn" `
    -e "PDO_INFORMIX_USER=$User" `
    -e "PDO_INFORMIX_PASS=$Password" `
    -v "${root}\test:/tests" `
    $Image php run-tests.php

if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

if ($Report) {
    Write-Host ""
    Write-Host "== docker run (report) ==" -ForegroundColor Cyan
    docker run --rm `
        --add-host host.docker.internal:host-gateway `
        -e "PDO_INFORMIX_DSN=$Dsn" `
        -e "PDO_INFORMIX_REPORT_DSN=$Dsn" `
        -e "PDO_INFORMIX_USER=$User" `
        -e "PDO_INFORMIX_PASS=$Password" `
        -v "${root}\test:/tests" `
        $Image php report.php
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

exit 0
