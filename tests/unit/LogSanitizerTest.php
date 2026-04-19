<?php
/**
 * Tests for the activity-log anonymization path (ajax_export_log_anon).
 *
 * This is the "safe to paste in a support thread" export — it runs over the
 * stored activity log and blanks out anything that could identify the
 * tenant: the Seafile host, the library UUID, the target folder, the user
 * e-mail, IPv4 addresses, any other UUID-shaped token, and the UpdraftPlus
 * nonce embedded in backup filenames. A regression here leaks customer data
 * the first time someone attaches the "anonymized" export to a GitHub
 * issue. The tests treat each masking rule as a contract.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversMethod;
use SBU\Tests\Helpers\JsonResponse;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Plugin;

#[CoversMethod(SBU_Plugin::class, 'ajax_export_log_anon')]
final class LogSanitizerTest extends TestCase {

	private SBU_Plugin $plugin;

	protected function setUp(): void {
		parent::setUp();
		PluginLoader::load();

		Functions\when( 'wp_parse_url' )->alias( static function ( $url ) {
			$parts = parse_url( (string) $url );
			return is_array( $parts ) ? $parts : false;
		} );

		$this->plugin = new SBU_Plugin();
	}

	/**
	 * Seed a minimal settings record so get_settings() merges into something
	 * realistic, then invoke ajax_export_log_anon and return the payload
	 * the handler would have sent to the browser.
	 */
	private function exportWith( string $log, array $settings = [] ): string {
		$this->options[ SBU_OPT ]      = array_merge(
			[
				'url'    => 'https://vault.example.com',
				'user'   => 'backup@example.com',
				'lib'    => '11111111-2222-3333-4444-555555555555',
				'folder' => '/tenant-42/backups',
			],
			$settings
		);
		$this->options[ SBU_ACTIVITY ] = $log;

		try {
			$this->plugin->ajax_export_log_anon();
			$this->fail( 'ajax_export_log_anon must send a JSON response' );
		} catch ( JsonResponse $r ) {
			$this->assertTrue( $r->success );
			return (string) $r->data;
		}

		return ''; // unreachable, keeps static analysis happy.
	}

	public function test_masks_configured_seafile_host(): void {
		$log    = '[2026-04-18 10:00:01] UPLOAD connected to vault.example.com/api2';
		$masked = $this->exportWith( $log );

		$this->assertStringNotContainsString( 'vault.example.com', $masked );
		$this->assertStringContainsString( '[SERVER]', $masked );
	}

	public function test_masks_configured_library_uuid(): void {
		$log    = '[2026-04-18 10:00:02] lib=11111111-2222-3333-4444-555555555555 selected';
		$masked = $this->exportWith( $log );

		$this->assertStringNotContainsString( '11111111-2222-3333-4444-555555555555', $masked );
		$this->assertStringContainsString( '[LIB]', $masked );
	}

	public function test_masks_configured_folder_path(): void {
		$log    = '[2026-04-18 10:00:03] writing to /tenant-42/backups/2026-04-18';
		$masked = $this->exportWith( $log );

		$this->assertStringNotContainsString( 'tenant-42/backups', $masked );
		$this->assertStringContainsString( '[PATH]', $masked );
	}

	public function test_masks_configured_user_email(): void {
		$log    = '[2026-04-18 10:00:04] login as backup@example.com ok';
		$masked = $this->exportWith( $log );

		$this->assertStringNotContainsString( 'backup@example.com', $masked );
		$this->assertStringContainsString( '[USER]', $masked );
	}

	public function test_masks_unknown_third_party_email(): void {
		$log    = '[2026-04-18 10:00:05] support ticket by alice@customer.test';
		$masked = $this->exportWith( $log );

		$this->assertStringNotContainsString( 'alice@customer.test', $masked );
		$this->assertStringContainsString( '[USER]', $masked );
	}

	public function test_masks_ipv4_addresses(): void {
		$log    = '[2026-04-18 10:00:06] remote 203.0.113.42 refused chunk';
		$masked = $this->exportWith( $log );

		$this->assertStringNotContainsString( '203.0.113.42', $masked );
		$this->assertStringContainsString( '[IP]', $masked );
	}

	public function test_masks_unconfigured_uuid_as_library(): void {
		$log    = '[2026-04-18 10:00:07] stray uuid aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee seen';
		$masked = $this->exportWith( $log );

		$this->assertStringNotContainsString( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $masked );
		$this->assertStringContainsString( '[LIB]', $masked );
	}

	public function test_masks_updraftplus_nonce_in_backup_filename(): void {
		// UpdraftPlus embeds a 12-hex-character nonce between "_" and "-" in
		// its backup filenames (backup_<date>_<site>_<12hex>-<part>.<ext>).
		$log    = '[2026-04-18 10:00:08] upload backup_2026-04-18-0815_site_abcdef123456-db.gz';
		$masked = $this->exportWith( $log );

		$this->assertStringNotContainsString( 'abcdef123456', $masked );
		$this->assertStringContainsString( '[NONCE]', $masked );
	}

	public function test_emits_anonymization_header(): void {
		$masked = $this->exportWith( '[2026-04-18 10:00:09] nothing to see here' );

		$this->assertStringContainsString( 'Activity Log (anonymized)', $masked );
		$this->assertStringContainsString( 'Website: [HOSTNAME]', $masked );
		$this->assertStringContainsString( 'masked', $masked );
	}

	public function test_preserves_non_sensitive_content(): void {
		$log    = '[2026-04-18 10:00:10] UPLOAD Backup komplett: 4 Dateien (182.3 MB)';
		$masked = $this->exportWith( $log );

		$this->assertStringContainsString( 'UPLOAD', $masked );
		$this->assertStringContainsString( 'Backup komplett', $masked );
		$this->assertStringContainsString( '182.3 MB', $masked );
	}

	public function test_empty_log_produces_placeholder_text(): void {
		$masked = $this->exportWith( '' );

		$this->assertStringContainsString( 'Noch keine Aktivität aufgezeichnet.', $masked );
	}

	public function test_masks_full_record_end_to_end(): void {
		$log = implode(
			"\n",
			[
				'[2026-04-18 10:00:11] UPLOAD to vault.example.com in /tenant-42/backups',
				'[2026-04-18 10:00:12] lib=11111111-2222-3333-4444-555555555555 user=backup@example.com',
				'[2026-04-18 10:00:13] remote 198.51.100.7 transient retry',
				'[2026-04-18 10:00:14] backup_2026-04-18-1015_site_0123456789ab-db.gz',
			]
		);

		$masked = $this->exportWith( $log );

		foreach (
			[
				'vault.example.com',
				'/tenant-42/backups',
				'11111111-2222-3333-4444-555555555555',
				'backup@example.com',
				'198.51.100.7',
				'0123456789ab',
			] as $leak
		) {
			$this->assertStringNotContainsString( $leak, $masked, "Leak in anonymized log: {$leak}" );
		}
	}
}
