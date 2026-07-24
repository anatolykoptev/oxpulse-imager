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
        $GLOBALS['__oxpulse_scheduled_events'] = [];
        $GLOBALS['__oxpulse_is_admin'] = false;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
        unset($GLOBALS['__oxpulse_actions']);
        unset($GLOBALS['__oxpulse_filters']);
        unset($GLOBALS['__oxpulse_did_action']);
        unset($GLOBALS['__oxpulse_transients']);
        unset($GLOBALS['__oxpulse_scheduled_events']);
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

    // ─── #81: periodic re-probe via WP-cron (recovery + newly-dead) ───

    public function test_activation_schedules_imgproxy_health_recheck_cron(): void
    {
        oxpulse_imager_activate();

        $this->assertNotFalse(
            wp_next_scheduled('oxpulse_imgproxy_health_recheck'),
            'Activation must schedule the recurring imgproxy health recheck cron',
        );
    }

    public function test_deactivation_clears_imgproxy_health_recheck_cron(): void
    {
        // Schedule the event (simulating a prior activation).
        wp_schedule_event(time(), 'hourly', 'oxpulse_imgproxy_health_recheck');
        $this->assertNotFalse(wp_next_scheduled('oxpulse_imgproxy_health_recheck'));

        oxpulse_imager_deactivate();

        $this->assertFalse(
            wp_next_scheduled('oxpulse_imgproxy_health_recheck'),
            'Deactivation must clear the scheduled cron so it does not fire while inactive',
        );
    }

    public function test_cron_callback_is_registered_at_bootstrap(): void
    {
        // The callback must be registered via add_action at plugin
        // bootstrap so WP-cron can fire it. This is the WIRING that
        // must not be missed — a cron event without a registered
        // callback is a no-op.
        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerImgproxyHealthCron', [$plugin]);

        $this->assertNotFalse(
            has_action('oxpulse_imgproxy_health_recheck'),
            'The cron callback must be registered via add_action at bootstrap',
        );
    }

    public function test_cron_callback_invokes_recheck_imgproxy_health_when_endpoint_set(): void
    {
        // Register the cron callback, then fire the cron hook. The
        // callback must call recheckImgproxyHealth() — verified via
        // the marker action oxpulse_recheck_imgproxy_health.
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerImgproxyHealthCron', [$plugin]);

        do_action('oxpulse_imgproxy_health_recheck');

        $this->assertGreaterThan(
            0,
            did_action('oxpulse_recheck_imgproxy_health'),
            'The cron callback must invoke recheckImgproxyHealth() when the endpoint is set',
        );
    }

    public function test_cron_callback_is_noop_when_endpoint_empty(): void
    {
        // When no endpoint is configured (LocalBackend active), the
        // cron callback must be a no-op — recheckImgproxyHealth() is
        // self-guarding via isLocalBackendActive().
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerImgproxyHealthCron', [$plugin]);

        do_action('oxpulse_imgproxy_health_recheck');

        $this->assertSame(
            0,
            did_action('oxpulse_recheck_imgproxy_health'),
            'The cron callback must be a no-op when no endpoint is configured',
        );
    }

    // ─── #84: self-heal cron on upgrade (init guard-schedule) ─────────

    public function test_init_self_heals_cron_when_unscheduled_on_upgrade(): void
    {
        // Simulate an already-active install that UPGRADED in place
        // (activation hook did NOT fire for this load). The recurring
        // event is unscheduled. Bootstrap registerImgproxyHealthCron
        // registers the callback AND an init guard; firing init must
        // schedule the event so the install converges without a
        // deactivate→reactivate.
        $this->assertFalse(
            wp_next_scheduled('oxpulse_imgproxy_health_recheck'),
            'Precondition: event must be unscheduled (upgrade path)',
        );

        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerImgproxyHealthCron', [$plugin]);

        do_action('init');

        $this->assertNotFalse(
            wp_next_scheduled('oxpulse_imgproxy_health_recheck'),
            'init must guard-schedule the recurring cron when unscheduled (upgrade self-heal)',
        );
    }

    public function test_init_does_not_double_schedule_when_cron_already_scheduled(): void
    {
        // Idempotency: when the event is already scheduled (e.g. a
        // fresh-activate install that DID run the activation hook),
        // firing init must NOT create a second schedule and must NOT
        // shift the existing timestamp. The wp_next_scheduled guard
        // makes the activation-hook schedule and the init guard
        // idempotent.
        $existingTimestamp = time() + 3600;
        wp_schedule_event($existingTimestamp, 'hourly', 'oxpulse_imgproxy_health_recheck');
        $this->assertSame(
            $existingTimestamp,
            wp_next_scheduled('oxpulse_imgproxy_health_recheck'),
            'Precondition: event is scheduled at a known timestamp',
        );

        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerImgproxyHealthCron', [$plugin]);

        do_action('init');

        $matching = 0;
        foreach ($GLOBALS['__oxpulse_scheduled_events'] as $event) {
            if ($event['hook'] === 'oxpulse_imgproxy_health_recheck') {
                $matching++;
            }
        }
        $this->assertSame(1, $matching, 'init must not add a second schedule entry when one already exists');
        $this->assertSame(
            $existingTimestamp,
            wp_next_scheduled('oxpulse_imgproxy_health_recheck'),
            'init must not shift the existing scheduled timestamp',
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

    public function getImage(string $url): array
    {
        return ['status' => $this->status, 'content_type' => '', 'error' => $this->error];
    }
}
