# Changelog

## Version 1.4.3 // 11.10.2026

* Bericht (HTML): Status in „Alle Projekte“ als Symbol (✅ ⚠️ 🕒 ⏸️ ❌) mit Legende; Ereignisse der Änderungstabelle heißen `CORE`, `ADDON` und `BEHOBEN`, Pfeile als `→`. Die Data-Mail bleibt unverändert
* Englische Backend-Sprache (`en_gb`)

## Version 1.4.2 // 08.10.2026

* Die Überschrift der Änderungen nennt jetzt Datum und Uhrzeit des letzten erfolgreichen Versands (`ÄNDERUNGEN seit 2026-10-07 06:30`), in der Data-Mail und im Bericht. Bisher stand nur das Datum, was bei mehreren Versendungen am Tag mehrdeutig war

## Version 1.4.1 // 08.10.2026

* Der Cronjob ist auch in der Umgebung **Frontend** wählbar (bisher nur Backend und Script). Damit läuft der Report bei Hostern ohne System-Cron, die nur den Aufruf einer URL als Trigger erlauben. Bestehende Cronjob-Zeilen bleiben unverändert; „Frontend“ im Cronjob-Addon anhaken

## Version 1.4.0 // 08.10.2026

* **Delta gegenüber dem letzten Versand:** beide Mails nennen die Änderungen seit dem zuletzt erfolgreich versendeten Stand (je Mailtyp). Gespeichert werden nur Rohdaten, Befunde werden mit der aktuellen Konfiguration neu berechnet (keine Scheindeltas bei geänderten Mindestversionen, `(Regeländerung)` bei echten Änderungen nach einer Regeländerung). Neue Tabellen `rex_project_manager_report_reported`, `_state` und `_syslog_seen` (Addon nach dem Update neu installieren)
* Ereignisse: `STATUS`, `KERN`, `ADDON ANGEWENDET`, `UPDATE NEU` (aggregiert je Addon und Zielversion), `BEFUND NEU`/`BEHOBEN`, `SYSLOG NEU`, `PROJEKTE`; erster Lauf: `ÄNDERUNGEN: Basislauf, keine Vergleichsdaten`
* **Data-Mail:** neuer Abschnitt `ÄNDERUNGEN`, Zeilen `FEHLER: domain seit N Tagen (letzter Erfolg …)`, `PAUSIERT: …` und `ADDON-UPDATES AGGREGIERT: …`; Betreff, Kopfzeile, Inventarkopf und Inventarspalten bleiben (`status` kennt zusätzlich `paused`, Kopfzeile bei Pausen mit `| Pausiert: N`). Es gibt weiterhin keine Detail-Blöcke je Projekt
* **Bericht:** Block „Änderungen seit …“ als Tabelle, Fehlerdauer, Abschnitt „Pausiert“; kürzere Inline-Styles und eine Zeile je Projekt bei den Addon-Updates (54 Projekte: 177 KB auf rund 80 KB, Gmail kürzt ab etwa 102 KB)
* **Pause:** je Projekt „Monitoring pausiert“ mit Grund (Backend: Projekte); pausierte Projekte zählen nicht als Fehler oder Befund und erzeugen keine Änderungen
* **Testlauf:** Einstellungen `dry_run` und Testadresse; Konsolenbefehl `project_manager_report:send` (`--dry-run`, `--to`, `--no-advance-baseline`); `project_manager_report:preview` zeigt das Delta und schreibt nichts (die Optionen `--stale-hours`, `--syslog-ignore` und `--ignore-message` entfallen, es gelten die Einstellungen)
* `state` und gesehene Syslog-Einträge werden bei jedem Lauf fortgeschrieben, auch wenn der Versand scheitert; der gemeldete Stand nur nach erfolgreichem Versand. Syslog-Einträge, die 90 Tage nicht gesehen wurden, werden gelöscht

## Version 1.3.0 // 08.10.2026

