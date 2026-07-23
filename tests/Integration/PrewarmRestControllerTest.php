<?php
/**
 * PrewarmRestController integration tests.
 *
 * Verifies the /oxpulse/v1/prewarm REST endpoint: route registration,
 * permission check, input validation, and response shape. The actual
 * HTTP dispatch is tested via PrewarmServiceTest (with a mock client);
 * here we verify the controller's validation + wiring.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Integration;

use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Integration\WordPress\Admin\PrewarmRestController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

class PrewarmRestControllerTest extends TestCase
{
    private OptionSettingsRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_rest_routes'] = [];
        $GLOBALS['__oxpulse_current_user_can'] = [OXPULSE_IMAGER_CAPABILITY => true];

        $this->repository = new OptionSettingsRepository();
    }

    /**
     * Fire all callbacks registered for a given hook.
     */
    private function fireHook(string $hook): void
    {
        foreach ($GLOBALS['__oxpulse_actions'] ?? [] as $action) {
            if ($action['hook'] === $hook && is_callable($action['callback'])) {
                call_user_func($action['callback']);
            }
        }
    }

    public function test_register_registers_prewarm_route(): void
    {
        $controller = new PrewarmRestController($this->repository);
        $controller->register();
        $this->fireHook('rest_api_init');

        $routes = $GLOBALS['__oxpulse_rest_routes'] ?? [];
        $this->assertArrayHasKey('oxpulse/v1/prewarm', $routes);
    }

    public function test_check_permission_requires_capability(): void
    {
        $GLOBALS['__oxpulse_current_user_can'] = [];
        $controller = new PrewarmRestController($this->repository);
        $this->assertFalse($controller->checkPermission());
    }

    public function test_handle_prewarm_rejects_empty_urls(): void
    {
        $controller = new PrewarmRestController($this->repository);
        $request = new WP_REST_Request(['urls' => []]);

        $response = $controller->handlePrewarm($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('oxpulse_prewarm_no_urls', array_key_first($response->errors));
    }

    public function test_handle_prewarm_rejects_too_many_urls(): void
    {
        $urls = array_fill(0, 51, 'https://example.com/uploads/photo.jpg');

        $controller = new PrewarmRestController($this->repository);
        $request = new WP_REST_Request(['urls' => $urls]);

        $response = $controller->handlePrewarm($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('oxpulse_prewarm_too_many_urls', array_key_first($response->errors));
    }

    public function test_handle_prewarm_rejects_when_delivery_disabled(): void
    {
        // No options set → delivery is disabled by default.
        $controller = new PrewarmRestController($this->repository);
        $request = new WP_REST_Request(['urls' => ['https://example.com/uploads/photo.jpg']]);

        $response = $controller->handlePrewarm($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('oxpulse_prewarm_disabled', array_key_first($response->errors));
    }

    public function test_handle_prewarm_rejects_when_no_endpoint(): void
    {
        // Enable delivery but don't set endpoint.
        $GLOBALS['__oxpulse_options']['oxpulse_imager_enabled'] = true;

        $controller = new PrewarmRestController($this->repository);
        $request = new WP_REST_Request(['urls' => ['https://example.com/uploads/photo.jpg']]);

        $response = $controller->handlePrewarm($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('oxpulse_prewarm_no_endpoint', array_key_first($response->errors));
    }

    public function test_handle_prewarm_rejects_when_no_signing(): void
    {
        // Enable delivery + set endpoint, but no signing secrets.
        $GLOBALS['__oxpulse_options']['oxpulse_imager_enabled'] = true;
        $GLOBALS['__oxpulse_options']['oxpulse_imager_endpoint'] = 'https://imgproxy.example.com';
        $GLOBALS['__oxpulse_options']['oxpulse_imager_allowed_sources'] = ['https://example.com/uploads/'];

        $controller = new PrewarmRestController($this->repository);
        $request = new WP_REST_Request(['urls' => ['https://example.com/uploads/photo.jpg']]);

        $response = $controller->handlePrewarm($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('oxpulse_prewarm_no_signing', array_key_first($response->errors));
    }

    public function test_handle_prewarm_dedupes_and_filters_empty_urls(): void
    {
        $this->setupFullConfig();

        $controller = new PrewarmRestController($this->repository);
        // Two duplicates + one empty → should be deduped to 1.
        $request = new WP_REST_Request([
            'urls' => [
                'https://example.com/uploads/photo.jpg',
                'https://example.com/uploads/photo.jpg',
                '',
            ],
        ]);

        // This will try to dispatch real HTTP — but cURL will fail
        // gracefully (connection refused to imgproxy.example.com).
        // We just verify it doesn't reject at validation.
        $response = $controller->handlePrewarm($request);

        // Should be a WP_REST_Response (not WP_Error) — the dispatch
        // ran, even if all URLs failed to connect.
        if ($response instanceof WP_Error) {
            // If cURL isn't available in the test env, the service
            // returns errors per-URL but the controller still succeeds.
            $this->fail('Expected WP_REST_Response, got WP_Error: ' . $response->get_error_message());
        }

        $data = $response->get_data();
        $this->assertSame(1, $data['total']);
    }

    private function setupFullConfig(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_enabled'] = true;
        $GLOBALS['__oxpulse_options']['oxpulse_imager_endpoint'] = 'https://imgproxy.example.com';
        $GLOBALS['__oxpulse_options']['oxpulse_imager_allowed_sources'] = ['https://example.com/uploads/'];
        $GLOBALS['__oxpulse_options']['oxpulse_imager_key'] = bin2hex(random_bytes(16));
        $GLOBALS['__oxpulse_options']['oxpulse_imager_salt'] = bin2hex(random_bytes(16));
    }

    // ─── #92: async prewarm + DISABLE_WP_CRON ───────────────────────

    /**
     * A spy DiagnosticLoggerInterface that records warning() calls so
     * the controller's "blocked prewarm" log path can be asserted
     * without touching error_log or the diagnostic-level option.
     */
    private function spyLogger(): object
    {
        return new class implements \OXPulse\Imager\Application\Diagnostics\DiagnosticLoggerInterface {
            public array $warnings = [];
            public array $entries = [];
            public function log(\OXPulse\Imager\Domain\Diagnostics\LogEntry $entry): void { $this->entries[] = $entry; }
            public function getEntries(): array { return $this->entries; }
            public function getSummary(): array { return ['rewritten' => 0, 'preserved' => 0, 'total' => count($this->entries)]; }
            public function flush(): void {}
            public function warning(string $message): void { $this->warnings[] = $message; }
        };
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_prewarm_async_reports_unavailability_when_cron_disabled(): void
    {
        define('DISABLE_WP_CRON', true);
        $this->setupFullConfig();
        $GLOBALS['__oxpulse_transients'] = [];
        $GLOBALS['__oxpulse_scheduled_events'] = [];

        $logger = $this->spyLogger();
        $controller = new PrewarmRestController($this->repository, $logger);
        $request = new WP_REST_Request([
            'urls'  => ['https://example.com/uploads/photo.jpg'],
            'async' => true,
        ]);

        $response = $controller->handlePrewarm($request);

        // Must be a WP_Error — NOT a silent success with a jobId.
        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('oxpulse_prewarm_cron_disabled', array_key_first($response->errors));
        $this->assertSame(503, $response->get_error_data()['status'] ?? null);

        // The message must point the operator at BOTH alternatives:
        // the WP-CLI command and the system-cron path.
        $msg = $response->get_error_message();
        $this->assertStringContainsString('WP-Cron', $msg);
        $this->assertStringContainsString('wp oxpulse warm', $msg);
        $this->assertStringContainsString('wp-cron.php', $msg);

        // No cron event scheduled (no dead job queued).
        $this->assertCount(0, $GLOBALS['__oxpulse_scheduled_events'] ?? []);

        // The blocked-attempt transient flag is set so AdminNotice can
        // surface the limitation on the next admin page load.
        $this->assertTrue($GLOBALS['__oxpulse_transients'][\OXPulse\Imager\Integration\WordPress\Admin\PrewarmRestController::CRON_BLOCKED_TRANSIENT] ?? false);

        // The diagnostic logger received a warning (no silent drop).
        $this->assertCount(1, $logger->warnings);
        $this->assertStringContainsString('DISABLE_WP_CRON', $logger->warnings[0]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_prewarm_async_creates_job_when_cron_enabled(): void
    {
        // DISABLE_WP_CRON intentionally NOT defined → cron enabled.
        $this->setupFullConfig();
        $GLOBALS['__oxpulse_transients'] = [];
        $GLOBALS['__oxpulse_scheduled_events'] = [];

        $logger = $this->spyLogger();
        $controller = new PrewarmRestController($this->repository, $logger);
        $request = new WP_REST_Request([
            'urls'  => ['https://example.com/uploads/photo.jpg'],
            'async' => true,
        ]);

        $response = $controller->handlePrewarm($request);

        // Unchanged behavior: a job is created and a cron event scheduled.
        $this->assertNotInstanceOf(WP_Error::class, $response);
        $data = $response->get_data();
        $this->assertNotEmpty($data['jobId']);
        $this->assertSame('pending', $data['status']);
        $this->assertCount(1, $GLOBALS['__oxpulse_scheduled_events']);
        // No blocked-attempt flag when cron is healthy.
        $this->assertArrayNotHasKey(
            \OXPulse\Imager\Integration\WordPress\Admin\PrewarmRestController::CRON_BLOCKED_TRANSIENT,
            $GLOBALS['__oxpulse_transients'] ?? []
        );
        // No warning logged when cron is healthy.
        $this->assertCount(0, $logger->warnings);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_handle_prewarm_sync_still_works_when_cron_disabled(): void
    {
        // Sync mode (async=false/default) must NOT be blocked by
        // DISABLE_WP_CRON — sync runs inline, no WP-Cron needed.
        define('DISABLE_WP_CRON', true);
        $this->setupFullConfig();
        $GLOBALS['__oxpulse_transients'] = [];
        $GLOBALS['__oxpulse_scheduled_events'] = [];

        $controller = new PrewarmRestController($this->repository, $this->spyLogger());
        $request = new WP_REST_Request([
            'urls'  => ['https://example.com/uploads/photo.jpg'],
            'async' => false,
        ]);

        $response = $controller->handlePrewarm($request);

        // Sync path is unaffected — runs inline, returns a result.
        $this->assertNotInstanceOf(WP_Error::class, $response);
        $data = $response->get_data();
        $this->assertSame(1, $data['total']);
        $this->assertCount(0, $GLOBALS['__oxpulse_scheduled_events']);
    }

    // ─── #82: health-gated REST prewarm URL producer ──────────────

    public function test_handle_prewarm_does_not_emit_imgproxy_urls_when_health_down(): void
    {
        $this->setupFullConfig();
        (new ImgproxyHealthCache())->write('down');

        $controller = new PrewarmRestController($this->repository);
        $request = new WP_REST_Request([
            'urls' => ['https://example.com/uploads/photo.jpg'],
        ]);

        $response = $controller->handlePrewarm($request);

        // With health Down, the controller must NOT produce imgproxy
        // warm URLs. The response is either a WP_REST_Response with all
        // items skipped/failed-with-local-urls, or (if the host has no
        // encoder) still a WP_REST_Response — but NEVER an item whose
        // imgproxyUrl starts with the imgproxy endpoint.
        $this->assertNotInstanceOf(WP_Error::class, $response, 'health-down prewarm must not 400');

        $data = $response->get_data();
        $this->assertSame(1, $data['total']);

        foreach ($data['items'] as $item) {
            $this->assertFalse(
                is_string($item['imgproxyUrl']) && str_starts_with($item['imgproxyUrl'], 'https://imgproxy.example.com/'),
                'cached-Down imgproxy: prewarm item must NOT have an imgproxy URL, got: ' . $item['imgproxyUrl']
            );
        }
    }
}
