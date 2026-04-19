<?php
/**
 * Admin page template for the Seafile Updraft Backup Uploader settings screen.
 *
 * Rendered from SBU_Plugin::render_page(). The caller supplies:
 *  - $this     (SBU_Plugin instance — used for $this->field() and $this->activity_logger->format())
 *  - $s        (settings array from get_settings())
 *  - $dots     (unicode placeholder shown when a password is already stored)
 *  - $activity (activity log string from SBU_ACTIVITY option)
 *
 * The admin JavaScript and styles live in assets/js/admin.js and assets/css/admin.css
 * respectively and are registered via enqueue_assets().
 *
 * @package seafile-updraft-backup-uploader
 *
 * @var SBU_Plugin            $this
 * @var array<string,mixed>   $s
 * @var string                $dots
 * @var string                $activity
 * @var string                $cron_url        External cron endpoint URL with embedded key (legacy form)
 * @var string                $cron_url_header External cron endpoint URL without the key (for header-based auth)
 * @var string                $cron_key        External cron endpoint secret key (32-char)
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap sbu">
	<h1>Seafile Updraft Backup Uploader <span class="ver">v<?php echo esc_html( SBU_VER ); ?></span></h1>

	<?php
	// Success banner after a restore completes — shown until user dismisses it
	// or a new restore starts. Populated from sbu_last_restore_success option.
	$last_restore = get_option( 'sbu_last_restore_success', array() );
	if ( ! empty( $last_restore['ts'] ) && empty( $last_restore['dismissed'] ) ) :
		$ts       = (int) $last_restore['ts'];
		$dir_nice = esc_html( $last_restore['dir_nice'] ?? ( $last_restore['dir'] ?? '' ) );
		$files    = (int) ( $last_restore['files'] ?? 0 );
		$mb       = number_format_i18n( ( $last_restore['bytes'] ?? 0 ) / 1024 / 1024, 1 );
		$dur_min  = max( 1, (int) round( ( $last_restore['duration'] ?? 0 ) / 60 ) );
		$when     = wp_date( 'd.m.Y H:i', $ts );
		$up_url   = admin_url( 'options-general.php?page=updraftplus' );
		?>
		<div class="sbu-restore-banner" id="sbu-restore-banner">
			<div class="sbu-restore-banner-head">
				<strong>✓ <?php esc_html_e( 'Wiederherstellung von Seafile abgeschlossen', 'seafile-updraft-backup-uploader' ); ?></strong>
				<button type="button" class="sbu-restore-dismiss" aria-label="<?php esc_attr_e( 'Hinweis schließen', 'seafile-updraft-backup-uploader' ); ?>" data-sbu-action="dismiss-restore-banner">×</button>
			</div>
			<p>
				<?php
				printf(
					/* translators: 1: backup set name, 2: timestamp, 3: file count, 4: MB size, 5: duration in minutes */
					esc_html__( 'Backup-Set %1$s wurde am %2$s in den UpdraftPlus-Ordner geladen — %3$d Dateien, %4$s MB, Dauer ca. %5$d Min.', 'seafile-updraft-backup-uploader' ),
					'<strong>' . esc_html( $dir_nice ) . '</strong>',
					esc_html( $when ),
					(int) $files,
					esc_html( $mb ),
					(int) $dur_min
				);
				?>
			</p>
			<p class="sbu-restore-banner-next">
				<?php esc_html_e( 'Nächster Schritt: die Dateien liegen jetzt lokal bereit. UpdraftPlus macht die eigentliche Wiederherstellung ins WordPress-System.', 'seafile-updraft-backup-uploader' ); ?>
			</p>
			<a href="<?php echo esc_url( $up_url ); ?>" class="button button-primary"><?php esc_html_e( 'In UpdraftPlus öffnen', 'seafile-updraft-backup-uploader' ); ?></a>
		</div>
	<?php endif; ?>

	<div class="sbu-info">
		<h3><?php esc_html_e( '☁️ So funktioniert das Plugin', 'seafile-updraft-backup-uploader' ); ?></h3>
		<p><?php echo wp_kses_post( __( 'Dieses Plugin arbeitet mit <strong>UpdraftPlus</strong> zusammen und tauscht den Remote-Speicher gegen eine direkte Seafile-Anbindung aus. Es löst zwei Richtungen getrennt — weil Upload und Restore <em>völlig unterschiedliche</em> Anforderungen haben.', 'seafile-updraft-backup-uploader' ) ); ?></p>
		<div class="sbu-info-twocol">
			<div>
				<h4><?php esc_html_e( '⬆️ Backup-Upload nach Seafile', 'seafile-updraft-backup-uploader' ); ?></h4>
				<p><?php echo wp_kses_post( __( 'Jede Datei wird in kleine Stücke (z. B. 40 MB) aufgeteilt und einzeln an Seafile geschickt. Notwendig, weil Reverse-Proxies wie Cloudflare nur 100 MB pro Upload-Request erlauben. Jedes Stück, das durchgeht, ist gesichert — fällt eines aus, wird nur dieses eine wiederholt, nicht die ganze Datei.', 'seafile-updraft-backup-uploader' ) ); ?></p>
			</div>
			<div>
				<h4><?php esc_html_e( '⬇️ Restore-Download von Seafile', 'seafile-updraft-backup-uploader' ); ?></h4>
				<p><?php echo wp_kses_post( __( 'Jede Datei wird zuerst als ganzes Stück geholt — derselbe Weg, den die Seafile-Weboberfläche nutzt. Klappt das nicht oder ist die Datei zu groß (über 500 MB), wechselt das Plugin automatisch auf Stücke. Läuft beides nicht sauber, werden die Pausen länger und du bekommst nach einer Stunde eine Info-Mail — abgebrochen wird nichts.', 'seafile-updraft-backup-uploader' ) ); ?></p>
			</div>
		</div>
		<p class="sbu-info-integrity"><?php echo wp_kses_post( __( '<strong>🔒 Integritätsprüfung läuft automatisch:</strong> Beim Upload wird für jede Backup-Datei eine Prüfsumme berechnet und gespeichert. Beim Wiederherstellen wird jede heruntergeladene Datei gegen diese Prüfsumme geprüft — fällt ein Fehler auf, wird die Datei als beschädigt markiert. Das geschieht ohne zusätzliche Bandbreite.', 'seafile-updraft-backup-uploader' ) ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Keine Dateigrößen-Begrenzung — auch Multi-GB-Dateien funktionieren', 'seafile-updraft-backup-uploader' ); ?></li>
			<li><?php esc_html_e( 'Reverse-Proxy-kompatibel (Cloudflare Tunnel Free Tier, 100-MB-Upload-Limit)', 'seafile-updraft-backup-uploader' ); ?></li>
			<li><?php esc_html_e( 'Läuft ohne externen Cron und ohne Seiten-Traffic — WordPress-internes Loopback hält die Queue am Leben', 'seafile-updraft-backup-uploader' ); ?></li>
			<li><?php esc_html_e( 'Dashboard-Widget, E-Mail-Alerts bei Fehler oder Stillstand, automatische Aufbewahrung', 'seafile-updraft-backup-uploader' ); ?></li>
		</ul>
		<div class="setup"><strong><?php esc_html_e( 'Einrichtung:', 'seafile-updraft-backup-uploader' ); ?></strong> <?php echo wp_kses_post( __( 'In UpdraftPlus den Remote-Speicher auf <em>Keine</em> stellen. Dieses Plugin übernimmt den Upload automatisch.', 'seafile-updraft-backup-uploader' ) ); ?></div>
	</div>

	<div class="sbu-grid">
		<div class="sbu-col-left">
			<div class="card">
				<h2><span class="icon">⚙️</span><?php esc_html_e( 'Verbindung & Einstellungen', 'seafile-updraft-backup-uploader' ); ?> <span class="sbu-save-status" id="sbu-save-status"></span></h2>
				<div id="sbu-settings-form">
					<?php
					$this->field( 'url', 'Seafile URL', $s['url'], 'url', 'https://seafile.example.com' );
					$this->field( 'user', __( 'Benutzername (E-Mail)', 'seafile-updraft-backup-uploader' ), $s['user'], 'email', 'user@example.com' );
					?>

					<div class="ff">
						<label><?php esc_html_e( 'Passwort', 'seafile-updraft-backup-uploader' ); ?></label>
						<input type="password" name="<?php echo esc_attr( SBU_OPT ); ?>[pass]" value="<?php echo $s['pass'] ? esc_attr( $dots ) : ''; ?>" autocomplete="new-password" />
						<p class="hint"><?php esc_html_e( 'AES-256 verschlüsselt gespeichert. Leer lassen für aktuelles Passwort.', 'seafile-updraft-backup-uploader' ); ?></p>
					</div>

					<div class="ff">
						<label>Bibliothek</label>
						<div class="sbu-picker">
							<select name="<?php echo esc_attr( SBU_OPT ); ?>[lib]" id="sbu-lib" disabled>
								<option value="<?php echo esc_attr( $s['lib'] ); ?>"><?php echo $s['lib'] ? esc_html( $s['lib'] ) : esc_html__( '— Bitte laden —', 'seafile-updraft-backup-uploader' ); ?></option>
							</select>
							<button type="button" class="sbu-btn-load" id="sbu-lib-load">Laden</button>
							<span class="sbu-picker-status" id="sbu-lib-status"></span>
						</div>
						<p class="hint">Verfügbare Bibliotheken werden von deinem Seafile-Server geladen.</p>
					</div>

					<div class="ff">
						<label>Unterordner</label>
						<div class="sbu-picker">
							<select name="<?php echo esc_attr( SBU_OPT ); ?>[folder]" id="sbu-folder" disabled>
								<option value="<?php echo esc_attr( $s['folder'] ); ?>"><?php echo $s['folder'] !== '/' ? esc_html( $s['folder'] ) : '/ (Stammverzeichnis)'; ?></option>
							</select>
							<button type="button" class="sbu-btn-load" id="sbu-folder-load" disabled>Laden</button>
							<button type="button" class="sbu-btn-new" id="sbu-folder-new" disabled>Neu</button>
							<span class="sbu-picker-status" id="sbu-folder-status"></span>
						</div>
						<div class="sbu-newdir" id="sbu-newdir-form">
							<input type="text" id="sbu-newdir-input" placeholder="Neuer Ordnername" />
							<button type="button" class="sbu-btn-text" id="sbu-newdir-create">Erstellen</button>
							<button type="button" class="sbu-btn-cancel" id="sbu-newdir-cancel">Abbrechen</button>
						</div>
						<p class="hint">Ein Unterordner mit Zeitstempel wird automatisch für jedes Backup erstellt.</p>
					</div>

					<div class="ff">
						<label><?php esc_html_e( 'Upload-Chunk-Größe', 'seafile-updraft-backup-uploader' ); ?></label>
						<div class="row">
							<input type="number" name="<?php echo esc_attr( SBU_OPT ); ?>[chunk]" value="<?php echo esc_attr( $s['chunk'] ); ?>" min="5" max="90" />
							<span>MB (<?php esc_html_e( 'max. 90, empfohlen: 40', 'seafile-updraft-backup-uploader' ); ?>)</span>
						</div>
					</div>

					<div class="ff">
						<label><?php esc_html_e( 'Aufbewahrung auf Seafile', 'seafile-updraft-backup-uploader' ); ?></label>
						<div class="row">
							<input type="number" name="<?php echo esc_attr( SBU_OPT ); ?>[retention]" value="<?php echo esc_attr( $s['retention'] ); ?>" min="0" max="50" />
							<span><?php esc_html_e( 'Backups behalten (0 = alle behalten, nie löschen)', 'seafile-updraft-backup-uploader' ); ?></span>
						</div>
					</div>

					<?php $this->field( 'email', __( 'Benachrichtigungs-E-Mail', 'seafile-updraft-backup-uploader' ), $s['email'], 'email' ); ?>

					<div class="ff">
						<label><?php esc_html_e( 'Benachrichtigungen senden', 'seafile-updraft-backup-uploader' ); ?></label>
						<select name="<?php echo esc_attr( SBU_OPT ); ?>[notify]">
							<option value="error"<?php selected( $s['notify'], 'error' ); ?>><?php esc_html_e( 'Nur bei Fehlern', 'seafile-updraft-backup-uploader' ); ?></option>
							<option value="always"<?php selected( $s['notify'], 'always' ); ?>><?php esc_html_e( 'Nach jedem Backup', 'seafile-updraft-backup-uploader' ); ?></option>
							<option value="never"<?php selected( $s['notify'], 'never' ); ?>><?php esc_html_e( 'Nie', 'seafile-updraft-backup-uploader' ); ?></option>
						</select>
					</div>

					<div class="ff">
						<label>
							<input type="checkbox" name="<?php echo esc_attr( SBU_OPT ); ?>[auto]" value="1"<?php checked( $s['auto'], 1 ); ?> />
							<?php esc_html_e( 'Automatisch nach UpdraftPlus-Backup hochladen', 'seafile-updraft-backup-uploader' ); ?>
						</label>
					</div>

					<div class="ff">
						<label>
							<input type="checkbox" name="<?php echo esc_attr( SBU_OPT ); ?>[del_local]" value="1"<?php checked( $s['del_local'], 1 ); ?> />
							<?php esc_html_e( 'Lokale Dateien nach erfolgreichem Upload löschen', 'seafile-updraft-backup-uploader' ); ?>
						</label>
						<p class="hint"><?php esc_html_e( 'Nur aktivieren, wenn Backups ausschließlich auf Seafile gespeichert werden.', 'seafile-updraft-backup-uploader' ); ?></p>
					</div>

					<div class="ff">
						<label>
							<input type="checkbox" name="<?php echo esc_attr( SBU_OPT ); ?>[debug_log]" value="1"<?php checked( $s['debug_log'], 1 ); ?> />
							<?php esc_html_e( 'Detailliertes Debug-Log (TICK, BATCH, CHUNK)', 'seafile-updraft-backup-uploader' ); ?>
						</label>
						<p class="hint"><?php esc_html_e( 'Schreibt für jeden Chunk eines Restores eine eigene Zeile ins Aktivitätsprotokoll (Byte-Range, Dauer, HTTP-Code, cURL-Fehlernummer, Klassifikation). Nur einschalten, wenn ein konkretes Problem zu diagnostizieren ist — das Log füllt sich sonst sehr schnell.', 'seafile-updraft-backup-uploader' ); ?></p>
					</div>

					<div class="ff">
						<label><?php esc_html_e( 'Aktivitätsprotokoll aufbewahren', 'seafile-updraft-backup-uploader' ); ?></label>
						<div class="row">
							<input type="number" name="<?php echo esc_attr( SBU_OPT ); ?>[activity_log_retention_days]" value="<?php echo esc_attr( $s['activity_log_retention_days'] ); ?>" min="0" max="365" />
							<span><?php esc_html_e( 'Tage (0 = unbegrenzt, empfohlen: 30, min. 7)', 'seafile-updraft-backup-uploader' ); ?></span>
						</div>
						<p class="hint"><?php esc_html_e( 'Einträge älter als die angegebene Anzahl Tage werden automatisch einmal täglich entfernt. Das Log enthält Backup-Dateinamen, Zeitstempel und Bibliotheksangaben — eine kürzere Aufbewahrung reduziert die gespeicherte Menge identifizierender Daten.', 'seafile-updraft-backup-uploader' ); ?></p>
					</div>

					<div class="sbu-btn-bar">
						<button type="button" class="button button-primary" id="sbu-save"><?php esc_html_e( 'Einstellungen speichern', 'seafile-updraft-backup-uploader' ); ?></button>
						<span class="sbu-save-status" id="sbu-save-status2"></span>
						<button type="button" class="button" id="sbu-reset" style="color:#b32d2e;border-color:#b32d2e"><?php esc_html_e( 'Einstellungen zurücksetzen', 'seafile-updraft-backup-uploader' ); ?></button>
					</div>
				</div>
			</div>
		</div>

		<div class="sbu-col-right">
			<div class="card">
				<h2><span class="icon">▶️</span><?php esc_html_e( 'Aktionen', 'seafile-updraft-backup-uploader' ); ?></h2>
				<div class="acts">
					<button class="button" data-sbu-action="test"><?php esc_html_e( 'Verbindung testen', 'seafile-updraft-backup-uploader' ); ?></button>
					<button class="button button-primary" data-sbu-action="upload"><?php esc_html_e( 'Backups jetzt hochladen', 'seafile-updraft-backup-uploader' ); ?></button>
				</div>
				<div class="sbu-progress" id="sp">
					<div class="sbu-progress-bar"><div class="sbu-progress-fill" id="spf"></div></div>
				</div>
				<div id="sr"></div>
			</div>

			<div class="sbu-up-banner" id="sbu-upb">
				<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
					<div class="sbu-up-title" id="sbu-uptitle">⏳ <?php esc_html_e( 'Upload zu Seafile läuft...', 'seafile-updraft-backup-uploader' ); ?></div>
					<div style="display:flex;gap:6px">
						<button class="button button-small" id="sbu-pause" data-sbu-action="pause"><?php esc_html_e( 'Pause', 'seafile-updraft-backup-uploader' ); ?></button>
						<button class="button button-small" id="sbu-resume" style="display:none" data-sbu-action="resume"><?php esc_html_e( 'Fortsetzen', 'seafile-updraft-backup-uploader' ); ?></button>
						<button class="button button-small" id="sbu-abort" style="color:#b32d2e;border-color:#b32d2e" data-sbu-action="abort"><?php esc_html_e( 'Abbrechen', 'seafile-updraft-backup-uploader' ); ?></button>
					</div>
				</div>
				<div class="sbu-up-file" id="sbu-upf"></div>
				<div class="sbu-up-bar"><div class="sbu-up-fill" id="sbu-upfill"></div></div>
				<div class="sbu-up-pct" id="sbu-uppct"></div>
			</div>

			<div class="card" id="blc">
				<h2><span class="icon">📦</span><?php esc_html_e( 'Backups auf Seafile', 'seafile-updraft-backup-uploader' ); ?></h2>
				<div id="bl"><em><?php esc_html_e( 'Laden...', 'seafile-updraft-backup-uploader' ); ?></em></div>
			</div>

			<details class="card" id="sbu-cronbox" style="padding:10px 16px">
				<summary style="cursor:pointer;font-weight:600;padding:6px 0"><?php esc_html_e( 'Erweitert: Optionaler externer Heartbeat', 'seafile-updraft-backup-uploader' ); ?></summary>
				<p style="margin-top:10px"><strong><?php esc_html_e( 'Nicht nötig — das Plugin läuft komplett unabhängig.', 'seafile-updraft-backup-uploader' ); ?></strong> <?php echo wp_kses_post( __( 'Uploads und Restores bleiben ohne jeden externen Trigger in Bewegung: nach jedem Tick startet ein interner WordPress-Loopback den nächsten, ein Shutdown-Handler feuert einen Loopback nach, falls PHP die Runde abschießt, und während Backoff-Phasen wartet der laufende Request in-Process ab, statt die Kette abreißen zu lassen. Ein WP-Cron-Event dient als zusätzliches Sicherheitsnetz. <strong>Traffic auf der Seite ist nicht erforderlich.</strong>', 'seafile-updraft-backup-uploader' ) ); ?></p>
				<p><?php echo wp_kses_post( __( 'Die folgende URL ist nur für den Ausnahmefall dokumentiert, in dem die Hosting-Umgebung WordPress-Loopbacks komplett blockiert (z. B. Firewall-Regeln, die die Seite sich selbst nicht aufrufen lassen). In dem Fall kann ein beliebiger externer Cron diesen Endpunkt anpingen — der Regelbetrieb benötigt das nicht.', 'seafile-updraft-backup-uploader' ) ); ?></p>

				<p style="margin-top:14px"><strong><?php esc_html_e( 'Empfohlen: Schlüssel im Header übergeben', 'seafile-updraft-backup-uploader' ); ?></strong></p>
				<p class="hint" style="margin:2px 0 6px"><?php esc_html_e( 'Der Schlüssel landet so nicht in Reverse-Proxy-Logs, Browser-History oder Shell-History.', 'seafile-updraft-backup-uploader' ); ?></p>
				<pre style="background:#f6f7f7;padding:10px;border-radius:4px;white-space:pre-wrap;word-break:break-all;font-size:12px;margin:6px 0"><code>*/2 * * * * curl -fsS -o /dev/null -H "X-SBU-Cron-Key: <?php echo esc_html( $cron_key ); ?>" "<?php echo esc_html( $cron_url_header ); ?>"</code></pre>

				<p style="margin-top:14px"><strong><?php esc_html_e( 'Legacy: Schlüssel in der URL', 'seafile-updraft-backup-uploader' ); ?></strong></p>
				<p class="hint" style="margin:2px 0 6px"><?php esc_html_e( 'Funktioniert weiter, ist aber nicht empfohlen — der Schlüssel wird in allen Zwischen-Logs sichtbar.', 'seafile-updraft-backup-uploader' ); ?></p>
				<pre style="background:#f6f7f7;padding:10px;border-radius:4px;white-space:pre-wrap;word-break:break-all;font-size:12px;margin:6px 0"><code>*/2 * * * * curl -fsS -o /dev/null "<?php echo esc_html( $cron_url ); ?>"</code></pre>

				<p style="margin-top:14px"><button type="button" class="button" id="sbu-rotate-cron-key"><?php esc_html_e( 'Schlüssel neu generieren', 'seafile-updraft-backup-uploader' ); ?></button> <span class="sbu-picker-status" id="sbu-rotate-cron-status"></span></p>
				<p class="hint" style="margin-top:4px"><?php esc_html_e( 'Ein neuer Schlüssel macht einen laufenden externen Cron bis zum nächsten Update der Crontab wirkungslos — den Regelbetrieb trifft das nicht.', 'seafile-updraft-backup-uploader' ); ?></p>
			</details>

			<div class="card" id="logcard">
				<h2><span class="icon">📋</span><?php esc_html_e( 'Aktivitätsprotokoll', 'seafile-updraft-backup-uploader' ); ?></h2>
				<div class="log-acts">
					<div class="log-acts-filter">
						<label for="sbu-log-filter" class="sbu-log-filter-label"><?php esc_html_e( 'Filter:', 'seafile-updraft-backup-uploader' ); ?></label>
						<select id="sbu-log-filter" class="sbu-log-filter">
							<option value="all"><?php esc_html_e( 'Alle', 'seafile-updraft-backup-uploader' ); ?></option>
							<option value="errors"><?php esc_html_e( 'Nur Fehler', 'seafile-updraft-backup-uploader' ); ?></option>
							<option value="restore"><?php esc_html_e( 'Restore-Flow', 'seafile-updraft-backup-uploader' ); ?></option>
							<option value="debug"><?php esc_html_e( 'Nur Debug', 'seafile-updraft-backup-uploader' ); ?></option>
						</select>
					</div>
					<div class="log-acts-buttons">
						<button class="button button-small" data-sbu-action="export-log"><?php esc_html_e( 'Als Textdatei exportieren', 'seafile-updraft-backup-uploader' ); ?></button>
						<button class="button button-small" data-sbu-action="export-log-anon" title="<?php esc_attr_e( 'Domain, Bibliotheks-ID und Ordnerpfad werden im Export maskiert — zum Teilen mit Support, ohne Datenschutz-Bedenken.', 'seafile-updraft-backup-uploader' ); ?>"><?php esc_html_e( 'Anonymisiert exportieren', 'seafile-updraft-backup-uploader' ); ?></button>
						<button class="button button-small" data-sbu-action="clear-log" style="color:#b32d2e"><?php esc_html_e( 'Log leeren', 'seafile-updraft-backup-uploader' ); ?></button>
					</div>
				</div>
				<?php if ( $activity ) : ?>
					<div class="sbu-al" id="alc"><?php echo $this->activity_logger->format( $activity ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format() returns pre-escaped HTML with span-wrapped log lines. ?></div>
				<?php else : ?>
					<div class="sbu-al" id="alc"><span class="dim"><?php esc_html_e( 'Noch keine Aktivität aufgezeichnet.', 'seafile-updraft-backup-uploader' ); ?></span></div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
