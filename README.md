# HSGMonitorLight

Ein schlankes Werkzeug, mit dem man eine Handvoll Windows-Notebooks aus der Ferne im Blick behält.
Gebaut für die Zeitnehmer-Notebooks eines Handballvereins.

Es zeigt für jedes Gerät:

- wann es **zuletzt online** war und mit welcher **IP** (öffentlich, lokal, Netzwerkname),
- welchen **Windows-Patch-Stand** es hat und ob Updates oder ein Neustart ausstehen.

Als Server reicht ein gewöhnlicher PHP-Webspace, zum Beispiel bei All-Inkl. Auf den Notebooks läuft ein PowerShell-Skript
über die Aufgabenplanung und meldet den Zustand per HTTPS. Befehle vom Server an die Geräte gibt es nicht.

| Ordner | Inhalt |
|---|---|
| `agent/` | PowerShell-Agent mit Installer für die Notebooks *(folgt)* |
| `server/` | PHP-Endpunkt und Übersicht für den Webspace *(folgt)* |

**Stand:** Planung. Das Konzept steht in [PLAN.md](PLAN.md).
