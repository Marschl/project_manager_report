Project Manager Report

Das Addon ergänzt den Project Manager (Plugin "server") um eine tägliche Status-Auswertung. Der Project Manager sammelt Daten aus mehreren REDAXO-Installationen. Dieses Addon fasst sie in zwei Mails zusammen und nennt jeweils, was sich seit dem letzten Versand geändert hat. So muss niemand jedes Projekt einzeln im Backend prüfen, und ein automatischer Check sieht nur das Neue.


DIE ZWEI MAILS

1. Kompakte Mail (maschinenlesbar)
Reiner Text mit festem, parsebarem Betreff:

[Projekt Manager - Data] 2026-10-08 | 14 Projekte | 3 mit Befund | 1 Fehler

Sie besteht aus einer Kopfzeile, dem Abschnitt "ÄNDERUNGEN", wenigen Kurzzeilen (Fehlerdauer, pausierte Projekte, aggregierte Addon-Updates) und einem Inventar mit Spaltenüberschrift, einer Zeile pro Projekt:

# domain | rex | php | db | addon_updates | syslog | below_min | behind | status | last_ok

In addon_updates stehen die Namen der Addons mit Update, in below_min die unterschrittenen Mindestversionen (db, php, rex), in behind der PHP-Patch-Rückstand, status ist ok, stale (veraltet), error oder paused, last_ok der letzte erfolgreiche Abruf. Detail-Blöcke je Projekt und Syslog-Texte gibt es in dieser Mail bewusst nicht: Das Format ist so knapp, dass eine automatische Auswertung, etwa durch einen KI-Agenten, wenig Aufwand (Tokens) hat. Die Details stehen im Bericht.

2. Bericht (für Menschen)
HTML mit Text-Alternative. Abschnitte: Zusammenfassung, Änderungen seit dem letzten Versand, Handlungsbedarf (mit Fehlerdauer), Pausiert, Addon-Updates, Syslog ohne Befund und eine Tabelle aller Projekte mit Status (OK, BEFUND, VERALTET, FEHLER, PAUSIERT). Unterschrittene Mindestversionen und Patch-Rückstände sind markiert. Betreff:

[Projekt Manager - Report] 2026-10-08 | 3 mit Befund | 1 Fehler | 5 Addon-Updates

Beide Mails haben eigene Empfängerlisten, einzustellen im Backend unter "Projekt Manager Report, Einstellungen". Bleibt ein Feld leer, entfällt die jeweilige Mail.


ÄNDERUNGEN SEIT DEM LETZTEN VERSAND

- Baseline ist der Stand der letzten erfolgreich versendeten Mail, getrennt je Mailtyp. Schlägt der Versand fehl, bleibt der Stand unverändert, die nächste Mail trägt den ausgefallenen Zeitraum nach. Der erste Lauf ist ein Basislauf ohne Vergleichsdaten.
- Gemeldet werden: Ausfall und Wiederkehr eines Projekts (STATUS), Änderungen an REDAXO, PHP und Datenbank (KERN), angewendete Addon-Updates (ADDON ANGEWENDET), neu verfügbare Updates (UPDATE NEU, zusammengefasst je Addon und Zielversion, z. B. "cke5 7.8.0 bei 35 Projekten"), neue und behobene Befunde (BEFUND NEU, BEFUND BEHOBEN), neue Syslog-Meldungen (SYSLOG NEU) sowie neue und entfernte Projekte (PROJEKTE).
- Gespeichert werden nur Rohdaten (Versionen, Status, Zeiten), keine Befunde. Befunde und Delta werden mit der aktuellen Konfiguration berechnet; so entstehen keine Scheindeltas, wenn Mindestversionen geändert werden. Echte Änderungen nach einer Regeländerung sind mit "(Regeländerung)" gekennzeichnet.
- Fehlerdauer: "FEHLER: domain seit 842 Tagen (letzter Erfolg 2024-06-20)".
- Projekte lassen sich pausieren (Backend, Projekte): kein Fehler, kein Befund, keine Änderungen, eigener Abschnitt "PAUSIERT".
- Neue Syslog-Meldungen werden über einen Hash aus Meldung, Datei und Zeile erkannt und 90 Tage aufbewahrt.


WAS ALS BEFUND ZÄHLT

- Ein Projekt, dessen letzter Abruf fehlgeschlagen ist (FEHLER)
- Ein Projekt, dessen letzter erfolgreicher Abruf zu lange zurückliegt (VERALTET, einstellbar, Vorgabe 30 Stunden)
- Ein Projekt, das die Mindestversion für REDAXO, PHP oder die Datenbank unterschreitet
- Ein Projekt mit Patch-Rückstand in seiner PHP-Linie
- Syslog-Einträge der letzten 24 Stunden, die nicht ignoriert sind

Addon-Updates sind kein Befund. Sie stehen im Inventar bzw. im Bericht.


MINDESTVERSIONEN UND RÜCKSTAND

Alle Werte sind im Backend unter "Projekt Manager Report, Einstellungen" frei einstellbar (Text im Format x.y oder x.y.z, leer = Regel aus):

