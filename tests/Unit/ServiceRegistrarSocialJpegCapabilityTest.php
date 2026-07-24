<?php
/**
 * ServiceRegistrar write-time social-jpeg capability probe trigger tests.
 *
 * Mirrors ServiceRegistrarImgproxyHealthTest: verifies the social-jpeg
 * capability probe fires ONLY at write-time (activation / settings-save
 * when imgproxy is active / once-per-version re-probe / hourly cron) —
 * never from the front-end read path. The probe and the imgproxy health
 * probe are complementary: both fire on the same triggers, each is
 * self-guarding via isLocalBackendActive().
 *
 * The test injects a recording HttpRequester (getImage) + a real
 * SocialJpegCapabilityCache + a fake sourceProvider so the REAL
 * SocialJpegCapabilityProbe::run() is exercised through the wiring.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Imgproxy\SocialJpegCapabilityCache;
use OXPulse\Imager\Infrastructure\Local\HttpRequester;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use PHPUnit\Framework\TestCase;

class ServiceRegistrarSocialJpegCapabilityTest extends TestCase
{
    private string $tmpDir = '';
    private string $imagePath = '';

    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_did_action'] = [];
        $GLOBALS['__oxpulse_transients'] = [];
        $GLOBALS['__oxpulse_scheduled_events'] = [];
        $GLOBALS['__oxpulse_is_admin'] = false;

        // Create a real temp file so SourcePolicy::authorize succeeds
        // in local mode (realpath must resolve).
        $this->tmpDir = sys_get_temp_dir() . '/oxpulse-sj-registrar-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->imagePath = $this->tmpDir . '/photo.webp';
        file_put_contents($this->imagePath, 'fake-image');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->imagePath)) {
            unlink($this->imagePath);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
        unset($GLOBALS['__oxpulse_options']);
        unset($GLOBALS['__oxpulse_actions']);
        unset($GLOBALS['__oxpulse_filters']);
        unset($GLOBALS['__oxpulse_did_action']);
        unset($GLOBALS['__oxpulse_transients']);
        unset($GLOBALS['__oxpulse_scheduled_events']);
        unset($GLOBALS['__oxpulse_is_admin']);
    }

    /**
     * Set up options for an imgproxy-active, local-source-mode config.
     */
    private function configureImgproxyLocal(string $endpoint = 'https://imgproxy.example.com'): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, $endpoint);
        update_option(OptionSettingsRepository::OPTION_ENABLED, true);
        update_option(OptionSettingsRepository::OPTION_KEY, '736563726574');
        update_option(OptionSettingsRepository::OPTION_SALT, '68656C6C6F');
        update_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES, ['https://example.com/']);
        update_option(OptionSettingsRepository::OPTION_SOURCE_MODE, 'local');
        update_option(OptionSettingsRepository::OPTION_LOCAL_BASE_PATH, $this->tmpDir);
    }

    private function sourceProvider(): callable
    {
        return fn(): ?string => 'https://example.com/photo.webp';
    }

    // ─── recheckSocialJpegCapability() direct behavior ───────────────

    public function test_recheck_fires_when_endpoint_configured(): void
    {
        $this->configureImgproxyLocal();
        $http = new GetImageStub(status: 200, contentType: 'image/jpeg');
        $cache = new SocialJpegCapabilityCache();

        ServiceRegistrar::recheckSocialJpegCapability($http, $cache, $this->sourceProvider());

        $this->assertSame(1, $http->getImageCalls, 'Probe must fire (getImage the .jpg URL) when an endpoint is configured');
        $this->assertTrue($cache->readOk(), 'A 200 + image/jpeg response must write "ok" to the cache');
        $this->assertGreaterThan(
            0,
            did_action('oxpulse_recheck_social_jpeg'),
            'recheckSocialJpegCapability must fire the marker action when endpoint is configured',
        );
    }

    public function test_recheck_does_not_fire_when_endpoint_empty(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        $http = new GetImageStub(status: 200, contentType: 'image/jpeg');
        $cache = new SocialJpegCapabilityCache();

        ServiceRegistrar::recheckSocialJpegCapability($http, $cache, $this->sourceProvider());

        $this->assertSame(0, $http->getImageCalls, 'Probe must NOT fire when no endpoint is configured (LocalBackend active)');
        $this->assertSame(0, did_action('oxpulse_recheck_social_jpeg'));
    }

    public function test_recheck_relative_endpoint_resolved_absolute(): void
    {
        // A relative endpoint ('/imgproxy') is non-empty → imgproxy
        // active → probe must fire against the resolved absolute URL.
        $this->configureImgproxyLocal('/imgproxy');
        $http = new GetImageStub(status: 200, contentType: 'image/jpeg');
        $cache = new SocialJpegCapabilityCache();

        ServiceRegistrar::recheckSocialJpegCapability($http, $cache, $this->sourceProvider());

        $this->assertSame(1, $http->getImageCalls);
        $this->assertStringStartsWith('https://', $http->lastGetImageUrl, 'relative endpoint must be resolved to absolute before probing');
        $this->assertGreaterThan(0, did_action('oxpulse_recheck_social_jpeg'));
    }

    public function test_recheck_403_writes_no(): void
    {
        $this->configureImgproxyLocal();
        $http = new GetImageStub(status: 403, contentType: 'text/plain');
        $cache = new SocialJpegCapabilityCache();

        ServiceRegistrar::recheckSocialJpegCapability($http, $cache, $this->sourceProvider());

        $this->assertFalse($cache->readOk());
    }

    // ─── activation hook triggers recheck ────────────────────────────

    public function test_activation_triggers_recheck_when_endpoint_configured(): void
    {
        $this->configureImgproxyLocal();

        oxpulse_imager_activate();

        $this->assertGreaterThan(
            0,
            did_action('oxpulse_recheck_social_jpeg'),
            'Activation with an imgproxy endpoint must trigger the social-jpeg capability recheck',
        );
    }

    public function test_activation_does_not_recheck_when_endpoint_empty(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');

        oxpulse_imager_activate();

        $this->assertSame(
            0,
            did_action('oxpulse_recheck_social_jpeg'),
            'Activation with no endpoint (LocalBackend active) must NOT trigger the social-jpeg recheck',
        );
    }

    // ─── settings-save (updated_option) triggers recheck ─────────────

    public function test_settings_save_triggers_recheck_when_endpoint_option_changes(): void
    {
        $this->configureImgproxyLocal();
        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerLocalDeliverySettingsSync', [$plugin]);

        $callback = $this->findUpdatedOptionCallback();
        $this->assertNotNull($callback, 'updated_option callback must be registered');
        $callback(OptionSettingsRepository::OPTION_ENDPOINT);

        $this->assertGreaterThan(
            0,
            did_action('oxpulse_recheck_social_jpeg'),
            'Settings-save (OPTION_ENDPOINT change) with imgproxy active must trigger the social-jpeg recheck',
        );
    }

    // ─── version-gated re-probe (admin-only, once per version) ────────

    public function test_version_mismatch_in_admin_triggers_social_jpeg_recheck(): void
    {
        $GLOBALS['__oxpulse_is_admin'] = true;
        $this->configureImgproxyLocal();
        update_option(OptionSettingsRepository::OPTION_PROBE_VERSION, '0.0.9');

        $this->invokePrivate('maybeReprobeOnVersionUpdate');

        $this->assertGreaterThan(0, did_action('oxpulse_recheck_social_jpeg'));
    }

    // ─── hourly cron callback ────────────────────────────────────────

    public function test_cron_callback_invokes_recheck_when_endpoint_set(): void
    {
        $this->configureImgproxyLocal();
        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerImgproxyHealthCron', [$plugin]);

        do_action('oxpulse_imgproxy_health_recheck');

        $this->assertGreaterThan(
            0,
            did_action('oxpulse_recheck_social_jpeg'),
            'The cron callback must invoke recheckSocialJpegCapability() when the endpoint is set',
        );
    }

    public function test_cron_callback_is_noop_when_endpoint_empty(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerImgproxyHealthCron', [$plugin]);

        do_action('oxpulse_imgproxy_health_recheck');

        $this->assertSame(
            0,
            did_action('oxpulse_recheck_social_jpeg'),
            'The cron callback must be a no-op when no endpoint is configured',
        );
    }

    // ─── helpers ──────────────────────────────────────────────────────

    private function buildPluginStub(): \OXPulse\Imager\Plugin
    {
        $ref = new \ReflectionClass(\OXPulse\Imager\Plugin::class);
        return $ref->newInstanceWithoutConstructor();
    }

    private function invokePrivate(string $method, array $args = []): void
    {
        $ref = new \ReflectionMethod(ServiceRegistrar::class, $method);
        $ref->setAccessible(true);
        $ref->invoke(null, ...$args);
    }

    private function findUpdatedOptionCallback(): ?callable
    {
        $actions = $GLOBALS['__oxpulse_actions'] ?? [];
        foreach ($actions as $entry) {
            if ($entry['hook'] === 'updated_option' && is_callable($entry['callback'])) {
                return $entry['callback'];
            }
        }
        return null;
    }
}
