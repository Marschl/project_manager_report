# Changelog

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
