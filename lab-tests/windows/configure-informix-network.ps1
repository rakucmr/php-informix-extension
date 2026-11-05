#Requires -Version 5.1
<#
    Configure Informix IDS to listen on all IPv4 interfaces, while Windows
    Firewall allows TCP 9088 only from:
      - the Hyper-V Default Switch subnet: <vm-subnet>
      - the physical LAN subnet:            <lan-subnet>

    Run this script from an elevated PowerShell ("Run as administrator").

    It backs up sqlhosts before changing it. If the IDS service does not
    come back, restore the backup and restart the service manually.

    Note: "blocked from the Internet" relies on:
      1. the router not forwarding TCP 9088 to this host;
      2. Windows Firewall default inbound block + the scoped allow rules below.
    Do not add a broad allow rule for TCP 9088.
#>

$ErrorActionPreference = 'Stop'

$principal = New-Object Security.Principal.WindowsPrincipal(
    [Security.Principal.WindowsIdentity]::GetCurrent()
)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this script from an elevated PowerShell window (Run as administrator).'
}

# Example values. Replace them with the local paths/names before running.
$SqlHosts = '<path-to-sqlhosts>'
$ServiceName = '<service-name>'
$BindHost = '0.0.0.0'

$VmSubnet = '<vm-subnet>'
$LanSubnet = '<lan-subnet>'

$vmRule = 'Informix IDS 9088 - Hyper-V VMs'
$lanRule = 'Informix IDS 9088 - LAN'

if (-not (Test-Path -LiteralPath $SqlHosts)) {
    throw "sqlhosts not found: $SqlHosts"
}

$backup = "$SqlHosts.bak-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
Copy-Item -LiteralPath $SqlHosts -Destination $backup -Force
Write-Host "Backup: $backup"

$escapedService = [regex]::Escape($ServiceName)
$lines = Get-Content -LiteralPath $SqlHosts
$changed = $false
$newLines = foreach ($line in $lines) {
    if ($line -match "^\s*$escapedService\s+olsoctcp\s+\S+\s+$escapedService\s*$") {
        $changed = $true
        "$ServiceName olsoctcp $BindHost $ServiceName"
    } else {
        $line
    }
}

if (-not $changed) {
    throw "The expected '$ServiceName olsoctcp ... $ServiceName' line was not found. Nothing changed."
}

$newLines | Set-Content -LiteralPath $SqlHosts -Encoding ASCII
Write-Host "Changed sqlhosts entry to: $ServiceName olsoctcp $BindHost $ServiceName"

try {
    Write-Host "Restarting service $ServiceName ..."
    Restart-Service -Name $ServiceName -Force
    Start-Sleep -Seconds 8

    $svc = Get-Service -Name $ServiceName
    if ($svc.Status -ne 'Running') {
        throw "Service $ServiceName is $($svc.Status), not Running."
    }
    Write-Host "Service $ServiceName is Running."
} catch {
    Write-Warning "IDS did not restart correctly: $($_.Exception.Message)"
    Write-Warning "Restoring $backup ..."
    Copy-Item -LiteralPath $backup -Destination $SqlHosts -Force
    Restart-Service -Name $ServiceName -Force -ErrorAction SilentlyContinue
    throw
}

Write-Host ''
Write-Host 'Listener check:'
netstat -ano | Select-String ':9088'

Write-Host ''
Write-Host 'Configuring Windows Firewall...'

Get-NetFirewallRule -DisplayName $vmRule, $lanRule -ErrorAction SilentlyContinue |
    Remove-NetFirewallRule -ErrorAction SilentlyContinue

New-NetFirewallRule `
    -DisplayName $vmRule `
    -Description 'Informix IDS TCP 9088 from Hyper-V lab VMs' `
    -Direction Inbound `
    -Action Allow `
    -Protocol TCP `
    -LocalPort 9088 `
    -RemoteAddress $VmSubnet `
    -Profile Any | Out-Null

New-NetFirewallRule `
    -DisplayName $lanRule `
    -Description 'Informix IDS TCP 9088 from the local LAN' `
    -Direction Inbound `
    -Action Allow `
    -Protocol TCP `
    -LocalPort 9088 `
    -RemoteAddress $LanSubnet `
    -Profile Any | Out-Null

Write-Host "Allowed sources: $VmSubnet and $LanSubnet"
Write-Host ''
Write-Host 'Rules:'
Get-NetFirewallRule -DisplayName $vmRule, $lanRule |
    Select-Object DisplayName, Enabled, Direction, Action, Profile |
    Format-Table -AutoSize

Write-Host ''
Write-Host 'Firewall profiles:'
Get-NetFirewallProfile |
    Select-Object Name, Enabled, DefaultInboundAction |
    Format-Table -AutoSize

$disabledProfiles = Get-NetFirewallProfile | Where-Object { -not $_.Enabled }
if ($disabledProfiles) {
    Write-Warning 'One or more firewall profiles are disabled. The scoped rules below do not filter those profiles.'
    Write-Warning 'Enable the relevant profile(s) before relying on this configuration.'
}

Write-Host ''
Write-Host 'Important: inspect existing inbound rules for TCP 9088.'
Write-Host 'A broad existing allow rule can override this scoped policy.'
Write-Host ''
Write-Host 'Test from a VM:'
Write-Host '  timeout 3 bash -c "</dev/tcp/<informix-host>/9088" && echo open || echo closed'
Write-Host ''
Write-Host 'Test from the LAN (should be open):'
Write-Host '  Test-NetConnection <lan-ip> -Port 9088'
Write-Host ''
Write-Host 'Rollback:'
Write-Host "  Copy-Item '$backup' '$SqlHosts' -Force"
Write-Host "  Restart-Service $ServiceName -Force"
