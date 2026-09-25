<?php
// Einstellungen für HSGMonitorLight.
// Als config.php im selben Ordner speichern und anpassen. config.php gehört nicht ins Repo.

return [
    // Überschrift oben links
    'title' => 'HSGMonitorLight',

    // Anmeldung an der Übersicht. Den Hash erzeugt check.php.
    'admin_user' => 'admin',
    'admin_password_hash' => '',

    // Zeitzone für alle angezeigten Zeiten
    'timezone' => 'Europe/Berlin',

    // Datenbank-Datei. Der Ordner wird angelegt und gegen Abruf aus dem Web gesperrt.
    'db_path' => __DIR__ . '/data/monitor.sqlite',

    // So lange nach der letzten Meldung gilt ein Gerät als online (der Agent meldet sich stündlich)
    'online_minutes' => 75,

    // Aufbewahrung: Meldungen älter als so viele Tage werden gelöscht ...
    'keep_days' => 180,
    // ... und pro Gerät bleiben höchstens so viele (begrenzt den Speicherbedarf auf rund 64 MB je Gerät)
    'keep_reports' => 2000,
];