- Mindestversion REDAXO (Vorgabe 5.21.2)
- Mindestversion PHP (Vorgabe 8.3, verglichen wird die PHP-Linie)
- Mindestversion MariaDB (Vorgabe 10.11) und MySQL (Vorgabe 8.4): die Datenbank wird nach Engine getrennt bewertet
- Erlaubter PHP-Patch-Rückstand (Vorgabe 3): maximaler Abstand der Patchversion zur höchsten Patchversion derselben PHP-Linie unter allen Projekten. Das Maximum wird bei jedem Lauf berechnet, es gibt keine externe Quelle.

Unter "Projekte" lassen sich pro Domain eigene Mindestversionen hinterlegen, damit bewusst abweichende Projekte nicht dauerhaft als Befund gelten. Leer = globaler Wert, 0.0 = Regel für dieses Projekt aus.

Versionsangaben werden bereinigt: PHP "8.4.24-nmm1" wird zu 8.4.24, bei der Datenbank fallen Suffixe und das MariaDB-Präfix "5.5.5-" weg. Ist danach keine Version erkennbar, gilt sie als unbekannt: das ist kein Befund, die Mail weist aber darauf hin. REDAXO-4-Projekte prüfen die REDAXO-Mindestversion nicht. Die Einstellungen "Addons ausschließen" und "Versionen ausschließen" des Project Managers gelten weiter für die Update-Liste.


SYSLOG FILTERN

- Nach Typ: Vorgabe ist, Info, logevent, Deprecated und Notice zu ignorieren. Warnungen zählen als Befund.
- Nach Meldungstext: Teiltexte, einer pro Zeile, geprüft gegen "Typ Meldung Datei:Zeile". So lassen sich bekannte, harmlose Meldungen ausblenden.

Gefilterte Einträge erscheinen im Bericht getrennt als "Syslog ohne Befund".


BETRIEB

- Läuft als Cronjob-Typ ohne eigene Parameter, wählbar in den Umgebungen "script" (Server-Cron mit cronjob:run) und "frontend" (für Hoster ohne System-Cron, ausgelöst durch einen URL-Trigger). Der Versand läuft über das Addon phpmailer. Der Cronjob wird bei der Installation inaktiv angelegt, bis Empfänger eingetragen sind.
- Ohne System-Cron (Shared Hosting, nur URL-Aufrufe erlaubt): beim Report-Cronjob und bei den Abruf-Cronjobs des Project Managers die Umgebung "Frontend" anhaken und beim Hoster eine Seite des Projekts regelmäßig aufrufen lassen (mindestens alle 15 bis 60 Minuten, z. B. https://domain.tld/?cron=1, nicht aus einem Seiten- oder CDN-Cache). Das Cronjob-Addon führt dann beim Aufruf die fälligen Jobs aus; der Report läuft beim ersten Aufruf nach der eingestellten Zeit.
- Alle Einstellungen (Empfänger, Schwelle für "veraltet", Syslog-Filter, Mindestversionen, Testlauf) und die Overrides je Projekt liegen in den Backend-Seiten des Addons.
- Die Mail kommt auch, wenn nichts auffällig ist. Bleibt sie aus, ist der Versand defekt. Schlägt er fehl, steht ein Eintrag im REDAXO-Syslog.
- Testlauf: Die Einstellung "Testlauf" mit Testadresse oder der Konsolenbefehl project_manager_report:send --dry-run sendet nur an die Testadresse und speichert nichts. Mit --no-advance-baseline bleibt der gemeldete Stand bei einem manuellen Versand unverändert.
- Mit project_manager_report:preview sieht man beide Mails samt Änderungen, ohne zu senden oder zu speichern.
- Das HTML des Berichts liegt als Fragment vor und lässt sich im project-Addon überschreiben.


VORAUSSETZUNGEN

REDAXO 5.17 oder neuer, PHP 8.1 oder neuer, die Addons cronjob, phpmailer und project_manager mit dem Plugin server (ab Version 1.5.0).


HINWEISE

- Der Project Manager hält nur den letzten Abruf je Projekt. Den Verlauf bildet dieses Addon über eigene Zustandstabellen ab, beginnend mit dem ersten Lauf; ein nachträglicher Import früherer Stände ist nicht möglich.
- Der Client des Project Managers meldet die "neueste Version" eines Addons nicht immer korrekt. Das Addon meldet Updates nur, wenn die gemeldete Version tatsächlich höher ist. Ein vorhandenes Update kann trotzdem fehlen.
- Core-, PHP- und Datenbankversionen werden nur gegen die eingestellten Mindestwerte und den Rückstand innerhalb der eigenen Projekte geprüft, nicht gegen aktuelle Releases oder Sicherheitsmeldungen.
- Die Syslog-Texte stammen aus den überwachten Installationen und sind ungeprüft. Wer die kompakte Mail automatisch auswertet, muss sie als Daten behandeln, nicht als Anweisungen.
- Im Umleitungsmodus (Detour) von phpmailer ändert dieses den Betreff; das feste Betreffformat gilt nur ohne Detour.
