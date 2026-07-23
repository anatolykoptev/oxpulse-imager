<?php
/**
 * ServiceRegistrar LocalBackend multisite gate tests (#87).
 *
 * Verifies installLocalDelivery() on multisite:
 *  - calls uninstall() (clears any stale endpoint + cache .htaccess left
 *    from a pre-multisite conversion) and returns WITHOUT generating.
 *  - single-site path is unchanged (install() runs, endpoint generated).
 *
 * Drives the REAL production path: ServiceRegistrar::installLocalDelivery()
 * → buildLocalDeliveryInstaller() → LocalDeliveryInstaller::install()/
 * uninstall(), with a temp WP_CONTENT_DIR so filesystem effects are
 * observable. Goes RED on the pre-fix code (multisite generates the
 * endpoint file).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use PHPUnit\Framework\TestCase;

class ServiceRegistrarMultisiteGateTest extends TestCase
{
    private const KEY_HEX = '736563726574';
    private const SALT_HEX = '68656C6C6F';

    private string $wpContentDir;

    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_is_multisite'] = false;
        $GLOBALS['__oxpulse_upload_dir'] = [
            'baseurl'    => 'https://example.test/wp-content/uploads',
            'basedir'    => '/tmp/oxpulse-ms-test/uploads',
            'baseurlrel' => '/wp-content/uploads',
            'error'      => false,
        ];

        // Use a fresh temp WP_CONTENT_DIR per test run. WP_CONTENT_DIR
        // is a constant (define-once), so reuse the same path across
        // tests and reset its contents in setUp.
        $this->wpContentDir = '/tmp/oxpulse-ms-test/wp-content';
        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->wpContentDir);
        }
        $this->resetTempDir();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
        unset($GLOBALS['__oxpulse_is_multisite']);
        unset($GLOBALS['__oxpulse_upload_dir']);
        $this->resetTempDir();
    }

    private function resetTempDir(): void
    {
        $endpoint = $this->wpContentDir . '/oxpulse-img.php';
        $cacheDir = $this->wpContentDir . '/cache/oxpulse';
        @unlink($endpoint);
        @unlink($cacheDir . '/.htaccess');
        if (is_dir($cacheDir)) {
            @rmdir($cacheDir);
        }
        if (is_dir($this->wpContentDir . '/cache')) {
            @rmdir($this->wpContentDir . '/cache');
        }
    }

    private function setSigning(): void
    {
        update_option(OptionSettingsRepository::OPTION_KEY, self::KEY_HEX);
        update_option(OptionSettingsRepository::OPTION_SALT, self::SALT_HEX);
    }

    /**
     * #87: on multisite, installLocalDelivery() must call uninstall()
     * (removing any stale endpoint + .htaccess) and NOT generate the
     * endpoint file. Pre-create the endpoint + .htaccess to prove
     * uninstall ran; if install() ran instead, the file would be
     * (re)generated.
     */
    public function test_install_local_delivery_on_multisite_uninstalls_and_does_not_generate(): void
    {
        $GLOBALS['__oxpulse_is_multisite'] = true;
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        $this->setSigning();

        // Pre-create a stale endpoint + cache .htaccess (simulating a
        // pre-multisite conversion leaving stale artifacts).
        $endpoint = $this->wpContentDir . '/oxpulse-img.php';
        $cacheDir = $this->wpContentDir . '/cache/oxpulse';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        file_put_contents($endpoint, '<?php // stale endpoint');
        file_put_contents($cacheDir . '/.htaccess', '# stale htaccess');

        $this->assertFileExists($endpoint, 'precondition: stale endpoint must exist');

        ServiceRegistrar::installLocalDelivery();

        $this->assertFileDoesNotExist($endpoint, 'multisite: uninstall() must remove the stale endpoint, install() must NOT regenerate it');
        $this->assertFileDoesNotExist($cacheDir . '/.htaccess', 'multisite: uninstall() must remove the stale cache .htaccess');
    }

    /**
     * #87: single-site path is unchanged — install() runs and generates
     * the endpoint file when LocalBackend is active (endpoint empty) and
     * signing is configured.
     */
    public function test_install_local_delivery_on_single_site_generates_endpoint_unchanged(): void
    {
        $GLOBALS['__oxpulse_is_multisite'] = false;
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        $this->setSigning();

        $endpoint = $this->wpContentDir . '/oxpulse-img.php';
        $this->assertFileDoesNotExist($endpoint, 'precondition: no endpoint before install');

        ServiceRegistrar::installLocalDelivery();

        $this->assertFileExists($endpoint, 'single-site: install() must generate the endpoint (unchanged behavior)');
    }
}
