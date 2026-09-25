# Server einrichten (All-Inkl-Webspace)

Das ist einmalig nötig und dauert etwa 15 Minuten.

1. **Subdomain:** Im KAS unter *Domain → Subdomains* die Subdomain `monitor.<vereinsdomain>` anlegen.
   Als Ziel den Pfad `/monitor/public/` eintragen (mit `public/` am Ende) und PHP 8.3 oder neuer wählen.
2. **SSL:** Für die Subdomain ein Let's-Encrypt-Zertifikat aktivieren und „SSL erzwingen“ einschalten.
3. **Hochladen:** Den *Inhalt* dieses Ordners `server/` per FTP nach `/monitor/` kopieren.
   Danach muss es `/monitor/public/index.php` geben. Zusätzlich den Ordner `agent/` aus dem Repo nach
   `/monitor/agent/` hochladen. Dann lässt sich der Agent in der Geräteverwaltung herunterladen.
4. **Prüfen:** `https://monitor.<vereinsdomain>/check.php` öffnen. Bis auf „config.php“ und „Passwort“
   sollte alles grün sein.
5. **Passwort:** Auf derselben Seite einen Passwort-Hash erzeugen. Dann `config.example.php` als `config.php`
   speichern, den Hash bei `admin_password_hash` eintragen und `config.php` nach `/monitor/` hochladen.
   Danach ist `check.php` nur noch nach der Anmeldung erreichbar.
6. **Geräte anlegen:** Unter `https://monitor.<vereinsdomain>/` als `admin` anmelden und unter *Geräte*
   für jedes Notebook einen Eintrag anlegen. Der angezeigte Befehl installiert den Agent,
   siehe [agent/README.md](../agent/README.md).

## Aufbau auf dem Webspace

```
/monitor/
├── .htaccess        sperrt alles außerhalb von public/
├── agent/           Agent-Dateien für den Download (nur nach Anmeldung über download.php)
├── app/             Programmlogik
├── config.php       Einstellungen (legst du an, steht nicht im Repo)
├── data/            entsteht automatisch: Datenbank und Anmelde-Sitzungen
└── public/          Document Root der Subdomain
```

- **Neue Version:** genauso hochladen, `agent/` eingeschlossen. `config.php` und `data/` bleiben dabei unangetastet.
  Beim ersten Aufruf passt sich die Datenbank selbst an. Hat ein Notebook eine ältere Agent-Version, steht auf
  seiner Karte „neuere Version verfügbar“.
- **Sicherung:** `data/monitor.sqlite` herunterladen. Das ist die komplette Datenbank. Geht sie verloren,
  fehlt nur der Verlauf: Geräte neu anlegen und die Agenten mit dem neuen Token neu installieren.

## Sicher betreiben

Die Anwendung selbst nimmt nur Meldungen im JSON-Format an und legt sie in der Datenbank ab. Sie speichert keine Dateien,
führt nichts aus und bietet keinen Upload. Den eigentlichen Zugang zum Webspace hat jeder, der Dateien hochladen kann.
Und derselbe Webspace trägt auch die Vereinswebsite. Deshalb:

- **Nur verschlüsselt hochladen:** Im FTP-Programm „FTP über TLS“ (FTPS) oder SFTP wählen, nie unverschlüsseltes FTP.
  Bei unverschlüsseltem FTP gehen Benutzername und Passwort im Klartext durchs Netz.
- **Eigenen FTP-Zugang nur für `/monitor/`:** Im KAS lässt sich ein zusätzlicher FTP-Benutzer anlegen, der nur diesen
  Ordner sieht. Wer die Monitor-Dateien pflegt, kommt dann nicht an die WordPress-Installation.
- **Nur geprüfte Versionen hochladen:** ausschließlich aus dem eigenen Repo. Vor dem Hochladen kurz ansehen, was sich
  geändert hat (auf GitHub unter *Commits*). Das GitHub-Konto mit Zwei-Faktor-Anmeldung schützen.
- **PHP aktuell halten:** `check.php` zeigt die PHP-Version an und warnt bei Versionen ohne Sicherheitsupdates.
  Umgestellt wird im KAS bei der Subdomain.

**Eingebaute Schutzmechanismen:**
- Die Übersicht ist nur nach Anmeldung erreichbar. Nach 5 Fehlversuchen ist die Anmeldung für 15 Minuten gesperrt.
- Jedes Formular ist per CSRF-Token geschützt.
- Die Schnittstelle nimmt pro Gerät höchstens eine Meldung alle 20 Sekunden an, jeweils bis 32 KB.
- Pro Gerät bleiben höchstens 2000 Meldungen und maximal 180 Tage gespeichert (einstellbar in `config.php`).
  Selbst mit einem gestohlenen Token lässt sich der Webspace also nicht vollschreiben.
- Alles außerhalb von `public/` ist gesperrt, auch wenn der Document Root einmal falsch gesetzt ist.
- Fehlermeldungen landen nur im Fehlerprotokoll des Webspace, nie mit Pfaden im Browser.

## Passwort vergessen

Einen neuen Hash erzeugen, in `config.php` bei `admin_password_hash` eintragen und die Datei hochladen.
Den Hash kannst du auf einem PC mit PHP erzeugen, ohne dass das Passwort übers Netz geht. Die Eingabe bleibt dabei verdeckt:

```powershell
$s = Read-Host 'Passwort' -AsSecureString; $env:HML_PW = [Net.NetworkCredential]::new('', $s).Password; php -r "echo password_hash(getenv('HML_PW'), PASSWORD_DEFAULT), PHP_EOL;"; Remove-Item Env:HML_PW
```

Alternativ: `admin_password_hash` in `config.php` vorübergehend leeren. Dann ist `check.php` wieder ohne Anmeldung
erreichbar und erzeugt den Hash. Diesen Zustand nicht lange stehen lassen.
