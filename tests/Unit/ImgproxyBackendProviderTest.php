<?php
/**
 * ImgproxyBackendProvider tests.
 *
 * Verifies the imgproxy provider's:
 * - isApplicable truth table (endpoint set + non-local-backend-active).
 * - health() reads the cache ONLY (zero HttpRequester calls on the
 *   front-end render path) — the BLOCKER regression lock.
 * - health() maps cached 'up' → Healthy, cached 'down' → Down.
 * - build() returns an ImgproxyBackend.
 * - recheck() probes the endpoint via HttpRequester::head() and writes
 *   the cache: 2xx/3xx → up, 502 → down, WP_Error/timeout → down.
 * - recheck() touches ONLY the admin-configured endpoint host (the
 *   URL passed to head() is exactly the configured endpoint, no
 *   derivation).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\BackendHealth;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackend;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackendProvider;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use OXPulse\Imager\Infrastructure\Local\HttpRequester;
use PHPUnit\Framework\TestCase;

class ImgproxyBackendProviderTest extends TestCase
{
    private const KEY_HEX = '736563726574';
    private const SALT_HEX = '68656C6C6F';
    private const ENDPOINT = 'https://imgproxy.example.com';
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_transients'] = [];
        $GLOBALS['__oxpulse_options'] = [];
    }

    protected function tearDown(): void
    {
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

    // ─── id / priority ───────────────────────────────────────────────

    public function test_id_is_imgproxy(): void
    {
        $provider = new ImgproxyBackendProvider(new RecordingHttpRequester(), new ImgproxyHealthCache());
        $this->assertSame('imgproxy', $provider->id());
    }

    public function test_priority_is_100(): void
    {
        $provider = new ImgproxyBackendProvider(new RecordingHttpRequester(), new ImgproxyHealthCache());
        $this->assertSame(100, $provider->priority());
    }

    // ─── isApplicable truth table ────────────────────────────────────

    public function test_is_applicable_when_endpoint_set(): void
    {
        $provider = new ImgproxyBackendProvider(new RecordingHttpRequester(), new ImgproxyHealthCache());
        $this->assertTrue($provider->isApplicable($this->delivery(), $this->signing()));
    }

    public function test_is_applicable_when_endpoint_set_and_signing_null(): void
    {
        // Endpoint set but no signing — still applicable at the
        // provider level (the registry short-circuits on null signing
        // before reaching providers; isApplicable is config-presence only).
        $provider = new ImgproxyBackendProvider(new RecordingHttpRequester(), new ImgproxyHealthCache());
        $this->assertTrue($provider->isApplicable($this->delivery(), null));
    }

    public function test_is_not_applicable_when_endpoint_empty(): void
    {
        $provider = new ImgproxyBackendProvider(new RecordingHttpRequester(), new ImgproxyHealthCache());
        $this->assertFalse($provider->isApplicable($this->delivery(''), $this->signing()));
    }

    // ─── health() is cache-only (BLOCKER regression lock) ────────────

    public function test_health_reads_cache_default_healthy_when_unset(): void
    {
        $http = new RecordingHttpRequester();
        $provider = new ImgproxyBackendProvider($http, new ImgproxyHealthCache());

        $this->assertSame(BackendHealth::Healthy, $provider->health($this->delivery()));
        $this->assertSame(0, $http->headCalls, 'health() must make ZERO HttpRequester calls (front-end-safe)');
        $this->assertSame(0, $http->getCalls, 'health() must make ZERO HttpRequester calls (front-end-safe)');
    }

    public function test_health_returns_down_when_cache_says_down(): void
    {
        $cache = new ImgproxyHealthCache();
        $cache->write('down');
        $http = new RecordingHttpRequester();
        $provider = new ImgproxyBackendProvider($http, $cache);

        $this->assertSame(BackendHealth::Down, $provider->health($this->delivery()));
        $this->assertSame(0, $http->headCalls, 'health() must make ZERO HttpRequester calls even when Down');
    }

    public function test_health_returns_healthy_after_cache_write_up(): void
    {
        $cache = new ImgproxyHealthCache();
        $cache->write('down');
        $cache->write('up');
        $provider = new ImgproxyBackendProvider(new RecordingHttpRequester(), $cache);

        $this->assertSame(BackendHealth::Healthy, $provider->health($this->delivery()));
    }

    // ─── build() ─────────────────────────────────────────────────────

    public function test_build_returns_imgproxy_backend(): void
    {
        $provider = new ImgproxyBackendProvider(new RecordingHttpRequester(), new ImgproxyHealthCache());
        $backend = $provider->build($this->delivery(), $this->signing());

        $this->assertInstanceOf(ImgproxyBackend::class, $backend);
    }

    // ─── recheck() — write-time bounded probe ────────────────────────

    public function test_recheck_2xx_writes_up(): void
    {
        $http = new RecordingHttpRequester(status: 200);
        $cache = new ImgproxyHealthCache();
        $provider = new ImgproxyBackendProvider($http, $cache);

        $provider->recheck($this->delivery());

        $this->assertSame(1, $http->headCalls);
        $this->assertSame('up', $cache->read());
    }

    public function test_recheck_3xx_writes_up(): void
    {
        // 3xx is treated as up (the endpoint responded; redirection=0
        // means WP returns the 3xx as-is, which still proves reachability).
        $http = new RecordingHttpRequester(status: 302);
        $cache = new ImgproxyHealthCache();
        $provider = new ImgproxyBackendProvider($http, $cache);

        $provider->recheck($this->delivery());

        $this->assertSame('up', $cache->read());
    }

    public function test_recheck_502_writes_down(): void
    {
        $http = new RecordingHttpRequester(status: 502);
        $cache = new ImgproxyHealthCache();
        $provider = new ImgproxyBackendProvider($http, $cache);

        $provider->recheck($this->delivery());

        $this->assertSame('down', $cache->read());
    }

    public function test_recheck_404_writes_down(): void
    {
        $http = new RecordingHttpRequester(status: 404);
        $cache = new ImgproxyHealthCache();
        $provider = new ImgproxyBackendProvider($http, $cache);

        $provider->recheck($this->delivery());

        $this->assertSame('down', $cache->read());
    }

    public function test_recheck_wp_error_writes_down(): void
    {
        $http = new RecordingHttpRequester(error: 'connection refused');
        $cache = new ImgproxyHealthCache();
        $provider = new ImgproxyBackendProvider($http, $cache);

        $provider->recheck($this->delivery());

        $this->assertSame('down', $cache->read());
    }

    public function test_recheck_timeout_status_zero_writes_down(): void
    {
        $http = new RecordingHttpRequester(status: 0);
        $cache = new ImgproxyHealthCache();
        $provider = new ImgproxyBackendProvider($http, $cache);

        $provider->recheck($this->delivery());

        $this->assertSame('down', $cache->read());
    }

    // ─── recheck() touches ONLY the configured endpoint (no SSRF) ────

    public function test_recheck_probes_exactly_the_configured_endpoint(): void
    {
        $http = new RecordingHttpRequester(status: 200);
        $provider = new ImgproxyBackendProvider($http, new ImgproxyHealthCache());

        $provider->recheck($this->delivery('https://imgproxy.example.com'));

        $this->assertSame('https://imgproxy.example.com', $http->lastHeadUrl);
        $this->assertNotSame('https://imgproxy.example.com/health', $http->lastHeadUrl, 'must probe the configured host root, not a derived path');
    }

    public function test_recheck_does_not_fire_when_endpoint_empty(): void
    {
        $http = new RecordingHttpRequester(status: 200);
        $provider = new ImgproxyBackendProvider($http, new ImgproxyHealthCache());

        $provider->recheck($this->delivery(''));

        $this->assertSame(0, $http->headCalls, 'recheck must not probe when no endpoint is configured');
    }

    // ─── #81: recovery + newly-dead detection via recheck ───────────

    public function test_recheck_recovers_down_to_up_when_probe_succeeds(): void
    {
        // Recovery: option holds 'down' (imgproxy was dead), a later
        // recheck probes 200 → writes 'up' so the registry re-promotes
        // imgproxy. This is the cron's primary job (bounds recovery
        // latency without waiting for a settings-save).
        $http = new RecordingHttpRequester(status: 200);
        $cache = new ImgproxyHealthCache();
        $cache->write('down');
        $provider = new ImgproxyBackendProvider($http, $cache);

        $provider->recheck($this->delivery());

        $this->assertSame('up', $cache->read(), 'A 200 probe after a prior down must write up (recovery)');
    }

    public function test_recheck_detects_newly_dead_up_to_down_when_probe_fails(): void
    {
        // Newly-dead detection: option holds 'up' (imgproxy was healthy),
        // a later recheck probes 502 → writes 'down' so the registry
        // falls through to LocalBackend. This is the cron's secondary
        // job (bounds re-detection latency without waiting for a save).
        $http = new RecordingHttpRequester(status: 502);
        $cache = new ImgproxyHealthCache();
        $cache->write('up');
        $provider = new ImgproxyBackendProvider($http, $cache);

        $provider->recheck($this->delivery());

        $this->assertSame('down', $cache->read(), 'A 502 probe after a prior up must write down (newly-dead)');
    }
}

/**
 * Recording HttpRequester stub: returns a canned head()/get() response
 * and records call counts + the last probed URL so tests can assert the
 * front-end path makes ZERO calls and recheck probes the right host.
 */
class RecordingHttpRequester implements HttpRequester
{
    public int $headCalls = 0;
    public int $getCalls = 0;
    public string $lastHeadUrl = '';

    public function __construct(
        private int $status = 200,
        private ?string $error = null,
    ) {}

    public function get(string $url): array
    {
        $this->getCalls++;
        return ['status' => $this->status, 'body' => '', 'error' => $this->error];
    }

    public function head(string $url): array
    {
        $this->headCalls++;
        $this->lastHeadUrl = $url;
        return ['status' => $this->status, 'body' => '', 'error' => $this->error];
    }
}
