# Agent auf einem Notebook installieren

Voraussetzungen: Windows 10 oder 11 (Windows PowerShell 5.1 ist dabei) und ein Administratorkonto.

1. In der Übersicht unter **Geräte** das Notebook anlegen und den angezeigten Befehl kopieren.
   Der Token ist nur dieses eine Mal zu sehen. Ist er weg, auf „Token erneuern“ klicken.
2. Diesen Ordner `agent` auf das Notebook bringen, zum Beispiel über GitHub → *Code* → *Download ZIP* und dann entpacken.
3. PowerShell **als Administrator** öffnen und in den Ordner wechseln, etwa:
   `cd "$HOME\Downloads\HSGMonitorLight-main\agent"`
4. Den kopierten Befehl einfügen und ausführen. Der Installer schickt sofort eine Testmeldung.
   Danach erscheint das Notebook in der Übersicht.

## Was eingerichtet wird

| Was | Wo |
|---|---|
| Agent | `C:\Program Files\HSGMonitorLight\HSGMonitorLight.ps1`. Ändern dürfen ihn nur Administratoren. |
| Adresse und Token | `C:\ProgramData\HSGMonitorLight\config.json`. Lesen dürfen die Datei nur SYSTEM und Administratoren. |
| Protokoll | `C:\ProgramData\HSGMonitorLight\agent.log` (höchstens 1 MB, danach `.old`) |
| Geplante Aufgabe | „HSGMonitorLight“. Sie läuft als SYSTEM beim Systemstart (+2 min), bei jeder neuen Netzwerkverbindung (+1 min) und stündlich. |

## Prüfen und Fehlersuche

- **Probelauf ohne Senden**, zeigt die gesammelten Daten als JSON:
  `powershell -ExecutionPolicy Bypass -File "C:\Program Files\HSGMonitorLight\HSGMonitorLight.ps1" -DryRun`
- **Protokoll** ansehen (als Administrator): `Get-Content C:\ProgramData\HSGMonitorLight\agent.log -Tail 20`
- **Sofort melden:** In der Aufgabenplanung die Aufgabe „HSGMonitorLight“ mit Rechtsklick → *Ausführen* starten.
- Steht im Protokoll ein `401`, passt der Token nicht mehr. Dann in der Übersicht „Token erneuern“ und neu installieren.

## Aktualisieren und entfernen

- **Neue Version:** den neuen `agent`-Ordner holen und `install.ps1` ohne Parameter als Administrator ausführen.
  Adresse und Token werden übernommen.
- **Entfernen:** `powershell -ExecutionPolicy Bypass -File .\uninstall.ps1` als Administrator ausführen.
  Mit `-KeepData` bleiben Konfiguration und Protokoll erhalten.
