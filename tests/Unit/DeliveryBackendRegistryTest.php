<?php
/**
 * DeliveryBackendRegistry tests.
 *
 * Verifies the ranked, health-gated selection:
 *  - applicable-filter (inapplicable providers skipped).
 *  - priority sort DESC (higher priority preferred).
 *  - skip-Down fallthrough (a Down provider is skipped → next best).
 *  - memoization (one decision per registry instance).
 *  - signing === null → null (short-circuit, no provider consulted).
 *  - the oxpulse_delivery_backends filter lets a registered 4th fake
 *    provider participate AND reorder (a higher-priority fake wins).
 *
 * Uses stub providers (anonymous classes) so the registry's SELECTION
 * LOGIC is tested in isolation from the real providers' behavior.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\BackendHealth;
use OXPulse\Imager\Application\Delivery\DeliveryBackend;
use OXPulse\Imager\Application\Delivery\DeliveryBackendProvider;
use OXPulse\Imager\Application\Delivery\DeliveryBackendRegistry;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackend;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackendProvider;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use OXPulse\Imager\Infrastructure\Local\HttpRequester;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;
use OXPulse\Imager\Infrastructure\Local\LocalBackendProvider;
use OXPulse\Imager\Infrastructure\Image\ImageTransformer;
use PHPUnit\Framework\TestCase;

class DeliveryBackendRegistryTest extends TestCase
{
    private const KEY_HEX = '736563726574';
    private const SALT_HEX = '68656C6C6F';
    private const ENDPOINT = 'https://imgproxy.example.com';
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_transients'] = [];
        $GLOBALS['__oxpulse_options'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_filters']);
        unset($GLOBALS['__oxpulse_transients']);
        unset($GLOBALS['__oxpulse_options']);
    }

    private function delivery(string $endpoint = self::ENDPOINT, string $sourceMode = 'http'): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: true,
            endpoint: $endpoint,
            allowedSources: [self::ALLOWED],
            sourceMode: $sourceMode,
        );
    }

    private function signing(): SigningConfig
    {
        return SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);
    }

    // ─── applicable-filter ───────────────────────────────────────────

    public function test_inapplicable_providers_are_skipped(): void
    {
        $inapplicable = new StubProvider('a', 100, false, BackendHealth::Healthy, 'a-built');
        $applicable = new StubProvider('b', 50, true, BackendHealth::Healthy, 'b-built');
        $registry = new DeliveryBackendRegistry($inapplicable, $applicable);

        $backend = $registry->select($this->delivery(), $this->signing());

        $this->assertSame('b-built', $backend->id);
        $this->assertFalse($inapplicable->buildCalled, 'inapplicable provider must not be built');
        $this->assertTrue($applicable->buildCalled);
    }

    // ─── priority sort DESC ──────────────────────────────────────────

    public function test_higher_priority_wins_when_both_applicable_and_healthy(): void
    {
        $high = new StubProvider('high', 100, true, BackendHealth::Healthy, 'high-built');
        $low = new StubProvider('low', 50, true, BackendHealth::Healthy, 'low-built');
        // Pass them in LOW-first order to prove the registry sorts, not just takes input order.
        $registry = new DeliveryBackendRegistry($low, $high);

        $backend = $registry->select($this->delivery(), $this->signing());

        $this->assertSame('high-built', $backend->id);
        $this->assertTrue($high->buildCalled);
        $this->assertFalse($low->buildCalled, 'lower-priority provider must not be built when higher wins');
    }

    public function test_priority_tie_keeps_input_order_stable(): void
    {
        $first = new StubProvider('first', 50, true, BackendHealth::Healthy, 'first-built');
        $second = new StubProvider('second', 50, true, BackendHealth::Healthy, 'second-built');
        $registry = new DeliveryBackendRegistry($first, $second);

        $backend = $registry->select($this->delivery(), $this->signing());

        $this->assertSame('first-built', $backend->id);
    }

    // ─── skip-Down fallthrough ───────────────────────────────────────

    public function test_down_provider_is_skipped_falls_to_next(): void
    {
        $down = new StubProvider('down', 100, true, BackendHealth::Down, 'down-built');
        $up = new StubProvider('up', 50, true, BackendHealth::Healthy, 'up-built');
        $registry = new DeliveryBackendRegistry($down, $up);

        $backend = $registry->select($this->delivery(), $this->signing());

        $this->assertSame('up-built', $backend->id);
        $this->assertFalse($down->buildCalled, 'Down provider must not be built');
        $this->assertTrue($up->buildCalled);
    }

    public function test_degraded_is_selectable_not_skipped(): void
    {
        $degraded = new StubProvider('degraded', 100, true, BackendHealth::Degraded, 'degraded-built');
        $lower = new StubProvider('lower', 50, true, BackendHealth::Healthy, 'lower-built');
        $registry = new DeliveryBackendRegistry($degraded, $lower);

        $backend = $registry->select($this->delivery(), $this->signing());

        $this->assertSame('degraded-built', $backend->id, 'Degraded is selectable — must NOT fall through to a lower-priority provider');
    }

    public function test_all_down_returns_null_via_passthrough_floor(): void
    {
        $down = new StubProvider('down', 100, true, BackendHealth::Down, 'down-built');
        $passthrough = new StubProvider('passthrough', 0, true, BackendHealth::Healthy, null);
        $registry = new DeliveryBackendRegistry($down, $passthrough);

        $backend = $registry->select($this->delivery(), $this->signing());

        $this->assertNull($backend, 'passthrough build()=null → registry returns null (preserve original URL)');
    }

    // ─── memoization ─────────────────────────────────────────────────

    public function test_select_memoizes_one_decision_per_instance(): void
    {
        $provider = new StubProvider('p', 100, true, BackendHealth::Healthy, 'p-built');
        $registry = new DeliveryBackendRegistry($provider);

        $first = $registry->select($this->delivery(), $this->signing());
        $second = $registry->select($this->delivery(), $this->signing());

        $this->assertSame($first, $second, 'memoized selection must return the same instance');
        $this->assertSame(1, $provider->buildCount, 'build() must run ONCE for a memoized registry');
    }

    public function test_memoization_is_per_registry_instance(): void
    {
        $provider = new StubProvider('p', 100, true, BackendHealth::Healthy, 'p-built');
        $r1 = new DeliveryBackendRegistry($provider);
        $r2 = new DeliveryBackendRegistry($provider);

        $b1 = $r1->select($this->delivery(), $this->signing());
        $b2 = $r2->select($this->delivery(), $this->signing());

        $this->assertNotSame($b1, $b2, 'separate registry instances must NOT share memoized state');
    }

    // ─── signing === null → null (short-circuit) ─────────────────────

    public function test_null_signing_returns_null_without_consulting_providers(): void
    {
        $provider = new StubProvider('p', 100, true, BackendHealth::Healthy, 'p-built');
        $registry = new DeliveryBackendRegistry($provider);

        $backend = $registry->select($this->delivery(), null);

        $this->assertNull($backend);
        $this->assertFalse($provider->healthCalled, 'null signing must short-circuit before health()');
        $this->assertFalse($provider->buildCalled, 'null signing must short-circuit before build()');
    }

    // ─── oxpulse_delivery_backends filter ────────────────────────────

    public function test_default_registry_filter_lets_a_4th_fake_provider_win(): void
    {
        // Register a 4th fake provider at priority 200 via the filter.
        // It must outrank the 3 core providers and be selected.
        $fake = new StubProvider('fake', 200, true, BackendHealth::Healthy, 'fake-built');
        add_filter('oxpulse_delivery_backends', static function (array $providers) use ($fake): array {
            $providers[] = $fake;
            return $providers;
        }, 10, 1);

        $registry = DeliveryBackendRegistry::default($this->delivery(), $this->signing());
        $backend = $registry->select($this->delivery(), $this->signing());

        $this->assertSame('fake-built', $backend->id);
        $this->assertTrue($fake->buildCalled);
    }

    public function test_default_registry_filter_can_reorder_to_demote_imgproxy(): void
    {
        // A filter that REMOVES imgproxy and inserts a fake at lower
        // priority than local — local must win (imgproxy gone, fake
        // lower than local's 50).
        $fake = new StubProvider('fake', 10, true, BackendHealth::Healthy, 'fake-built');
        add_filter('oxpulse_delivery_backends', static function (array $providers) use ($fake): array {
            // Strip the real imgproxy provider, keep local + passthrough, add fake.
            return array_filter($providers, fn($p) => $p->id() !== 'imgproxy') + [50 => $fake];
        }, 10, 1);

        $registry = DeliveryBackendRegistry::default($this->delivery(''), $this->signing());
        $backend = $registry->select($this->delivery(''), $this->signing());

        // Local (priority 50) beats fake (priority 10) — and imgproxy
        // was removed. Local needs an encoder; the test host may not
        // have one, so accept either LocalBackend (encoder present) or
        // the fake (if local is Down).
        $this->assertTrue(
            $backend instanceof LocalBackend || ($backend instanceof StubBackend && $backend->id === 'fake-built'),
            'imgproxy removed by filter; local or fake must win, not imgproxy',
        );
    }

    public function test_default_registry_builds_three_core_providers_without_filter(): void
    {
        $registry = DeliveryBackendRegistry::default($this->delivery(), $this->signing());
        $reflection = new \ReflectionProperty(DeliveryBackendRegistry::class, 'providers');
        $providers = $reflection->getValue($registry);

        $ids = array_map(fn($p) => $p->id(), $providers);
        $this->assertSame(['imgproxy', 'local', 'passthrough'], $ids);
    }

    /**
     * A misbehaving oxpulse_delivery_backends filter that returns a
     * NON-array (null / scalar / false) must NOT drop the core
     * providers — the registry falls back to the 3 core providers
     * instead of emitting a PHP 8 foreach-warning and yielding 0.
     *
     * @dataProvider nonArrayFilterReturns
     */
    public function test_default_registry_falls_back_to_core_when_filter_returns_non_array($badReturn): void
    {
        add_filter('oxpulse_delivery_backends', static function () use ($badReturn) {
            return $badReturn;
        }, 10, 1);

        $registry = DeliveryBackendRegistry::default($this->delivery(), $this->signing());
        $reflection = new \ReflectionProperty(DeliveryBackendRegistry::class, 'providers');
        $providers = $reflection->getValue($registry);

        $ids = array_map(fn($p) => $p->id(), $providers);
        $this->assertSame(['imgproxy', 'local', 'passthrough'], $ids, 'non-array filter return must fall back to the 3 core providers');

        // Floor + core intact: imgproxy is endpoint-set + healthy → selected.
        $backend = $registry->select($this->delivery(), $this->signing());
        $this->assertInstanceOf(ImgproxyBackend::class, $backend);
    }

    public static function nonArrayFilterReturns(): array
    {
        return [
            'null'   => [null],
            'scalar' => ['not-an-array'],
            'false'  => [false],
        ];
    }

    // ─── integration: default registry parity with the old factory ───

    public function test_default_registry_selects_imgproxy_when_endpoint_set_and_healthy(): void
    {
        $registry = DeliveryBackendRegistry::default($this->delivery(), $this->signing());
        $backend = $registry->select($this->delivery(), $this->signing());

        $this->assertInstanceOf(ImgproxyBackend::class, $backend);
    }

    public function test_default_registry_falls_through_to_local_when_imgproxy_cached_down(): void
    {
        // Mark imgproxy health Down in the cache.
        $cache = new ImgproxyHealthCache();
        $cache->write('down');

        $registry = DeliveryBackendRegistry::default($this->delivery(), $this->signing());
        $backend = $registry->select($this->delivery(), $this->signing());

        // imgproxy Down → fall through to local (if the host can encode)
        // or passthrough (null) if local is also Down. The test host
        // likely has GD webp, so LocalBackend is expected; accept null
        // (passthrough) as the floor too — but NEVER ImgproxyBackend.
        $this->assertNotInstanceOf(ImgproxyBackend::class, $backend, 'cached-Down imgproxy must NOT be selected');
        $this->assertTrue(
            $backend instanceof LocalBackend || $backend === null,
            'must fall through to LocalBackend or passthrough (null), not imgproxy',
        );
    }
}