* **Eigene Einstellungen** (Backend: Projekt Manager Report → Einstellungen): Mindestversionen für REDAXO, PHP, MariaDB und MySQL als freie Texteingabe (`x.y` oder `x.y.z`, leer = Regel aus), erlaubter PHP-Patch-Rückstand, Vorabversionen bei Addon-Updates ignorieren. Die Mindestwerte des Project Managers (`php_min`, `cms_min`, `cms_4_min`) werden nicht mehr gelesen
* **Overrides je Projekt** (Backend: Projekte, neue Tabelle `rex_project_manager_report_override`): eigene Mindestversionen pro Domain, leer = global, `0.0` = Regel aus. Das Addon nach dem Update neu installieren, damit die Tabelle entsteht
* **Datenbank nach Engine** (MariaDB oder MySQL) mit je eigener Mindestversion; MariaDB-Präfix `5.5.5-` und Suffixe werden bereinigt; unbekannte DB-Version ist kein Befund (`DB-Version unbekannt`)
* **Patch-Rückstand:** Abstand der PHP-Patchversion zum Maximum derselben PHP-Linie unter allen Projekten (zur Laufzeit berechnet), ab mehr als `max_patch_lag` ein Befund
* REDAXO-4-Projekte prüfen `min_rex` nicht
* Die Nullzeit `0000-00-00 00:00:00` (Spaltenvorgabe des Project Managers) gilt nicht als Abrufzeitpunkt
* **Data-Mail ohne Detail-Blöcke** (Tokenkosten): nur noch Kopf (mit `Ältester Abruf` und Zeile `SYSLOG_IGNORIERT: N`) und Inventar; die Blöcke `== domain ==` mit Befundzeilen entfallen vollständig, Details stehen im Bericht. Das Inventar hat 10 Felder: neu `behind`, `status` (`ok`, `stale`, `error`) und `last_ok` (letzter erfolgreicher Abruf); `below_min` kennt zusätzlich `db`, die db-Spalte zeigt `unbekannt`. Eine Auswertung muss 10 statt 6 Felder lesen und keine Blöcke mehr erwarten
* **Bericht:** „unter Mindestversion“ und „im Rückstand“ getrennt in der Zusammenfassung, Markierung `!` und `~` mit Legende (HTML rot und orange)
* Prüfskript `tests/run.php` (ohne REDAXO und ohne Abhängigkeiten, 76 Prüfungen inklusive Akzeptanz-Fixture)
* **Alle Einstellungen im Addon:** Empfänger (kompakt und Bericht), „Veraltet nach (Stunden)“, Syslog-Typen und Syslog-Meldungen ignorieren stehen jetzt unter *Projekt Manager Report → Einstellungen* (Gruppen Versand, Auswertung, Mindestversionen und Rückstand). Der Cronjob „Projekt Manager: Status-Mail“ hat keine typspezifischen Parameter mehr
* **Übernahme:** `install.php` kopiert die bisherigen Cronjob-Parameter einmalig in die Einstellungen (vorhandene Einstellungen bleiben unberührt) und leert danach die Parameter der Cronjob-Zeile. Das Addon nach dem Update neu installieren
* Beim Speichern werden E-Mail-Adressen und die Stundenzahl geprüft
* Konsolenbefehl `project_manager_report:preview`: `--stale-hours`, `--syslog-ignore` und `--ignore-message` sind optional und ersetzen sonst die Einstellungen

## Version 1.2.1 // 08.10.2026

* Betreffpräfixe geändert: kompakte Mail `[Projekt Manager - Data] …` (vorher `[ProjectManager] …`), Bericht `[Projekt Manager - Report] …` (vorher `[ProjectManager Bericht] …`). Rest des Betreffs unverändert; Filter und Auswertungen, die auf das alte Präfix prüfen, anpassen

## Version 1.2.0 // 08.10.2026

