# Server einrichten (All-Inkl-Webspace)

Das ist einmalig nötig und dauert etwa 15 Minuten.

1. **Subdomain:** Im KAS unter *Domain → Subdomains* die Subdomain `monitor.<vereinsdomain>` anlegen.
   Als Ziel den Pfad `/monitor/public/` eintragen und PHP 8.1 oder neuer wählen.
2. **SSL:** Für die Subdomain ein Let's-Encrypt-Zertifikat aktivieren und „SSL erzwingen“ einschalten.
3. **Hochladen:** Den *Inhalt* dieses Ordners `server/` per FTP nach `/monitor/` kopieren.
   Danach muss es `/monitor/public/index.php` geben.
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
├── app/             Programmlogik
├── config.php       Einstellungen (legst du an, steht nicht im Repo)
├── data/            entsteht automatisch: Datenbank und Anmelde-Sitzungen
└── public/          Document Root der Subdomain
```

- **Neue Version:** genauso hochladen. `config.php` und `data/` bleiben dabei unangetastet.
- **Sicherung:** `data/monitor.sqlite` herunterladen. Das ist die komplette Datenbank.
