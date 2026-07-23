<?php
/**
 * StatusRestController integration tests.
 *
 * Verifies the /oxpulse/v1/status and /oxpulse/v1/info endpoints.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Integration;

use OXPulse\Imager\Application\Health\HealthCheckService;
use OXPulse\Imager\Infrastructure\Http\WordPressHealthClient;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Integration\WordPress\Admin\StatusRestController;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class StatusRestControllerTest extends TestCase
{
    private OptionSettingsRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_rest_routes'] = [];
        $GLOBALS['__oxpulse_current_user_can'] = [OXPULSE_IMAGER_CAPABILITY => true];
        $GLOBALS['__oxpulse_http_responses'] = [];

        $this->repository = new OptionSettingsRepository();
    }

    private function fireHook(string $hook): void
    {
        foreach ($GLOBALS['__oxpulse_actions'] ?? [] as $action) {
            if ($action['hook'] === $hook && is_callable($action['callback'])) {
                call_user_func($action['callback']);
            }
        }
    }

    private function setupFullConfig(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_enabled'] = true;
        $GLOBALS['__oxpulse_options']['oxpulse_imager_endpoint'] = 'https://imgproxy.example.com';
        $GLOBALS['__oxpulse_options']['oxpulse_imager_allowed_sources'] = ['https://example.com/uploads/'];
        $GLOBALS['__oxpulse_options']['oxpulse_imager_key'] = bin2hex(random_bytes(16));
        $GLOBALS['__oxpulse_options']['oxpulse_imager_salt'] = bin2hex(random_bytes(16));
    }

    public function test_register_registers_status_and_info_routes(): void
    {
        $controller = new StatusRestController($this->repository);
        $controller->register();
        $this->fireHook('rest_api_init');

        $routes = $GLOBALS['__oxpulse_rest_routes'] ?? [];
        $this->assertArrayHasKey('oxpulse/v1/status', $routes);
        $this->assertArrayHasKey('oxpulse/v1/info', $routes);
    }

    public function test_check_permission_requires_capability(): void
    {
        $GLOBALS['__oxpulse_current_user_can'] = [];
        $controller = new StatusRestController($this->repository);
        $this->assertFalse($controller->checkPermission());
    }

    public function test_handle_status_returns_config_when_disabled(): void
    {
        $controller = new StatusRestController($this->repository);
        $response = $controller->handleStatus();

        $data = $response->get_data();
        $this->assertFalse($data['delivery']['enabled']);
        $this->assertFalse($data['signing']['configured']);
        $this->assertNull($data['health']);
    }

    public function test_handle_status_returns_config_with_health(): void
    {
        $this->setupFullConfig();
        $GLOBALS['__oxpulse_http_responses']['https://imgproxy.example.com/health'] = [
            'response' => ['code' => 200],
            'headers' => [],
        ];

        $controller = new StatusRestController($this->repository);
        $response = $controller->handleStatus();

        $data = $response->get_data();
        $this->assertTrue($data['delivery']['enabled']);
        $this->assertTrue($data['signing']['configured']);
        $this->assertNotNull($data['health']);
        $this->assertTrue($data['health']['ok']);
    }

    public function test_handle_info_returns_rewritten_url_when_authorized(): void
    {
        $this->setupFullConfig();
        $controller = new StatusRestController($this->repository);

        $request = new WP_REST_Request([
            'url'   => 'https://example.com/uploads/photo.jpg',
            'width' => 800,
        ]);

        $response = $controller->handleInfo($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $data = $response->get_data();
        $this->assertTrue($data['rewritten']);
        $this->assertNotNull($data['imgproxyUrl']);
        $this->assertStringStartsWith('https://imgproxy.example.com/', $data['imgproxyUrl']);
    }

    public function test_handle_info_returns_preserved_when_unauthorized(): void
    {
        $this->setupFullConfig();
        $controller = new StatusRestController($this->repository);

        $request = new WP_REST_Request([
            'url'   => 'https://evil.com/uploads/photo.jpg',
            'width' => 800,
        ]);

        $response = $controller->handleInfo($request);

        $data = $response->get_data();
        $this->assertFalse($data['rewritten']);
        $this->assertNull($data['imgproxyUrl']);
    }

    public function test_handle_info_returns_preserved_when_delivery_disabled(): void
    {
        $controller = new StatusRestController($this->repository);

        $request = new WP_REST_Request([
            'url'   => 'https://example.com/uploads/photo.jpg',
            'width' => 0,
        ]);

        $response = $controller->handleInfo($request);

        $data = $response->get_data();
        $this->assertFalse($data['rewritten']);
        $this->assertSame('delivery_disabled', $data['reason']);
    }

    public function test_handle_info_errors_when_no_url(): void
    {
        $controller = new StatusRestController($this->repository);
        $request = new WP_REST_Request([]);

        $response = $controller->handleInfo($request);

        $this->assertInstanceOf(\WP_Error::class, $response);
    }

    // ─── #82: health-gated REST info URL producer ─────────────────

    public function test_handle_info_does_not_emit_imgproxy_url_when_health_down(): void
    {
        $this->setupFullConfig();
        (new ImgproxyHealthCache())->write('down');

        $controller = new StatusRestController($this->repository);
        $request = new WP_REST_Request([
            'url'   => 'https://example.com/uploads/photo.jpg',
            'width' => 800,
        ]);

        $response = $controller->handleInfo($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $data = $response->get_data();

        // With health Down, the rewriter must NOT produce an imgproxy
        // URL. Either preserved (rewritten=false, imgproxyUrl=null) or
        // rewritten to a local cache URL — but NEVER an imgproxy URL.
        if ($data['rewritten']) {
            $this->assertStringNotContainsString(
                'https://imgproxy.example.com/',
                $data['imgproxyUrl'],
                'cached-Down imgproxy: REST info must NOT return an imgproxy URL'
            );
        }
        $this->assertFalse(
            is_string($data['imgproxyUrl']) && str_starts_with($data['imgproxyUrl'], 'https://imgproxy.example.com/'),
            'cached-Down imgproxy: imgproxyUrl must not be an imgproxy URL'
        );
    }
}
