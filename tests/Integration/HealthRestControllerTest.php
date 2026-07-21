<?php
/**
 * HealthRestController integration tests.
 *
 * Verifies the /oxpulse/v1/health and /oxpulse/v1/avif-check REST
 * endpoints: route registration, permission check, and response shape.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Integration;

use OXPulse\Imager\Application\Health\HealthCheckService;
use OXPulse\Imager\Infrastructure\Http\WordPressHealthClient;
use OXPulse\Imager\Integration\WordPress\Admin\HealthRestController;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class HealthRestControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_rest_routes'] = [];
        $GLOBALS['__oxpulse_current_user_can'] = [OXPULSE_IMAGER_CAPABILITY => true];
        $GLOBALS['__oxpulse_http_responses'] = [];
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

    public function test_register_registers_health_and_avif_routes(): void
    {
        $controller = new HealthRestController(new HealthCheckService(new WordPressHealthClient()));
        $controller->register();
        $this->fireHook('rest_api_init');

        $routes = $GLOBALS['__oxpulse_rest_routes'] ?? [];
        $this->assertArrayHasKey('oxpulse/v1/health', $routes);
        $this->assertArrayHasKey('oxpulse/v1/avif-check', $routes);
    }

    public function test_check_permission_requires_capability(): void
    {
        $GLOBALS['__oxpulse_current_user_can'] = [];
        $controller = new HealthRestController(new HealthCheckService(new WordPressHealthClient()));
        $this->assertFalse($controller->checkPermission());
    }

    public function test_check_permission_grants_with_capability(): void
    {
        $GLOBALS['__oxpulse_current_user_can'] = [OXPULSE_IMAGER_CAPABILITY => true];
        $controller = new HealthRestController(new HealthCheckService(new WordPressHealthClient()));
        $this->assertTrue($controller->checkPermission());
    }

    public function test_handle_health_check_returns_ok_when_endpoint_healthy(): void
    {
        // Stub: imgproxy /health returns 200.
        $GLOBALS['__oxpulse_http_responses']['https://imgproxy.example.com/health'] = [
            'response' => ['code' => 200],
            'headers' => [],
        ];

        $controller = new HealthRestController(new HealthCheckService(new WordPressHealthClient()));
        $request = new WP_REST_Request(['endpoint' => 'https://imgproxy.example.com']);

        $response = $controller->handleHealthCheck($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertSame('ok', $data['status']);
    }

    public function test_handle_health_check_returns_error_when_empty_endpoint(): void
    {
        $controller = new HealthRestController(new HealthCheckService(new WordPressHealthClient()));
        $request = new WP_REST_Request([]);

        $response = $controller->handleHealthCheck($request);

        $this->assertInstanceOf(\WP_Error::class, $response);
    }

    public function test_handle_avif_check_returns_ok_when_avif_supported(): void
    {
        $sampleUrl = 'https://example.com/uploads/test.jpg';
        $imgproxyUrl = 'https://imgproxy.example.com/plain/' . $sampleUrl;

        // Stub: imgproxy returns 200 with image/avif Content-Type.
        $GLOBALS['__oxpulse_http_responses'][$imgproxyUrl . '#Accept=image/avif,image/webp,image/*,*/*;q=0.8'] = [
            'response' => ['code' => 200],
            'headers' => ['content-type' => 'image/avif'],
        ];

        $controller = new HealthRestController(new HealthCheckService(new WordPressHealthClient()));
        $request = new WP_REST_Request([
            'endpoint'    => 'https://imgproxy.example.com',
            'sampleImage' => $sampleUrl,
        ]);

        $response = $controller->handleAvifCheck($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $data = $response->get_data();
        $this->assertTrue($data['ok']);
        $this->assertSame('AVIF format negotiation is supported.', $data['message']);
    }

    public function test_handle_avif_check_returns_error_when_no_sample_image(): void
    {
        $controller = new HealthRestController(new HealthCheckService(new WordPressHealthClient()));
        $request = new WP_REST_Request(['endpoint' => 'https://imgproxy.example.com']);

        $response = $controller->handleAvifCheck($request);

        $this->assertInstanceOf(\WP_Error::class, $response);
    }
}
