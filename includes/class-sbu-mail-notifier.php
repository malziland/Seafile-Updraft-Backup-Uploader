<?php
/**
 * Mail notifier service — extracted from SBU_Plugin (ARCH-001 Schritt 2).
 *
 * Verpackt alle Admin-Mails (Erfolg/Fehler/Stillstand) hinter einer
 * einzigen Oberfläche, damit zukünftige Transport-Umbauten
 * (HTML-Templates, Alerting-Webhook als Zweit-Kanal) lokal bleiben.
 *
 * @package SeafileUpdraftBackupUploader
 */

defined( 'ABSPATH' ) || exit;

final class SBU_Mail_Notifier {

	/**
	 * Settings-Provider (Callable, liefert Settings-Array).
	 *
	 * @var callable
	 */
	private $settings_provider;

	/**
	 * Konstruktor.
	 *
	 * @param callable $settings_provider Gibt die aktuellen Plugin-Settings zurück (Array).
	 */
	public function __construct( callable $settings_provider ) {
		$this->settings_provider = $settings_provider;
	}

	/**
	 * Send email notification about upload result.
	 *
	 * @param bool   $ok  Whether the upload was successful.
	 * @param string $msg Summary message.
	 */
	public function send( $ok, $msg ) {
		$s = ( $this->settings_provider )();
		if ( $s['notify'] === 'never' ) {
			return;
		}
		if ( $s['notify'] === 'error' && $ok ) {
			return;
		}
		if ( empty( $s['email'] ) ) {
			return;
		}

		$site   = get_bloginfo( 'name' );
		$status = $ok ? __( 'Erfolgreich', 'seafile-updraft-backup-uploader' ) : __( 'FEHLER', 'seafile-updraft-backup-uploader' );

		$subject = "[Seafile Backup] {$site}: {$status}";
		$body    = "Seafile Updraft Backup Uploader\n========================\n\n";
		$body   .= __( 'Website: ', 'seafile-updraft-backup-uploader' ) . $site . "\n";
		$body   .= "Status:  {$status}\n";
		$body   .= __( 'Details: ', 'seafile-updraft-backup-uploader' ) . $msg . "\n";
		$body   .= __( 'Target:  ', 'seafile-updraft-backup-uploader' ) . $s['lib'] . $s['folder'] . "\n";
		$body   .= __( 'Zeit:    ', 'seafile-updraft-backup-uploader' ) . current_time( 'd.m.Y H:i:s' ) . "\n";

		if ( ! $ok ) {
			$body .= "\n" . __( 'Log prüfen: ', 'seafile-updraft-backup-uploader' );
			$body .= admin_url( 'options-general.php?page=' . SBU_SLUG ) . "\n";
		}

		wp_mail( $s['email'], $subject, $body );
	}
}
