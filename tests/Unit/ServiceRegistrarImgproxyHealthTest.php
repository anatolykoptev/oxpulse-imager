<?php
/**
 * ServiceRegistrar write-time imgproxy health probe trigger tests.
 *
 * Mirrors ServiceRegistrarRewriteCapabilityTest: verifies the imgproxy
 * health probe fires ONLY at write-time (activation / settings-save
 * when imgproxy is active / once-per-version re-probe) — never from
 * the front-end read path. The probe and the rewrite-capability probe
 * are complementary: exactly one fires on a given endpoint change
 * (imgproxy active = endpoint set; local active = endpoint empty).
 *
 * The test injects a recording HttpRequester + a real ImgproxyHealthCache
 * so the REAL ImgproxyBackendProvider::recheck() is exercised through
 * the wiring (no subclassing — the provider is final).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use OXPulse\Imager\Infrastructure\Local\HttpRequester;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use PHPUnit\Framework\TestCase;

class ServiceRegistrarImgproxyHealthTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_did_action'] = [];
        $GLOBALS['__oxpulse_transients'] = [];
        $GLOBALS['__oxpulse_is_admin'] = false;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
        unset($GLOBALS['__oxpulse_actions']);
        unset($GLOBALS['__oxpulse_filters']);
        unset($GLOBALS['__oxpulse_did_action']);
        unset($GLOBALS['__oxpulse_transients']);
        unset($GLOBALS['__oxpulse_is_admin']);
    }

    // ─── recheckImgproxyHealth() direct behavior ─────────────────────

    public function test_recheck_fires_when_endpoint_configured(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        $http = new RecordingHeadRequester(200);
        $cache = new ImgproxyHealthCache();

        ServiceRegistrar::recheckImgproxyHealth($http, $cache);

        $this->assertSame(1, $http->headCalls, 'Probe must fire (HEAD the endpoint) when an endpoint is configured');
        $this->assertSame('up', $cache->read(), 'A 200 response must write "up" to the cache');
        $this->assertGreaterThan(
            0,
            did_action('oxpulse_recheck_imgproxy_health'),
            'recheckImgproxyHealth must fire the marker action when endpoint is configured',
        );
    }

    public function test_recheck_does_not_fire_when_endpoint_empty(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        $http = new RecordingHeadRequester(200);
        $cache = new ImgproxyHealthCache();

        ServiceRegistrar::recheckImgproxyHealth($http, $cache);

        $this->assertSame(0, $http->headCalls, 'Probe must NOT fire when no endpoint is configured (LocalBackend active)');
        $this->assertSame(0, did_action('oxpulse_recheck_imgproxy_health'));
    }

    public function test_recheck_fires_for_relative_endpoint(): void
    {
        // A relative endpoint ('/imgproxy') is non-empty → imgproxy
        // active → probe must fire against the resolved absolute URL.
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '/imgproxy');
        $http = new RecordingHeadRequester(200);
        $cache = new ImgproxyHealthCache();

        ServiceRegistrar::recheckImgproxyHealth($http, $cache);

        $this->assertSame(1, $http->headCalls);
        $this->assertStringStartsWith('https://', $http->lastHeadUrl, 'relative endpoint must be resolved to absolute before probing');
        $this->assertGreaterThan(0, did_action('oxpulse_recheck_imgproxy_health'));
    }

    public function test_recheck_probes_only_the_configured_endpoint_host(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        $http = new RecordingHeadRequester(200);

        ServiceRegistrar::recheckImgproxyHealth($http, new ImgproxyHealthCache());

        $this->assertSame('https://imgproxy.example.com', $http->lastHeadUrl, 'must probe exactly the configured endpoint, no derivation');
    }

    public function test_recheck_502_writes_down(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        $http = new RecordingHeadRequester(502);
        $cache = new ImgproxyHealthCache();

        ServiceRegistrar::recheckImgproxyHealth($http, $cache);

        $this->assertSame('down', $cache->read());
    }

    // ─── activation hook triggers recheck when imgproxy active ────────

    public function test_activation_triggers_recheck_when_endpoint_configured(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');

        oxpulse_imager_activate();

        $this->assertGreaterThan(
            0,
            did_action('oxpulse_recheck_imgproxy_health'),
            'Activation with an imgproxy endpoint must trigger the imgproxy health recheck',
        );
    }

    public function test_activation_does_not_recheck_imgproxy_when_endpoint_empty(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');

        oxpulse_imager_activate();

        $this->assertSame(
            0,
            did_action('oxpulse_recheck_imgproxy_health'),
            'Activation with no endpoint (LocalBackend active) must NOT trigger the imgproxy recheck',
        );
    }

    // ─── settings-save (updated_option) triggers recheck ──────────────

    public function test_settings_save_triggers_recheck_when_endpoint_option_changes(): void
    {
        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerLocalDeliverySettingsSync', [$plugin]);
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');

        $callback = $this->findUpdatedOptionCallback();
        $this->assertNotNull($callback, 'updated_option callback must be registered');
        $callback(OptionSettingsRepository::OPTION_ENDPOINT);

        $this->assertGreaterThan(
            0,
            did_action('oxpulse_recheck_imgproxy_health'),
            'Settings-save (OPTION_ENDPOINT change) with imgproxy active must trigger the imgproxy recheck',
        );
    }

    // ─── version-gated re-probe (admin-only, once per version) ────────

    public function test_version_mismatch_in_admin_triggers_imgproxy_recheck(): void
    {
        $GLOBALS['__oxpulse_is_admin'] = true;
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        update_option(OptionSettingsRepository::OPTION_PROBE_VERSION, '0.0.9');

        $this->invokePrivate('maybeReprobeOnVersionUpdate');

        $this->assertGreaterThan(0, did_action('oxpulse_recheck_imgproxy_health'));
    }

    public function test_version_mismatch_in_admin_does_not_imgproxy_recheck_when_endpoint_empty(): void
    {
        $GLOBALS['__oxpulse_is_admin'] = true;
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        update_option(OptionSettingsRepository::OPTION_PROBE_VERSION, '0.0.9');

        $this->invokePrivate('maybeReprobeOnVersionUpdate');

        $this->assertSame(
            0,
            did_action('oxpulse_recheck_imgproxy_health'),
            'Version-gated re-probe must NOT fire the imgproxy recheck when LocalBackend is active (endpoint empty)',
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

/**
 * Recording HttpRequester for the imgproxy health wiring tests: returns
 * a canned HEAD response and records the call count + last probed URL.
 */
class RecordingHeadRequester implements HttpRequester
{
    public int $headCalls = 0;
    public string $lastHeadUrl = '';

    public function __construct(private int $status = 200, private ?string $error = null) {}

    public function get(string $url): array
    {
        return ['status' => $this->status, 'body' => '', 'error' => $this->error];
    }

    public function head(string $url): array
    {
        $this->headCalls++;
        $this->lastHeadUrl = $url;
        return ['status' => $this->status, 'body' => '', 'error' => $this->error];
    }
}
