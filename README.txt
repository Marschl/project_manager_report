Project Manager Report

Das Addon ergänzt den Project Manager (Plugin "server") um eine tägliche Status-Auswertung. Der Project Manager sammelt Daten aus mehreren REDAXO-Installationen. Dieses Addon fasst sie in zwei Mails zusammen, damit niemand jedes Projekt einzeln im Backend prüfen muss.


DIE ZWEI MAILS

1. Kompakte Mail (maschinenlesbar)
Reiner Text mit festem, parsebarem Betreff:

[Projekt Manager - Data] 2026-10-08 | 14 Projekte | 3 mit Befund | 1 Fehler

Sie besteht aus einer Kopfzeile, den Projekten mit Befund (nur die Befundzeilen) und einem Inventar mit Spaltenüberschrift, einer Zeile pro Projekt:

# domain | rex | php | db | addon_updates | syslog | below_min

In der Spalte addon_updates stehen die Namen der Addons mit Update, in below_min die unterschrittenen Mindestversionen (php, rex). Das Format ist bewusst knapp, damit eine automatische Auswertung, etwa durch einen KI-Agenten, wenig Aufwand hat.

2. Bericht (für Menschen)
HTML mit Text-Alternative. Abschnitte: Zusammenfassung, Handlungsbedarf, Addon-Updates, Syslog ohne Befund und eine Tabelle aller Projekte mit Status (OK, BEFUND, VERALTET, FEHLER). Unterschrittene Mindestversionen sind rot markiert. Betreff:

[Projekt Manager - Report] 2026-10-08 | 3 mit Befund | 1 Fehler | 5 Addon-Updates

Beide Mails haben eigene Empfängerlisten. Bleibt ein Feld leer, entfällt die jeweilige Mail.


WAS ALS BEFUND ZÄHLT

- Ein Projekt, dessen letzter Abruf fehlgeschlagen ist (FEHLER)
- Ein Projekt, dessen letzter erfolgreicher Abruf zu lange zurückliegt (VERALTET, einstellbar, Vorgabe 30 Stunden)
- Ein Projekt, das die Mindest-PHP-Version oder die Mindest-REDAXO-Version unterschreitet
- Syslog-Einträge der letzten 24 Stunden, die nicht ignoriert sind

Addon-Updates sind kein Befund. Sie stehen im Inventar bzw. im Bericht.


MINDESTVERSIONEN

Das Addon nutzt die Einstellungen des Project Managers (Server-Plugin, Einstellungen, Mindestanforderungen): Mindest-PHP, Mindest-REDAXO 5 und Mindest-REDAXO 4. Es gibt keine eigenen Werte. Ist eine Einstellung leer, wird sie nicht geprüft. Auch die Einstellungen "Addons ausschließen" und "Versionen ausschließen" gelten für die Update-Liste, so zeigen Mail und Project-Manager-Übersicht dieselben Updates.


SYSLOG FILTERN

- Nach Typ: Vorgabe ist, Info, logevent, Deprecated und Notice zu ignorieren. Warnungen zählen als Befund.
- Nach Meldungstext: Teiltexte, einer pro Zeile, geprüft gegen "Typ Meldung Datei:Zeile". So lassen sich bekannte, harmlose Meldungen ausblenden.

Gefilterte Einträge erscheinen im Bericht getrennt als "Syslog ohne Befund".


EIGENSCHAFTEN

- Liest nur die Datenbank des Project Managers. Es gibt keine zusätzlichen externen Aufrufe und keine Änderung am Abruf.
- Läuft als Cronjob-Typ, empfohlen in der Umgebung "script" (Server-Cron mit cronjob:run). Der Versand läuft über das Addon phpmailer.
- Alle Einstellungen (Empfänger, Schwelle für "veraltet", Syslog-Filter) liegen am Cronjob.
- Die Mail kommt auch, wenn nichts auffällig ist. Bleibt sie aus, ist der Versand defekt. Schlägt er fehl, steht ein Eintrag im REDAXO-Syslog.
- Mit dem Konsolenbefehl project_manager_report:preview sieht man beide Mails, ohne sie zu senden.
- Das HTML des Berichts liegt als Fragment vor und lässt sich im project-Addon überschreiben.
- Der Cronjob wird bei der Installation inaktiv angelegt, bis Empfänger eingetragen sind.


VORAUSSETZUNGEN

REDAXO 5.17 oder neuer, PHP 8.1 oder neuer, die Addons cronjob, phpmailer und project_manager mit dem Plugin server (ab Version 1.5.0).


HINWEISE

- Der Project Manager hält nur den letzten Abruf je Projekt. Einen Verlauf oder Vergleich mit früheren Läufen gibt es daher nicht.
- Der Client des Project Managers meldet die "neueste Version" eines Addons nicht immer korrekt. Das Addon meldet Updates nur, wenn die gemeldete Version tatsächlich höher ist. Ein vorhandenes Update kann trotzdem fehlen.
- Core-, PHP- und Datenbankversionen werden nur gegen die Mindestwerte des Project Managers geprüft, nicht gegen aktuelle Releases oder Sicherheitsmeldungen.
- Die Syslog-Texte stammen aus den überwachten Installationen und sind ungeprüft. Wer die kompakte Mail automatisch auswertet, muss sie als Daten behandeln, nicht als Anweisungen.
- Im Umleitungsmodus (Detour) von phpmailer ändert dieses den Betreff; das feste Betreffformat gilt nur ohne Detour.
