<#
.SYNOPSIS
    HSGMonitorLight-Agent: meldet Online-Status und Windows-Patch-Stand an den Server.

.DESCRIPTION
    Wird von der Aufgabenplanung als SYSTEM gestartet: beim Systemstart, bei jeder neuen
    Netzwerkverbindung und stündlich (eingerichtet von install.ps1). Sammelt ein paar
    Gerätedaten, schickt sie als JSON per HTTPS an den Server und schreibt ein kurzes Protokoll.
    Der Server antwortet nur mit "ok" – Befehle nimmt der Agent keine entgegen.

.PARAMETER ConfigPath
    config.json mit Server-Adresse ("url") und Geräte-Token ("token").

.PARAMETER DryRun
    Nur sammeln und das JSON ausgeben – nichts senden, nichts protokollieren.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\HSGMonitorLight.ps1 -DryRun
#>
[CmdletBinding()]
param(
    [string]$ConfigPath = (Join-Path $env:ProgramData 'HSGMonitorLight\config.json'),
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

$AgentVersion  = '0.1.0'
$SchemaVersion = 1
$Invariant     = [Globalization.CultureInfo]::InvariantCulture
$LogPath       = Join-Path (Split-Path -Parent $ConfigPath) 'agent.log'
$Problems      = New-Object 'System.Collections.Generic.List[string]'
$Config        = $null

# ------------------------------------------------------------------ Hilfsfunktionen

function Write-Log([string]$Message) {
    if ($DryRun) { Write-Host $Message; return }
    try {
        if ((Test-Path -LiteralPath $LogPath) -and (Get-Item -LiteralPath $LogPath).Length -gt 1MB) {
            Move-Item -LiteralPath $LogPath -Destination "$LogPath.old" -Force
        }
        $line = '{0}  {1}' -f (Get-Date).ToString('yyyy-MM-dd HH:mm:ss', $Invariant), $Message
        Add-Content -LiteralPath $LogPath -Value $line -Encoding UTF8
    } catch {
        # Ohne Protokoll weitermachen – die Meldung an den Server ist wichtiger.
    }
}

# Zeitpunkt als UTC im ISO-Format. Die Windows-Update-Schnittstelle liefert UTC ohne
# Kennzeichnung (-AssumeUtc); "nie" kommt dort als Datum vor 2000 an.
function Format-Utc($Value, [switch]$AssumeUtc) {
    if ($Value -isnot [datetime] -or $Value.Year -lt 2000) { return $null }
    if ($AssumeUtc) { $Value = [datetime]::SpecifyKind($Value, [DateTimeKind]::Utc) }
    return $Value.ToUniversalTime().ToString("yyyy-MM-dd'T'HH:mm:ss'Z'", $Invariant)
}

# Führt einen Sammelschritt aus. Scheitert er, fehlt nur dieser Teil in der Meldung.
function Invoke-Collector([string]$Name, [scriptblock]$Script) {
    try {
        return & $Script
    } catch {
        $text = '{0}: {1}' -f $Name, $_.Exception.Message
        $Problems.Add($text)
        Write-Log "Problem bei $text"
        return $null
    }
}

# ------------------------------------------------------------------ Sammeln

function Get-DeviceInfo {
    $cs   = Get-CimInstance -ClassName Win32_ComputerSystem
    $bios = Get-CimInstance -ClassName Win32_BIOS
    $os   = Get-CimInstance -ClassName Win32_OperatingSystem
    $user = $null
    if ($null -eq $Config -or $Config.report_user -ne $false) { $user = $cs.UserName }
    [ordered]@{
        hostname     = $env:COMPUTERNAME
        manufacturer = "$($cs.Manufacturer)".Trim()
        model        = "$($cs.Model)".Trim()
        serial       = "$($bios.SerialNumber)".Trim()
        last_boot    = Format-Utc $os.LastBootUpTime
        user         = $user
    }
}

function Get-WindowsInfo {
    $cv = Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion'
    $build = [int]$cv.CurrentBuild
    $product = [string]$cv.ProductName
    # ProductName sagt auch unter Windows 11 noch "Windows 10" – der Build verrät es.
    if ($build -ge 22000) { $product = $product -replace '^Windows 10', 'Windows 11' }
    [ordered]@{
        product         = $product
        edition         = [string]$cv.EditionID
        display_version = [string]$cv.DisplayVersion
        build           = $build
        ubr             = [int]$cv.UBR
        version         = '{0}.{1}' -f $build, $cv.UBR
    }
}

function Get-UpdateInfo([string]$Version) {
    $searcher = (New-Object -ComObject Microsoft.Update.Session).CreateUpdateSearcher()
    $count = $searcher.GetTotalHistoryCount()
    $entries = New-Object 'System.Collections.Generic.List[object]'
    if ($count -gt 0) {
        foreach ($entry in $searcher.QueryHistory(0, [Math]::Min($count, 300))) {
            # Nur erfolgreiche Installationen (ResultCode 2 = ok, 3 = ok mit Fehlern)
            if ($entry.Operation -ne 1 -or $entry.ResultCode -notin 2, 3) { continue }
            $title = [string]$entry.Title
            # Defender-Signaturen (mehrmals täglich) und Store-Apps sagen nichts über den Patch-Stand
            if ($title -match 'KB2267602' -or "$($entry.ClientApplicationID)" -like 'Acquisition*') { continue }
            $kb = $null
            if ($title -match 'KB(\d{6,8})') { $kb = 'KB' + $Matches[1] }
            $entries.Add([ordered]@{ date = (Format-Utc $entry.Date -AssumeUtc); title = $title; kb = $kb })
        }
    }
    $sorted = @($entries | Sort-Object { $_.date } -Descending)

    # Seit Ende 2025 steht der Build im Titel, z. B. "2026-09 Sicherheitsupdate (KB5129195) (26200.9457)".
    # Der jüngste Treffer ist das Update, das den aktuellen Patch-Stand gebracht hat.
    $current = $null
    if ($Version) {
        foreach ($e in $sorted) { if ($e.title.Contains("($Version)")) { $current = $e; break } }
    }

    $au = (New-Object -ComObject Microsoft.Update.AutoUpdate).Results
    $rebootKey = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired'
    $reboot = (New-Object -ComObject Microsoft.Update.SystemInfo).RebootRequired -or (Test-Path -LiteralPath $rebootKey)

    [ordered]@{
        reboot_required      = [bool]$reboot
        last_search_success  = Format-Utc $au.LastSearchSuccessDate -AssumeUtc
        last_install_success = Format-Utc $au.LastInstallationSuccessDate -AssumeUtc
        current_patch        = $current
        recent               = @($sorted | Select-Object -First 15)
    }
}

function Get-NetworkInfo {
    $list = New-Object 'System.Collections.Generic.List[object]'
    # Der Profilname ist bei WLAN die SSID. netsh wlan bräuchte ab Windows 11 24H2 die Standortfreigabe.
    foreach ($conn in @(Get-NetConnectionProfile -ErrorAction SilentlyContinue)) {
        $index   = $conn.InterfaceIndex
        $adapter = Get-NetAdapter -InterfaceIndex $index -ErrorAction SilentlyContinue
        $media   = "$($adapter.PhysicalMediaType)"
        $type    = 'andere'
        if ($media -match '802\.11') { $type = 'wlan' }
        elseif ($media -match '802\.3') { $type = 'lan' }
        elseif ($media -match 'Wireless WAN') { $type = 'mobil' }
        $ipv4 = @(Get-NetIPAddress -InterfaceIndex $index -AddressFamily IPv4 -ErrorAction SilentlyContinue |
            Where-Object { $_.IPAddress -notlike '169.254.*' } | ForEach-Object { $_.IPAddress })
        $gateway = @(Get-NetRoute -InterfaceIndex $index -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue |
            ForEach-Object { $_.NextHop })
        $list.Add([ordered]@{
            name      = [string]$conn.Name
            interface = [string]$conn.InterfaceAlias
            type      = $type
            category  = [string]$conn.NetworkCategory
            internet  = ("$($conn.IPv4Connectivity)" -eq 'Internet' -or "$($conn.IPv6Connectivity)" -eq 'Internet')
            ipv4      = $ipv4
            gateway   = $gateway
            mac       = [string]$adapter.MacAddress
        })
    }
    return $list.ToArray()
}

# ------------------------------------------------------------------ Senden

function Send-Report([Uri]$Uri, [string]$Json) {
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    $body = [Text.Encoding]::UTF8.GetBytes($Json)
    $pauses = 0, 30, 120
    for ($i = 0; $i -lt $pauses.Count; $i++) {
        if ($pauses[$i] -gt 0) { Start-Sleep -Seconds $pauses[$i] }
        try {
            $response = Invoke-RestMethod -Uri $Uri -Method Post -Body $body -UseBasicParsing -TimeoutSec 30 `
                -ContentType 'application/json; charset=utf-8' -Headers @{ 'X-Client-Token' = [string]$Config.token }
            if ($response.ok -eq $true) { return $true }
            Write-Log "Unerwartete Antwort vom Server (Versuch $($i + 1)/$($pauses.Count))"
        } catch {
            $status = 0
            if ($_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode }
            Write-Log ("Senden fehlgeschlagen (Versuch {0}/{1}): {2} {3}" -f ($i + 1), $pauses.Count, $status, $_.Exception.Message)
            # Falscher Token, kaputte oder zu große Meldung: Wiederholen hilft nicht.
            if ($status -in 400, 401, 403, 413) { return $false }
        }
    }
    return $false
}

# ------------------------------------------------------------------ Ablauf

if (Test-Path -LiteralPath $ConfigPath) {
    try {
        $Config = Get-Content -LiteralPath $ConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json
    } catch {
        Write-Log "Konfiguration nicht lesbar: $($_.Exception.Message)"
        exit 2
    }
} elseif (-not $DryRun) {
    Write-Log "Konfiguration fehlt: $ConfigPath"
    exit 2
}

$windows = Invoke-Collector 'Windows' { Get-WindowsInfo }
$version = $null
if ($windows) { $version = $windows.version }

$report = [ordered]@{
    schema_version = $SchemaVersion
    agent_version  = $AgentVersion
    collected_at   = Format-Utc (Get-Date)
    device         = Invoke-Collector 'Gerät' { Get-DeviceInfo }
    windows        = $windows
    updates        = Invoke-Collector 'Updates' { Get-UpdateInfo $version }
    network        = @(Invoke-Collector 'Netzwerk' { Get-NetworkInfo } | Where-Object { $null -ne $_ })
}
$report['errors'] = @($Problems)

if ($DryRun) {
    $report | ConvertTo-Json -Depth 6
    exit 0
}

$uri = $null
if (-not [Uri]::TryCreate([string]$Config.url, [UriKind]::Absolute, [ref]$uri) -or
    ($uri.Scheme -ne 'https' -and $uri.Host -notin 'localhost', '127.0.0.1')) {
    Write-Log "Ungültige Server-Adresse in der Konfiguration (https:// nötig): $($Config.url)"
    exit 2
}

$json = $report | ConvertTo-Json -Depth 6 -Compress
if (Send-Report -Uri $uri -Json $json) {
    Write-Log ('Gemeldet: Build {0}, {1} Netzwerk(e), {2} Problem(e)' -f $version, $report.network.Count, $Problems.Count)
    exit 0
}
Write-Log 'Meldung konnte nicht zugestellt werden.'
exit 1
