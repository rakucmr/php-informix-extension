param(
    [Parameter(Mandatory = $true)][string]$Path,
    [int]$Major = 14,
    [int]$Minor = 16
)

# PHP verifică versiunea de linker din header-ul PE la încărcarea unui modul.
# Extensiile compilate cu VS2019/VS2022 (14.29/14.41) nu se încarcă în PHP
# 7.0-7.3, compilat cu VS2015/VS2017 (14.0/14.16). Acest script ajustează doar
# câmpurile MajorLinkerVersion/MinorLinkerVersion; runtime-ul CRT rămâne cel
# folosit la compilare.

$full = (Resolve-Path -LiteralPath $Path).Path
$bytes = [System.IO.File]::ReadAllBytes($full)
$peOffset = [BitConverter]::ToInt32($bytes, 0x3C)
$optionalHeader = $peOffset + 4 + 20
if ($bytes[$peOffset] -ne 0x50 -or $bytes[$peOffset + 1] -ne 0x45) {
    throw "Nu este un fișier PE valid: $full"
}

$bytes[$optionalHeader + 2] = [byte]$Major
$bytes[$optionalHeader + 3] = [byte]$Minor
[System.IO.File]::WriteAllBytes($full, $bytes)
Write-Host ("LinkerVersion set to {0}.{1}: {2}" -f $Major, $Minor, $full)