/**
 * Stub provider for registry selection-logic tests. Returns canned
 * id/priority/applicability/health/build values and records call state
 * so tests can assert which provider was consulted/built.
 */
class StubProvider implements DeliveryBackendProvider
{
    public bool $buildCalled = false;
    public int $buildCount = 0;
    public bool $healthCalled = false;

    public function __construct(
        private string $id,
        private int $priority,
        private bool $applicable,
        private BackendHealth $health,
        private ?string $builtId,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function isApplicable(DeliveryConfig $config, ?SigningConfig $signing): bool
    {
        return $this->applicable;
    }

    public function health(DeliveryConfig $config): BackendHealth
    {
        $this->healthCalled = true;
        return $this->health;
    }

    public function build(DeliveryConfig $config, SigningConfig $signing): ?DeliveryBackend
    {
        $this->buildCalled = true;
        $this->buildCount++;
        if ($this->builtId === null) {
            return null;
        }
        return new StubBackend($this->builtId);
    }
}

/**
 * Stub DeliveryBackend carrying a public $id marker so registry tests
 * can assert WHICH provider was selected without coupling to the real
 * backend classes. Satisfies the DeliveryBackend interface.
 */
class StubBackend implements DeliveryBackend
{
    public function __construct(public readonly ?string $id) {}

    public function available(): bool
    {
        return true;
    }

    public function generate(\OXPulse\Imager\Domain\Transform\TransformRequest $request, ?string $filename = null): string
    {
        return '';
    }
}
