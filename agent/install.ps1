<#
.SYNOPSIS
    Installiert den HSGMonitorLight-Agent auf diesem Rechner (als Administrator ausführen).

.DESCRIPTION
    1. Kopiert den Agent nach "C:\Program Files\HSGMonitorLight" – dort dürfen nur
       Administratoren schreiben. Wichtig, weil der Agent als SYSTEM läuft.
    2. Schreibt Server-Adresse und Token nach "C:\ProgramData\HSGMonitorLight\config.json";
       den Ordner dürfen nur SYSTEM und Administratoren lesen.
    3. Legt die geplante Aufgabe "HSGMonitorLight" an (Systemstart, neue Netzwerkverbindung, stündlich –
       auch ohne Netzwerk, damit der Agent Offline-Zeiten vormerken kann).
    4. Startet die Aufgabe einmal und zeigt, ob die Meldung angekommen ist.
    Erneut ausführen = Agent aktualisieren. Ohne -Url/-Token gelten dann die bisherigen Werte.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\install.ps1 -Url "https://monitor.example.de/api/report.php" -Token "…"

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\install.ps1
    Aktualisiert einen schon installierten Agent und behält Adresse und Token.
#>
[CmdletBinding()]
param(
    [string]$Url,
    [string]$Token,
    # Den Namen des angemeldeten Kontos nicht mitsenden
    [switch]$NoUserName
)

$ErrorActionPreference = 'Stop'

$TaskName    = 'HSGMonitorLight'
$ProgramDir  = Join-Path $env:ProgramFiles 'HSGMonitorLight'
$DataDir     = Join-Path $env:ProgramData 'HSGMonitorLight'
$AgentTarget = Join-Path $ProgramDir 'HSGMonitorLight.ps1'

function Stop-WithError([string]$Message) {
    Write-Host $Message -ForegroundColor Red
    exit 1
}

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Stop-WithError 'Bitte als Administrator ausführen (Rechtsklick auf PowerShell > "Als Administrator ausführen").'
}

$existingConfig = Join-Path $DataDir 'config.json'
if ((-not $Url -or -not $Token) -and (Test-Path -LiteralPath $existingConfig)) {
    $previous = Get-Content -LiteralPath $existingConfig -Raw -Encoding UTF8 | ConvertFrom-Json
    if (-not $Url) { $Url = [string]$previous.url }
    if (-not $Token) { $Token = [string]$previous.token }
    Write-Host 'Übernehme Server-Adresse und Token aus der bisherigen Installation.'
}
if (-not $Url -or -not $Token) {
    Stop-WithError 'Bitte -Url und -Token angeben. Der fertige Befehl steht in der Geräteverwaltung nach "Anlegen" bzw. "Token erneuern".'
}

$uri = $null
if (-not [Uri]::TryCreate($Url, [UriKind]::Absolute, [ref]$uri) -or
    ($uri.Scheme -ne 'https' -and $uri.Host -notin 'localhost', '127.0.0.1')) {
    Stop-WithError "Die Server-Adresse muss mit https:// beginnen: $Url"
}
if ($Token -notmatch '^[A-Za-z0-9_-]{32,128}$') {
    Stop-WithError 'Der Token hat nicht das erwartete Format. Bitte den Befehl aus der Geräteverwaltung vollständig kopieren.'
}

# 1. Agent
Write-Host 'Kopiere Agent ...'
New-Item -ItemType Directory -Path $ProgramDir -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'HSGMonitorLight.ps1') -Destination $AgentTarget -Force
Unblock-File -LiteralPath $AgentTarget

# 2. Konfiguration – Ordner nur für SYSTEM (S-1-5-18) und Administratoren (S-1-5-32-544)
Write-Host 'Schreibe Konfiguration ...'
New-Item -ItemType Directory -Path $DataDir -Force | Out-Null
& icacls.exe $DataDir /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { Stop-WithError "Die Rechte für $DataDir konnten nicht gesetzt werden." }
$config = [ordered]@{ url = $Url; token = $Token; report_user = (-not $NoUserName) } | ConvertTo-Json
[IO.File]::WriteAllText((Join-Path $DataDir 'config.json'), $config, (New-Object Text.UTF8Encoding($false)))

