=== Seafile Updraft Backup Uploader ===
Contributors: malziland
Tags: backup, seafile, updraftplus, chunked-upload, cloudflare
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Lädt UpdraftPlus-Backups über die native Seafile-Upload-API (Chunked Upload) hoch. Cloudflare-Tunnel-tauglich. Inklusive Dashboard-Widget, E-Mail-Alerts, Retention und zuverlässigem Restore.

== Description ==

**Seafile Updraft Backup Uploader** koppelt UpdraftPlus an einen selbst gehosteten Seafile-Server über die native Seafile-Upload-API — nicht über WebDAV.

= Warum nicht WebDAV? =

Die WebDAV-Implementierung von Seafile unterstützt keine Chunked Uploads. Steht der Seafile-Server hinter einem Reverse-Proxy mit Upload-Limit (z. B. Cloudflare Tunnel Free Tier: 100 MB), können Dateien oberhalb dieser Grenze schlicht nicht übertragen werden.

Dieses Plugin löst das Problem mit der Upload-API, die die Seafile-Weboberfläche selbst verwendet. Große Dateien werden in kleine Stücke zerlegt (z. B. 40 MB), jedes Stück wird als eigener HTTP-Request gesendet — voll kompatibel mit Cloudflare Tunnel und anderen Reverse-Proxies.

= Funktionsumfang =

