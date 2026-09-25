# Agent auf einem Notebook installieren

Voraussetzungen: Windows 10 oder 11 (Windows PowerShell 5.1 ist dabei) und ein Administratorkonto.

Am einfachsten geht alles direkt auf dem Notebook:

1. Die Übersicht im Browser öffnen, anmelden und unter **Geräte** das Notebook anlegen.
   Ist es schon angelegt, auf „Token erneuern“ klicken.
2. Im blauen Kasten auf **Agent herunterladen** klicken und die ZIP-Datei nach `C:\` entpacken.
   Das ergibt `C:\HSGMonitorLight-agent`.
3. PowerShell **als Administrator** öffnen (Startmenü → „PowerShell“ → „Als Administrator ausführen“).
4. Den Befehl aus dem Kasten kopieren, einfügen und ausführen. Der Installer schickt sofort eine Testmeldung.
5. Aus der Übersicht abmelden und das Passwort nicht im Browser des Notebooks speichern.
   `C:\HSGMonitorLight-agent` kann danach gelöscht werden.

Der Token ist nur direkt nach „Anlegen“ bzw. „Token erneuern“ zu sehen. Ohne Übersicht auf dem Notebook geht es auch:
den Ordner `agent` aus dem Repo per USB-Stick übertragen und im Befehl den Pfad hinter `-File` anpassen.

## Was eingerichtet wird

| Was | Wo |
|---|---|
| Agent | `C:\Program Files\HSGMonitorLight\HSGMonitorLight.ps1`. Ändern dürfen ihn nur Administratoren. |
| Adresse und Token | `C:\ProgramData\HSGMonitorLight\config.json`. Lesen dürfen die Datei nur SYSTEM und Administratoren. |
| Letzte Update-Suche | `C:\ProgramData\HSGMonitorLight\state.json` |
| Noch nicht zugestellte Lebenszeichen | `C:\ProgramData\HSGMonitorLight\queue.json` (höchstens 150) |
| Protokoll | `C:\ProgramData\HSGMonitorLight\agent.log` (höchstens 1 MB, danach `.old`) |
| Geplante Aufgabe | „HSGMonitorLight“ |

## Was der Agent tut

- Er startet als SYSTEM beim Systemstart (+2 min), bei jeder neuen Netzwerkverbindung (+1 min) und stündlich,
  auch ohne Netzwerk.
- Er meldet Windows-Version und Build, das installierte Monatsupdate, **fehlende Updates** (ohne Treiber und
  optionale Updates), ob ein Neustart nötig ist, den **Virenschutz** mit dem Alter der Definitionen sowie die Netzwerke.
- Die Suche nach fehlenden Updates fragt bei Microsoft nach und dauert 10 s bis 2 min. Sie läuft deshalb höchstens
  alle 12 Stunden, zusätzlich sofort, wenn seitdem etwas installiert wurde. Installiert wird dabei nichts.
- Kommt die Meldung nicht an, zum Beispiel im Hallen-WLAN ohne Internet, merkt sich der Agent Zeit und Netzwerkname
  und schickt beides mit der nächsten erfolgreichen Meldung nach. So erscheinen auch Offline-Zeiten in der Übersicht.
- Vom Server nimmt er keinerlei Befehle an.

## Prüfen und Fehlersuche

- **Probelauf ohne Senden**, zeigt die gesammelten Daten als JSON:
  `powershell -ExecutionPolicy Bypass -File "C:\Program Files\HSGMonitorLight\HSGMonitorLight.ps1" -DryRun`
- **Protokoll** ansehen (als Administrator): `Get-Content C:\ProgramData\HSGMonitorLight\agent.log -Tail 20`
- **Sofort melden:** In der Aufgabenplanung die Aufgabe „HSGMonitorLight“ mit Rechtsklick → *Ausführen* starten.
- Steht im Protokoll ein `401`, passt der Token nicht mehr. Dann in der Übersicht „Token erneuern“ und neu installieren.

## Aktualisieren und entfernen

- **Neue Version:** den neuen Agent herunterladen und entpacken, dann `install.ps1` **ohne Parameter** als Administrator
  ausführen. Adresse und Token werden übernommen:
  `powershell -ExecutionPolicy Bypass -File C:\HSGMonitorLight-agent\install.ps1`
- **Entfernen:** `powershell -ExecutionPolicy Bypass -File C:\HSGMonitorLight-agent\uninstall.ps1` als Administrator ausführen.
  Mit `-KeepData` bleiben Konfiguration und Protokoll erhalten.
