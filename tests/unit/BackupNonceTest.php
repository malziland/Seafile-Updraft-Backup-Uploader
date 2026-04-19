<?php
/**
 * Tests for SBU_Plugin::extract_backup_nonce(): filename → nonce extraction
 * that gates a single-backup-set upload queue.
 */

declare( strict_types = 1 );

namespace SBU\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversMethod;
use SBU\Tests\Helpers\PluginLoader;
use SBU\Tests\Helpers\TestCase;
use SBU_Plugin;

#[CoversMethod(SBU_Plugin::class, 'extract_backup_nonce')]
final class BackupNonceTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        PluginLoader::load();
    }

    public function test_returns_nonce_for_canonical_filenames(): void {
        $cases = [
            'backup_2026-04-10-2052_INTERIORISTA_4f3ce36c22a1-db.gz'               => '4f3ce36c22a1',
            'backup_2026-04-10-2052_INTERIORISTA_4f3ce36c22a1-plugins.zip'         => '4f3ce36c22a1',
            'backup_2026-04-10-2052_INTERIORISTA_4f3ce36c22a1-uploads.zip'         => '4f3ce36c22a1',
            'backup_2026-04-10-2052_INTERIORISTA_4f3ce36c22a1-uploads13.zip'       => '4f3ce36c22a1',
            'backup_2026-04-10-2052_My-Site_abcdef012345-themes.zip'               => 'abcdef012345',
            'backup_2021-01-01-0000_Site_Name_With_Underscores_0123456789ab-db.gz' => '0123456789ab',
        ];
        foreach ( $cases as $name => $expected ) {
            $this->assertSame(
                $expected,
                SBU_Plugin::extract_backup_nonce( $name ),
                "Nonce extraction failed for: {$name}"
            );
        }
    }

    public function test_lowercases_uppercase_nonce(): void {
        $this->assertSame(
            '4f3ce36c22a1',
            SBU_Plugin::extract_backup_nonce( 'backup_2026-04-10-2052_Site_4F3CE36C22A1-db.gz' )
        );
    }

    public function test_rejects_non_backup_filenames(): void {
        $bad = [
            'readme.txt',
            'random.zip',
            'backup_without_nonce.zip',
            'backup_2026-04-10-2052_Site_tooshort-db.gz',
            'backup_2026-04-10-2052_Site_4f3ce36c22a1.zip', // missing -type suffix
            'backup_2026-04-10-2052_Site_4f3ce36c22a1-db.rar', // unsupported ext
            '',
        ];
        foreach ( $bad as $name ) {
            $this->assertSame(
                '',
                SBU_Plugin::extract_backup_nonce( $name ),
                "Should reject: " . ( $name === '' ? '<empty>' : $name )
            );
        }
    }
}