# 3. Geplante Aufgabe
Write-Host 'Richte geplante Aufgabe ein ...'
$command = [Security.SecurityElement]::Escape("$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe")
$arguments = [Security.SecurityElement]::Escape("-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$AgentTarget`"")
$start = (Get-Date).ToString('yyyy-MM-ddTHH:mm:ss')
$xml = @"
<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.4" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo>
    <Author>HSGMonitorLight</Author>
    <Description>Meldet Online-Status und Windows-Patch-Stand an den HSGMonitorLight-Server.</Description>
  </RegistrationInfo>
  <Triggers>
    <BootTrigger>
      <Enabled>true</Enabled>
      <Delay>PT2M</Delay>
    </BootTrigger>
    <EventTrigger>
      <Enabled>true</Enabled>
      <Delay>PT1M</Delay>
      <Subscription>&lt;QueryList&gt;&lt;Query Id="0" Path="Microsoft-Windows-NetworkProfile/Operational"&gt;&lt;Select Path="Microsoft-Windows-NetworkProfile/Operational"&gt;*[System[(EventID=10000)]]&lt;/Select&gt;&lt;/Query&gt;&lt;/QueryList&gt;</Subscription>
    </EventTrigger>
    <TimeTrigger>
      <Enabled>true</Enabled>
      <StartBoundary>$start</StartBoundary>
      <Repetition>
        <Interval>PT1H</Interval>
        <StopAtDurationEnd>false</StopAtDurationEnd>
      </Repetition>
      <RandomDelay>PT5M</RandomDelay>
    </TimeTrigger>
  </Triggers>
  <Principals>
    <Principal id="Author">
      <UserId>S-1-5-18</UserId>
      <RunLevel>HighestAvailable</RunLevel>
    </Principal>
  </Principals>
  <Settings>
    <MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>
    <DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>
    <StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>
    <AllowHardTerminate>true</AllowHardTerminate>
    <StartWhenAvailable>true</StartWhenAvailable>
    <RunOnlyIfNetworkAvailable>false</RunOnlyIfNetworkAvailable>
    <IdleSettings>
      <StopOnIdleEnd>false</StopOnIdleEnd>
      <RestartOnIdle>false</RestartOnIdle>
    </IdleSettings>
    <AllowStartOnDemand>true</AllowStartOnDemand>
    <Enabled>true</Enabled>
    <Hidden>false</Hidden>
    <RunOnlyIfIdle>false</RunOnlyIfIdle>
    <WakeToRun>false</WakeToRun>
    <ExecutionTimeLimit>PT10M</ExecutionTimeLimit>
    <Priority>7</Priority>
  </Settings>
  <Actions Context="Author">
    <Exec>
      <Command>$command</Command>
      <Arguments>$arguments</Arguments>
    </Exec>
  </Actions>
</Task>
"@
Register-ScheduledTask -TaskName $TaskName -Xml $xml -Force | Out-Null

# 4. Testlauf über die Aufgabe, also wirklich als SYSTEM
Write-Host 'Sende Testmeldung (kann bis zu einigen Minuten dauern) ' -NoNewline
$startedAt = (Get-Date).AddSeconds(-2)
Start-ScheduledTask -TaskName $TaskName
$deadline = (Get-Date).AddMinutes(6)
do {
    Start-Sleep -Seconds 3
    Write-Host '.' -NoNewline
    $state = (Get-ScheduledTask -TaskName $TaskName).State
    $info = Get-ScheduledTaskInfo -TaskName $TaskName
} while (($state -eq 'Running' -or $info.LastRunTime -lt $startedAt) -and (Get-Date) -lt $deadline)
Write-Host ''

$log = Join-Path $DataDir 'agent.log'
if ($info.LastTaskResult -eq 0) {
    Write-Host 'Fertig: Die Meldung ist beim Server angekommen.' -ForegroundColor Green
} else {
    Write-Host ("Die Testmeldung hat nicht geklappt (Ergebnis {0})." -f $info.LastTaskResult) -ForegroundColor Yellow
    Write-Host 'Die Aufgabe ist trotzdem eingerichtet und versucht es beim nächsten Anlass erneut.'
}
if (Test-Path -LiteralPath $log) {
    Write-Host "`nLetzte Einträge aus $($log):"
    Get-Content -LiteralPath $log -Tail 5 | ForEach-Object { Write-Host "  $_" }
}
