<?php
/**
 * ServiceRegistrar write-time rewrite-capability probe trigger tests.
 *
 * #43 Phase 1 review (BLOCKER wire): verifies the live probe fires ONLY
 * at write-time (activation / settings-save when LocalBackend becomes
 * active / once-per-version re-probe on plugin update) — never from the
 * front-end read path, never on every admin load.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Local\CapabilityTester;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use PHPUnit\Framework\TestCase;

class ServiceRegistrarRewriteCapabilityTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_did_action'] = [];
        $GLOBALS['__oxpulse_is_admin'] = false;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
        unset($GLOBALS['__oxpulse_actions']);
        unset($GLOBALS['__oxpulse_filters']);
        unset($GLOBALS['__oxpulse_did_action']);
        unset($GLOBALS['__oxpulse_is_admin']);
    }

    // ─── recheckRewriteCapability() direct behavior ─────────────────────

    /**
     * When LocalBackend is active (endpoint empty), recheckRewriteCapability()
     * invokes the probe on the injected tester.
     */
    public function test_recheck_fires_when_endpoint_empty(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        $probe = new SyncStubProbe('yes');
        $tester = new CapabilityTester($probe);

        ServiceRegistrar::recheckRewriteCapability($tester);

        $this->assertTrue($probe->wasCalled, 'Probe must fire when endpoint is empty');
        $this->assertGreaterThan(
            0,
            did_action('oxpulse_recheck_rewrite_capability'),
            'recheckRewriteCapability must fire the marker action when endpoint is empty',
        );
        $this->assertSame(
            'yes',
            get_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY),
        );
    }

    /**
     * When an imgproxy endpoint is configured, recheckRewriteCapability()
     * is a no-op — the probe must NOT fire (ImgproxyBackend manages its
     * own cache; the capability probe is irrelevant).
     */
    public function test_recheck_does_not_fire_when_endpoint_configured(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        $probe = new SyncStubProbe('yes');
        $tester = new CapabilityTester($probe);

        ServiceRegistrar::recheckRewriteCapability($tester);

        $this->assertFalse($probe->wasCalled, 'Probe must NOT fire when endpoint is configured');
        $this->assertSame(
            0,
            did_action('oxpulse_recheck_rewrite_capability'),
            'recheck must NOT fire the marker action when endpoint is configured',
        );
    }

    /**
     * A relative endpoint ('/imgproxy') resolves to a non-empty absolute
     * URL → ImgproxyBackend active → probe must NOT fire.
     */
    public function test_recheck_does_not_fire_for_relative_endpoint(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '/imgproxy');
        $probe = new SyncStubProbe('yes');
        $tester = new CapabilityTester($probe);

        ServiceRegistrar::recheckRewriteCapability($tester);

        $this->assertFalse($probe->wasCalled);
        $this->assertSame(0, did_action('oxpulse_recheck_rewrite_capability'));
    }

    // ─── activation hook triggers recheck when LocalBackend active ───────

    /**
     * oxpulse_imager_activate() must trigger recheckRewriteCapability()
     * when LocalBackend will be active (endpoint empty). Verified via
     * the static counter (activation builds a real tester; the counter
     * records that recheck was invoked).
     */
    public function test_activation_triggers_recheck_when_endpoint_empty(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');

        oxpulse_imager_activate();

        $this->assertGreaterThan(
            0,
            did_action('oxpulse_recheck_rewrite_capability'),
            'Activation with LocalBackend active (endpoint empty) must trigger recheck',
        );
    }

    /**
     * When an imgproxy endpoint is configured at activation, the probe
     * must NOT fire (recheckRewriteCapability is a no-op).
     */
    public function test_activation_does_not_recheck_when_endpoint_configured(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');

        oxpulse_imager_activate();

        $this->assertSame(
            0,
            did_action('oxpulse_recheck_rewrite_capability'),
            'Activation with an imgproxy endpoint must NOT trigger recheck',
        );
    }

    // ─── settings-save (updated_option) triggers recheck ─────────────────

    /**
     * The updated_option hook registered by
     * registerLocalDeliverySettingsSync() must invoke recheck when
     * OPTION_ENDPOINT changes. Verified by invoking the registered
     * callback with OPTION_ENDPOINT and an empty endpoint option.
     */
    public function test_settings_save_triggers_recheck_when_endpoint_option_changes(): void
    {
        // Register the hook (registers the updated_option callback).
        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerLocalDeliverySettingsSync', [$plugin]);

        // LocalBackend active (endpoint empty).
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');

        $callback = $this->findUpdatedOptionCallback();
        $this->assertNotNull($callback, 'updated_option callback must be registered');

        // Simulate WP firing updated_option for OPTION_ENDPOINT.
        $callback(OptionSettingsRepository::OPTION_ENDPOINT);

        $this->assertGreaterThan(
            0,
            did_action('oxpulse_recheck_rewrite_capability'),
            'Settings-save (OPTION_ENDPOINT change) with LocalBackend active must trigger recheck',
        );
    }

    /**
     * A non-endpoint option change must NOT trigger recheck (only
     * OPTION_ENDPOINT is watched for the capability probe).
     */
    public function test_settings_save_non_endpoint_option_does_not_recheck(): void
    {
        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerLocalDeliverySettingsSync', [$plugin]);
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');

        $callback = $this->findUpdatedOptionCallback();
        $this->assertNotNull($callback);
        $callback(OptionSettingsRepository::OPTION_KEY);

        $this->assertSame(
            0,
            did_action('oxpulse_recheck_rewrite_capability'),
            'A non-endpoint option change must NOT trigger recheck',
        );
    }

    // ─── version-gated re-probe (admin-only, once per version) ───────────

    /**
     * maybeReprobeOnVersionUpdate() fires recheck once when the stored
     * probe version does not match the plugin version (admin context).
     */
    public function test_version_mismatch_in_admin_triggers_recheck(): void
    {
        $GLOBALS['__oxpulse_is_admin'] = true;
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        // Stored version differs from OXPULSE_IMAGER_VERSION ('0.1.0').
        update_option(OptionSettingsRepository::OPTION_PROBE_VERSION, '0.0.9');

        $this->invokePrivate('maybeReprobeOnVersionUpdate');

        $this->assertGreaterThan(0, did_action('oxpulse_recheck_rewrite_capability'));
        $this->assertSame(
            OXPULSE_IMAGER_VERSION,
            get_option(OptionSettingsRepository::OPTION_PROBE_VERSION),
            'Probe version must be stamped after the re-probe',
        );
    }

    /**
     * When the stored probe version matches the plugin version, the
     * re-probe must NOT fire (once-per-version guard — not every admin load).
     */
    public function test_version_match_in_admin_does_not_recheck(): void
    {
        $GLOBALS['__oxpulse_is_admin'] = true;
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        update_option(OptionSettingsRepository::OPTION_PROBE_VERSION, OXPULSE_IMAGER_VERSION);

        $this->invokePrivate('maybeReprobeOnVersionUpdate');

        $this->assertSame(0, did_action('oxpulse_recheck_rewrite_capability'));
    }

    /**
     * The version-gated re-probe is admin-only — must NOT fire on the
     * front-end (is_admin() false), even when the version mismatches.
     */
    public function test_version_mismatch_on_frontend_does_not_recheck(): void
    {
        $GLOBALS['__oxpulse_is_admin'] = false;
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        update_option(OptionSettingsRepository::OPTION_PROBE_VERSION, '0.0.9');

        $this->invokePrivate('maybeReprobeOnVersionUpdate');

        $this->assertSame(
            0,
            did_action('oxpulse_recheck_rewrite_capability'),
            'Version-gated re-probe must NOT fire on the front-end',
        );
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    /**
     * Build a minimal Plugin stub for the settings-sync registrar.
     */
    private function buildPluginStub(): \OXPulse\Imager\Plugin
    {
        $ref = new \ReflectionClass(\OXPulse\Imager\Plugin::class);
        return $ref->newInstanceWithoutConstructor();
    }

    /**
     * Invoke a private static method on ServiceRegistrar via reflection.
     *
     * @param string $method
     * @param array<int,mixed> $args
     */
    private function invokePrivate(string $method, array $args = []): void
    {
        $ref = new \ReflectionMethod(ServiceRegistrar::class, $method);
        $ref->setAccessible(true);
        $ref->invoke(null, ...$args);
    }

    /**
     * Find the updated_option callback registered via add_action.
     */
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
 * Stub LocalRewriteProbe that returns a canned tri-state result.
 */
class SyncStubProbe extends \OXPulse\Imager\Infrastructure\Local\LocalRewriteProbe
{
    public bool $wasCalled = false;

    public function __construct(private string $result)
    {
        parent::__construct(
            '/tmp/stub',
            'https://stub.test/.probe',
            new SyncNullHttpRequester(),
        );
    }

    public function probe(): string
    {
        $this->wasCalled = true;
        return $this->result;
    }
}

class SyncNullHttpRequester implements \OXPulse\Imager\Infrastructure\Local\HttpRequester
{
    public function get(string $url): array
    {
        return ['status' => 0, 'body' => '', 'error' => 'null'];
    }
}
