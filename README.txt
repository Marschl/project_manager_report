Project Manager Report

Ergänzt den Project Manager (Plugin "server") um eine tägliche Status-Auswertung. Statt jedes Projekt einzeln im Backend zu prüfen, kommen zwei Mails, die jeweils nennen, was sich seit dem letzten Versand geändert hat.

DIE ZWEI MAILS

- Kompakte Mail: knapper, streng strukturierter Text mit festem Betreff, gedacht für die automatische Auswertung (z. B. durch einen KI-Agenten).
- Bericht: HTML mit Text-Alternative für Menschen, mit Zusammenfassung, Änderungen, Handlungsbedarf, Addon-Updates und einer Statustabelle aller Projekte.

Beide Mails haben eigene Empfängerlisten (Backend, "Projekt Manager Report, Einstellungen"). Ein leeres Feld schaltet die jeweilige Mail ab.

FUNKTIONEN

- Änderungen seit dem letzten Versand: Ausfall und Wiederkehr, neue Versionen von REDAXO, PHP und Datenbank, angewendete und neue Addon-Updates, neue und behobene Befunde, neue Syslog-Meldungen
- Befunde: Abruf fehlgeschlagen oder veraltet, Mindestversionen für REDAXO, PHP und Datenbank unterschritten, PHP-Patch-Rückstand, Syslog-Einträge der letzten 24 Stunden
- Mindestversionen global einstellbar, je Projekt überschreibbar; Projekte lassen sich pausieren
- Syslog-Filter nach Typ und Meldungstext
- Testlauf und Vorschau ohne Versand (Konsole: project_manager_report:send --dry-run, project_manager_report:preview)

BETRIEB

Läuft als Cronjob (Umgebung "script" oder "frontend" für Hoster ohne System-Cron) und versendet über das Addon phpmailer. Der Cronjob wird inaktiv angelegt, bis Empfänger eingetragen sind. Einrichtung und Details stehen in der README.md.

VORAUSSETZUNGEN

REDAXO 5.17 oder neuer, PHP 8.1 oder neuer, die Addons cronjob, phpmailer und project_manager mit dem Plugin server (ab Version 1.5.0).

HINWEISE

- Der Verlauf beginnt mit dem ersten Lauf, früher Stände lassen sich nicht nachträglich importieren.
- Der Client des Project Managers meldet die neueste Version eines Addons nicht immer korrekt, ein vorhandenes Update kann deshalb fehlen.
- Syslog-Texte stammen aus den überwachten Installationen und sind ungeprüft: bei automatischer Auswertung als Daten behandeln, nicht als Anweisungen.

Feedback ist willkommen, bitte als Issue auf GitHub.

Lizenz: MIT