* **Mindestversionen als Befund:** Das Addon liest die Einstellungen des Project Managers (Server-Plugin, „Mindestanforderungen“: `php_min`, `cms_min`, `cms_4_min`). Ein Projekt unter Mindest-PHP oder Mindest-REDAXO ist ein Befund; REDAXO-4-Projekte werden gegen die REDAXO-4-Version geprüft. Ein leerer Wert bedeutet: keine Prüfung
* Kompakte Mail: Befundzeilen `Mindestversion: PHP 7.4.33 < 8.1`, neue letzte Inventarspalte `below_min` (`php`, `rex`; leer = erfüllt, `-` = keine Daten); Inventarzeilen haben jetzt 7 statt 6 Feldern
* Bericht: Verstöße im Handlungsbedarf, Kachel „Unter Mindestversion“, unterschrittene Versionen in der Tabelle rot markiert
* `addon_updates` beachtet jetzt auch „Addons ausschließen“ und „Versionen ausschließen“ des Project Managers, so zeigen Mail und Project-Manager-Übersicht dieselben Updates

## Version 1.1.1 // 08.10.2026

* Inventar der kompakten Mail: `addon_updates` nennt jetzt die Addons mit Update, kommagetrennt und alphabetisch (`addon_updates=cke5,massif_minify,mform`), statt der Anzahl. Leer = keine Updates, `-` = keine Daten (FEHLER). Eine Auswertung, die `addon_updates=N` als Zahl liest, muss angepasst werden

## Version 1.1.0 // 08.10.2026

* Zwei Mails: die bisherige strukturierte Mail bleibt als **kompakte Mail** (Plain-Text, für die automatische Auswertung), dazu neu ein **Bericht** für Menschen (HTML mit Text-Alternative; Betreff `[ProjectManager Bericht] …`)
* **Befund** zählt nur noch echte Befunde: FEHLER, VERALTET und nicht ignorierte Syslog-Einträge. Addon-Updates sind kein Befund mehr und stehen nur im Inventar bzw. im Bericht
* Kompakte Mail schlanker: Teil 2 enthält nur noch Befundzeilen (keine Versions- und Update-Zeilen), Inventar mit Spaltenüberschrift
* Syslog-Typen `Deprecated` und `Notice` werden standardmäßig ignoriert (Vorgabe `Info,logevent,Deprecated,Notice`); ein gespeicherter Cronjob behält seinen bisherigen Wert
* Neuer Cronjob-Parameter „Syslog-Meldungen ignorieren“ (Teiltexte, einer pro Zeile) und neues Feld „Empfänger Bericht (HTML)“; `recipients` gilt weiter für die kompakte Mail
* Bericht zeigt ignorierte Syslog-Einträge getrennt je Typ („Syslog ohne Befund“)
* Konsolenbefehl `project_manager_report:preview` mit `--format=machine|report-text|report-html` und `--ignore-message`

## Version 1.0.0 // 08.10.2026

Erste Version.

* Cronjob-Typ „Projekt Manager: Status-Mail“: eine Status-Mail pro Tag aus den Daten des Project Managers (`rex_project_manager_logs`), Versand mit `rex_mailer` als Plain-Text
* Fester, parsebarer Betreff: `[ProjectManager] YYYY-MM-DD | N Projekte | X mit Befund | Y Fehler`
* Text in drei Teilen: Kopfzeile, Projekte mit Befund (FEHLER und VERALTET zuerst), Inventar mit Spaltenüberschrift
* Befunde: fehlgeschlagener oder veralteter Abruf, Addon-Updates (nur höhere, stabile Versionen), Syslog-Einträge der letzten 24 Stunden (gruppiert)
* Parameter am Cronjob: Empfänger, „veraltet nach“ (Stunden), Syslog-Typen ignorieren, Syslog-Meldungen ignorieren (Teiltexte)
* Konsolenbefehl `project_manager_report:preview` zeigt die Mail ohne Versand
* Fehler beim Versand werden ins REDAXO-Syslog geschrieben
* Cronjob wird bei der Installation inaktiv angelegt (Umgebung `script`, täglich 06:30)