* **Chunked Upload** — Dateien werden in 5–90-MB-Stücken übertragen, konfigurierbar.
* **Stream-First-Restore** — Downloads nutzen zuerst den gleichen Pfad wie die Seafile-Web-Oberfläche (ein HTTP-GET). Für Dateien über 500 MB und bei Stream-Fehlern fällt das Plugin automatisch auf parallele Range-Chunks zurück.
* **Exponentielles Backoff mit zwei Kurven** — unterscheidet zwischen „Server kalt" (längere Pausen) und „Netz wackelt" (kürzere Pausen, bis 1 h).
* **Stillstand-Meldung per Mail ohne Abbruch** — wenn eine Datei 1 h nicht vorwärtsgeht, gibt's eine Info-Mail. Die Queue läuft weiter, probiert frische Links, wartet nötigenfalls länger.
* **Zero-Traffic-Betrieb** — läuft ohne Besucher, ohne WP-Cron-Dependency und ohne externe Dienste. Ein interner WordPress-Loopback hält die Queue am Laufen.
* **Pause & Resume** — Uploads und Restores lassen sich pausieren und an exakt derselben Byte-Position fortsetzen.
* **Integritätsprüfung ohne Extra-Bandbreite** — pro Datei wird beim Upload eine SHA1-Prüfsumme gespeichert und beim Restore streamend geprüft.
* **Lokal-Status pro Backup-Set** — farbige Badges („Lokal vollständig", „Teilweise lokal", „Nur remote"). Bei vollständig lokalem Set ersetzt „In UpdraftPlus öffnen" den Wiederherstellen-Button.
* **Erfolgs-Banner nach Restore** — zeigt Zeitpunkt, Dateianzahl, Größe, Dauer und verlinkt direkt zu UpdraftPlus.
* **Anonymisierter Log-Export** — Host, Library-ID, Ordnerpfad, E-Mails, IPs und UUIDs werden im Export maskiert. Zum Teilen im Support.
* **Dashboard-Widget, E-Mail-Benachrichtigungen, Retention-Management** (4 Default, 0 = alle behalten).
* **UpdraftPlus-History-Sync** — nach Löschen lokaler Backups werden die zugehörigen UpdraftPlus-History-Einträge entfernt.
* **AES-256 Passwortverschlüsselung** mit zufälligem IV.
* **Mehrsprachige Oberfläche** (Deutsch, Englisch — automatisch nach WordPress-Locale).

= Anforderungen =

* WordPress 6.0+
* PHP 7.4+
* UpdraftPlus (Free oder Premium)
* Ein Seafile-Server (self-hosted oder Cloud)

= Empfohlene Server-Konfiguration =

Das Plugin läuft auf Minimal-Hosting (30 s `max_execution_time`, 128 MB `memory_limit`) und passt Chunk-Größe, Parallelität und Tick-Budget automatisch nach unten an. Folgende Werte machen Multi-GB-Restores spürbar schneller:

* **`max_execution_time` ≥ 60 s** — doppelt so große Chunks (~11 MB statt ~5 MB), halber Tick-Overhead bei langen Restores.
* **`max_execution_time` ≥ 180 s** — hält die Chunk-Größe auf 20 MB und maximiert die Parallelität.
* **`memory_limit` ≥ 256 MB** — erlaubt bis zu 8 parallele Chunks ohne RAM-Druck. 128 MB funktionieren, deckeln die Parallelität aber tiefer.

Keine dieser Werte ist Pflicht. Ohne Ausbau skaliert das Plugin automatisch herunter — die gewählten Werte stehen beim Restore-Start im Activity-Log: `Restore-Konfiguration: Xs Tick-Budget, Y parallele Chunks à Z MB`.

= Einrichtung =

1. Plugin installieren und aktivieren.
2. Einstellungen → Seafile Backup öffnen.
3. Seafile-URL, Zugangsdaten, Bibliothek und Unterordner eintragen.
4. „Verbindung testen" klicken.
5. In UpdraftPlus den Remote-Speicher auf „Keine" setzen — dieses Plugin übernimmt den Upload.
6. Fertig. Backups werden nach jedem UpdraftPlus-Lauf automatisch nach Seafile hochgeladen.

== Frequently Asked Questions ==

= Funktioniert das ohne UpdraftPlus? =
Nein. Das Plugin braucht UpdraftPlus, um die Backup-Dateien zu erzeugen. Es übernimmt nur den Upload und den Restore auf den Server.

= Was passiert mit Dateien über 100 MB? =
Sie werden automatisch in Chunks zerlegt. Jeder Chunk geht als eigener HTTP-Request raus. Seafile setzt die Datei auf dem Server wieder zusammen.

= Funktioniert das mit Cloudflare Tunnel? =
Ja. Das ist der primäre Anwendungsfall. Jeder Upload-Chunk bleibt unter dem 100-MB-Limit. Für Downloads nutzt das Plugin standardmäßig einen einzelnen HTTP-GET (wie die Seafile-Weboberfläche), bei großen Dateien oder Fehlern fällt es auf parallele Range-Chunks zurück.

= Was passiert, wenn ein Upload fehlschlägt? =
Abhängig vom Setting bekommst du eine E-Mail. Der nächste Upload-Durchlauf erzeugt einen neuen Ordner. Manuell kann der Upload auch in den Plugin-Einstellungen neu gestartet werden.

= Was bedeutet Retention „0"? =
Alle Backups behalten — nichts wird automatisch gelöscht.

= Was passiert, wenn „Lokale Dateien löschen" aktiv ist? =
Nach erfolgreichem Upload nach Seafile werden die lokalen Backup-Dateien entfernt und die zugehörigen UpdraftPlus-History-Einträge automatisch bereinigt. Nur Einträge ohne lokal verbliebene Dateien werden entfernt — Backups, die UpdraftPlus durch eigene Retention noch lokal hält, bleiben unangetastet.

= Kann ich ein Backup wiederherstellen, das nur auf Seafile liegt? =
Ja. Einstellungen → Seafile Backup → Backup in der Liste suchen → „Wiederherstellen". Die Dateien werden in den UpdraftPlus-Ordner geladen. Danach in UpdraftPlus auf „Lokalen Ordner neu scannen" — das Backup erscheint dort und kann normal zurückgespielt werden. Das Plugin zeigt ein Erfolgs-Banner mit Direktlink in UpdraftPlus.

= Was bedeutet „Lokal vollständig / Teilweise lokal / Nur remote"? =
Das Plugin prüft pro Backup-Set, wie viele der Seafile-Dateien bereits lokal vorhanden sind (mit passender Dateigröße). „Lokal vollständig" → Restore wäre doppelte Arbeit, der Button wird durch „In UpdraftPlus öffnen" ersetzt. „Teilweise lokal" → beim Restore werden nur die fehlenden Dateien geladen. „Nur remote" → klassischer Full-Restore.

= Muss ich externen Cron einrichten? =
Nein. Das Plugin läuft durch einen internen WordPress-Loopback komplett eigenständig — keine Besucher nötig, kein externer Cron-Trigger. Für Umgebungen, die Loopbacks komplett blockieren (spezielle Firewall-Regeln, die WordPress sich selbst nicht aufrufen lassen), gibt es einen optionalen schlüsselgeschützten Cron-Endpoint. Die URL mit Crontab-Beispiel steht direkt im Admin unter „Erweitert: Optionaler externer Heartbeat".

== Changelog ==

= 1.0.0 =
* Erste öffentliche Version. Chunked Upload über Seafile-API, Stream-First-Restore mit Range-Chunk-Fallback, exponentielles Backoff mit zwei Kurven, Stillstand-Meldung per Mail ohne Abbruch, Zero-Traffic-Betrieb ohne externe Dienste, Pause/Resume mit Byte-Offset, Integritätsprüfung ohne Extra-Bandbreite, Lokal-Status-Badges im Backup-Browser, Erfolgs-Banner nach Restore mit UpdraftPlus-Deeplink, anonymisierter Log-Export, AIMD-Rate-Controller, AES-256 Passwortverschlüsselung, mehrsprachige Oberfläche. 87 Tests / 257 Assertions.

== Upgrade Notice ==

= 1.0.0 =
Erste öffentliche Version.
