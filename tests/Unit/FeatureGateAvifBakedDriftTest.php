<?php
/**
 * Gate 1 — AVIF baked-constant staleness self-heal tests (FIX 2 MAJOR).
 *
 * The baked OXPULSE_AVIF_ALLOWED constant (in the generated
 * oxpulse-img.php miss-endpoint) only regenerates on activation /
 * updated_option(delivery keys) / version bump — NOT on a Freemius
 * license change. So Pro→free keeps serving AVIF (leak) and free→Pro
 * withholds AVIF (lag) until the next settings save.
 *
 * The self-heal drift-guard (ServiceRegistrar::maybeRebakeAvifOnLicenseChange)
 * mirrors the maybeGrandfatherPreFreemiusInstalls / imgproxy-health
 * self-heal idiom: on an idempotent admin-time guard, compare current
 * isPro() to a stored oxpulse_avif_baked_pro option; if they differ
 * AND the site is a LocalBackend site (endpoint===''), call
 * installLocalDelivery() to regenerate the endpoint with the correct
 * value, then update the stored option. Idempotent (no-op when they
 * match). For an imgproxy site (no local endpoint) it's a no-op on
 * regeneration but still updates the stored flag.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Local\LocalDeliveryInstaller;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use PHPUnit\Framework\TestCase;

class FeatureGateAvifBakedDriftTest extends TestCase
{
    /** Reuse the SAME fixed WP_CONTENT_DIR path as
     *  ServiceRegistrarMultisiteGateTest / ServiceRegistrarCacheCleanupTest
     *  ('/tmp/oxpulse-ms-test/wp-content') so the define-once constant
     *  is consistent regardless of which test class runs first. A
     *  different path would silently skip the define() and
     *  installLocalDelivery() would write to the other path while this
     *  test checks this one. */
    private string $wpContentDir = '/tmp/oxpulse-ms-test/wp-content';
    private string $uploadsBasedir = '/tmp/oxpulse-ms-test/uploads';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_did_action'] = [];
        $GLOBALS['__oxpulse_is_admin'] = true;
        $GLOBALS['__oxpulse_is_multisite'] = false;
        $GLOBALS['__oxpulse_fs_stub'] = null;
        $GLOBALS['__oxpulse_upload_dir'] = [
            'baseurl'    => 'https://example.test/wp-content/uploads',
            'basedir'    => $this->uploadsBasedir,
            'baseurlrel' => '/wp-content/uploads',
            'error'      => false,
        ];

        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->wpContentDir);
        }
        if (!is_dir($this->wpContentDir)) {
            @mkdir($this->wpContentDir, 0755, true);
        }
        if (!is_dir($this->uploadsBasedir)) {
            @mkdir($this->uploadsBasedir, 0755, true);
        }
        $this->resetArtifact();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_filters'],
            $GLOBALS['__oxpulse_actions'],
            $GLOBALS['__oxpulse_did_action'],
            $GLOBALS['__oxpulse_is_admin'],
            $GLOBALS['__oxpulse_is_multisite'],
            $GLOBALS['__oxpulse_fs_stub'],
            $GLOBALS['__oxpulse_upload_dir'],
        );
        $this->resetArtifact();
    }

    private function resetArtifact(): void
    {
        @unlink($this->wpContentDir . '/' . LocalDeliveryInstaller::ENDPOINT_FILENAME);
    }

    private function artifactPath(): string
    {
        return $this->wpContentDir . '/' . LocalDeliveryInstaller::ENDPOINT_FILENAME;
    }

    /** Read the baked OXPULSE_AVIF_ALLOWED literal from the endpoint file. */
    private function bakedAvifAllowed(): ?string
    {
        if (!is_file($this->artifactPath())) {
            return null;
        }
        $content = file_get_contents($this->artifactPath());
        if (preg_match("/define\\('OXPULSE_AVIF_ALLOWED',\\s*(true|false)\\)/", $content, $m)) {
            return $m[1];
        }
        return null;
    }

    private function signingOptions(): void
    {
        update_option(OptionSettingsRepository::OPTION_KEY, str_repeat('a', 64));
        update_option(OptionSettingsRepository::OPTION_SALT, str_repeat('b', 64));
    }

    /**
     * Invoke the private maybeRebakeAvifOnLicenseChange() via reflection
     * (mirrors GrandfatherPreFreemiusTest::runGrandfatherDetector).
     */
    private function runGuard(): void
    {
        $method = new \ReflectionMethod(ServiceRegistrar::class, 'maybeRebakeAvifOnLicenseChange');
        $method->invoke(null);
    }

    // ─── Drift: stored=pro, isPro()→false → regenerate + store false ──

    public function test_pro_to_free_regenerates_endpoint_and_stores_false(): void
    {
        update_option('oxpulse_avif_baked_pro', true);
        add_filter('oxpulse_is_pro', '__return_false');
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');

        $this->runGuard();

        $this->assertGreaterThan(
            0,
            did_action('oxpulse_rebaked_avif'),
            'Pro→free drift must regenerate the endpoint (re-bake AVIF=false)',
        );
        $this->assertFalse(
            (bool) get_option('oxpulse_avif_baked_pro'),
            'The stored flag must be updated to false after the re-bake',
        );
        $this->assertSame(
            'false',
            $this->bakedAvifAllowed(),
            'The regenerated endpoint must bake OXPULSE_AVIF_ALLOWED=false (no AVIF leak under free)',
        );
    }

    // ─── Drift: stored=free, isPro()→true → regenerate + store true ────

    public function test_free_to_pro_regenerates_endpoint_and_stores_true(): void
    {
        update_option('oxpulse_avif_baked_pro', false);
        add_filter('oxpulse_is_pro', '__return_true');
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');

        $this->runGuard();

        $this->assertGreaterThan(
            0,
            did_action('oxpulse_rebaked_avif'),
            'free→Pro drift must regenerate the endpoint (re-bake AVIF=true)',
        );
        $this->assertTrue(
            (bool) get_option('oxpulse_avif_baked_pro'),
            'The stored flag must be updated to true after the re-bake',
        );
        $this->assertSame(
            'true',
            $this->bakedAvifAllowed(),
            'The regenerated endpoint must bake OXPULSE_AVIF_ALLOWED=true (AVIF unlocked under Pro)',
        );
    }

    // ─── Idempotent: equal → NO regeneration ──────────────────────────

    public function test_equal_state_does_not_regenerate(): void
    {
        update_option('oxpulse_avif_baked_pro', true);
        add_filter('oxpulse_is_pro', '__return_true');
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');

        $this->runGuard();

        $this->assertSame(
            0,
            did_action('oxpulse_rebaked_avif'),
            'Equal state (stored===isPro) must NOT regenerate (idempotent)',
        );
        $this->assertTrue(
            (bool) get_option('oxpulse_avif_baked_pro'),
            'Equal state must leave the stored flag unchanged',
        );
        $this->assertFileDoesNotExist(
            $this->artifactPath(),
            'Idempotent guard must not write the endpoint file',
        );
    }

    // ─── Imgproxy site: no local endpoint → no regeneration, flag updated ──

    public function test_imgproxy_site_drift_updates_flag_without_regenerating(): void
    {
        update_option('oxpulse_avif_baked_pro', true);
        add_filter('oxpulse_is_pro', '__return_false');
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');

        $this->runGuard();

        $this->assertSame(
            0,
            did_action('oxpulse_rebaked_avif'),
            'Imgproxy site (no local endpoint) must NOT regenerate — no baked endpoint exists',
        );
        $this->assertFalse(
            (bool) get_option('oxpulse_avif_baked_pro'),
            'Imgproxy site must still update the stored flag so a later switch-to-local re-bakes correctly',
        );
        $this->assertFileDoesNotExist($this->artifactPath());
    }

    // ─── Non-admin: guard does not run ─────────────────────────────────

    public function test_guard_does_not_run_on_non_admin_requests(): void
    {
        $GLOBALS['__oxpulse_is_admin'] = false;
        update_option('oxpulse_avif_baked_pro', true);
        add_filter('oxpulse_is_pro', '__return_false');
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');

        $this->runGuard();

        $this->assertSame(0, did_action('oxpulse_rebaked_avif'), 'Guard must be admin-only');
        $this->assertTrue(
            (bool) get_option('oxpulse_avif_baked_pro'),
            'Non-admin must leave the stored flag untouched',
        );
    }
}
