<#
.SYNOPSIS
    Entfernt den HSGMonitorLight-Agent wieder (als Administrator ausführen).

.PARAMETER KeepData
    Konfiguration und Protokoll in C:\ProgramData\HSGMonitorLight behalten.
#>
[CmdletBinding()]
param([switch]$KeepData)

$ErrorActionPreference = 'Stop'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'Bitte als Administrator ausführen.' -ForegroundColor Red
    exit 1
}

Unregister-ScheduledTask -TaskName 'HSGMonitorLight' -Confirm:$false -ErrorAction SilentlyContinue
Remove-Item -LiteralPath (Join-Path $env:ProgramFiles 'HSGMonitorLight') -Recurse -Force -ErrorAction SilentlyContinue
if (-not $KeepData) {
    Remove-Item -LiteralPath (Join-Path $env:ProgramData 'HSGMonitorLight') -Recurse -Force -ErrorAction SilentlyContinue
}
Write-Host 'HSGMonitorLight wurde entfernt.' -ForegroundColor Green
Write-Host 'Das Gerät kann jetzt in der Geräteverwaltung gelöscht werden.'
