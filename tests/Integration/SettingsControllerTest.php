<?php
/**
 * Settings controller flow integration tests.
 *
 * Verifies the save → validate → persist → redirect flow and the
 * Test Connection flow using the stub WordPress environment. Exercises
 * the doSave() / doTestConnection() methods directly (the public
 * handleSave/handleTestConnection wrappers add wp_safe_redirect + exit,
 * which are tested separately via the guard logic).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Integration;

use OXPulse\Imager\Application\Health\HealthCheckHttpClient;
use OXPulse\Imager\Application\Health\HealthCheckService;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\SettingsValidator;
use OXPulse\Imager\Integration\WordPress\Admin\SettingsController;
use PHPUnit\Framework\TestCase;

class SettingsControllerTest extends TestCase
{
    private OptionSettingsRepository $repository;
    private SettingsValidator $validator;
    private StubHealthClient $healthClient;
    private HealthCheckService $healthCheck;
    private SettingsController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_redirects'] = [];

        $this->repository = new OptionSettingsRepository();
        $this->validator = new SettingsValidator();
        $this->healthClient = new StubHealthClient();
        $this->healthCheck = new HealthCheckService($this->healthClient);
        $this->controller = new SettingsController(
            $this->repository,
            $this->validator,
            $this->healthCheck
        );
    }

    public function test_do_save_persists_valid_settings_and_redirects_with_success(): void
    {
        $key = bin2hex(random_bytes(16));
        $salt = bin2hex(random_bytes(16));

        $url = $this->controller->doSave([
            'enabled' => '1',
            'endpoint' => 'https://imgproxy.example.com/',
            'key' => $key,
            'salt' => $salt,
            'allowed_sources' => "https://example.com/uploads/",
            'output_format' => 'avif',
            'default_quality' => '82',
        ]);

        $this->assertStringContainsString('settings_updated=1', $url);

        $config = $this->repository->loadDeliveryConfig();
        $this->assertTrue($config->enabled);
        $this->assertSame('https://imgproxy.example.com', $config->endpoint);
        $this->assertSame('avif', $config->outputFormat);
        $this->assertSame(82, $config->defaultQuality);
        $this->assertSame(['https://example.com/uploads/'], $config->allowedSources);

        $this->assertSame($key, get_option(OptionSettingsRepository::OPTION_KEY, ''));
        $this->assertSame($salt, get_option(OptionSettingsRepository::OPTION_SALT, ''));
    }

    public function test_do_save_with_empty_secrets_preserves_existing_secrets(): void
    {
        // Pre-populate secrets.
        $existingKey = bin2hex(random_bytes(16));
        $existingSalt = bin2hex(random_bytes(16));
        $this->repository->saveSecrets($existingKey, $existingSalt);

        $this->controller->doSave([
            'enabled' => '1',
            'endpoint' => 'https://imgproxy.example.com',
            'key' => '',
            'salt' => '',
        ]);

        $this->assertSame($existingKey, get_option(OptionSettingsRepository::OPTION_KEY, ''));
        $this->assertSame($existingSalt, get_option(OptionSettingsRepository::OPTION_SALT, ''));
    }

    public function test_do_save_with_invalid_input_redirects_with_errors_and_does_not_persist(): void
    {
        $url = $this->controller->doSave([
            'endpoint' => 'not-a-url',
            'key' => 'abcd', // too short
        ]);

        $this->assertStringContainsString('settings_errors=', $url);
        $this->assertStringNotContainsString('settings_updated=1', $url);

        // Nothing should have been persisted.
        $config = $this->repository->loadDeliveryConfig();
        $this->assertFalse($config->enabled);
        $this->assertSame('', $config->endpoint);
    }

    public function test_do_save_rejects_http_endpoint_without_dev_override(): void
    {
        $url = $this->controller->doSave([
            'endpoint' => 'http://imgproxy.example.com',
        ]);

        $this->assertStringContainsString('settings_errors=', $url);
        $config = $this->repository->loadDeliveryConfig();
        $this->assertSame('', $config->endpoint);
    }

    public function test_do_save_allows_http_with_dev_override(): void
    {
        $url = $this->controller->doSave([
            'endpoint' => 'http://localhost:8080',
            'dev_http_override' => '1',
        ]);

        $this->assertStringContainsString('settings_updated=1', $url);
        $config = $this->repository->loadDeliveryConfig();
        $this->assertSame('http://localhost:8080', $config->endpoint);
    }

    public function test_do_test_connection_uses_form_endpoint_when_provided(): void
    {
        $this->healthClient->nextResponse = ['status' => 200, 'error' => null, 'headers' => []];

        $url = $this->controller->doTestConnection('https://imgproxy.example.com');

        $this->assertSame('https://imgproxy.example.com/health', $this->healthClient->lastUrl);
        $this->assertStringContainsString('health_result=ok', $url);
    }

    public function test_do_test_connection_falls_back_to_stored_endpoint(): void
    {
        $this->healthClient->nextResponse = ['status' => 200, 'error' => null, 'headers' => []];
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://stored.example.com');

        $url = $this->controller->doTestConnection('');

        $this->assertSame('https://stored.example.com/health', $this->healthClient->lastUrl);
        $this->assertStringContainsString('health_result=ok', $url);
    }

    public function test_do_test_connection_reports_unreachable(): void
    {
        $this->healthClient->nextResponse = ['status' => 0, 'error' => 'Connection refused', 'headers' => []];

        $url = $this->controller->doTestConnection('https://imgproxy.example.com');

        $this->assertStringContainsString('health_result=unreachable', $url);
        $this->assertStringContainsString('Connection+refused', $url);
    }

    public function test_do_test_connection_reports_404(): void
    {
        $this->healthClient->nextResponse = ['status' => 404, 'error' => null, 'headers' => []];

        $url = $this->controller->doTestConnection('https://imgproxy.example.com');

        $this->assertStringContainsString('health_result=failed', $url);
    }

    public function test_do_test_connection_with_no_endpoint_anywhere_returns_failed(): void
    {
        $url = $this->controller->doTestConnection('');

        $this->assertStringContainsString('health_result=failed', $url);
        $this->assertStringContainsString('empty', $url);
        $this->assertNull($this->healthClient->lastUrl);
    }

    public function test_do_test_connection_with_null_health_check_returns_failed(): void
    {
        $controller = new SettingsController($this->repository, $this->validator, null);

        $url = $controller->doTestConnection('https://imgproxy.example.com');

        $this->assertStringContainsString('health_result=failed', $url);
        $this->assertStringContainsString('not+available', $url);
    }

    // --- AVIF check tests ---

    public function test_do_test_avif_success_returns_ok(): void
    {
        $this->healthClient->nextResponse = [
            'status' => 200,
            'error' => null,
            'headers' => ['content-type' => 'image/avif'],
        ];

        $url = $this->controller->doTestAvif(
            'https://imgproxy.example.com',
            'https://example.com/photo.jpg'
        );

        $this->assertStringContainsString('avif_result=ok', $url);
    }

    public function test_do_test_avif_webp_response_returns_failed(): void
    {
        $this->healthClient->nextResponse = [
            'status' => 200,
            'error' => null,
            'headers' => ['content-type' => 'image/webp'],
        ];

        $url = $this->controller->doTestAvif(
            'https://imgproxy.example.com',
            'https://example.com/photo.jpg'
        );

        $this->assertStringContainsString('avif_result=failed', $url);
        $this->assertStringContainsString('IMGPROXY_AUTO_AVIF', $url);
    }

    public function test_do_test_avif_empty_endpoint_returns_failed(): void
    {
        $url = $this->controller->doTestAvif('', 'https://example.com/photo.jpg');

        $this->assertStringContainsString('avif_result=failed', $url);
        $this->assertStringContainsString('empty', $url);
    }

    public function test_do_test_avif_uses_stored_endpoint_when_empty(): void
    {
        $this->repository->saveDeliverySettings([
            'enabled' => true,
            'endpoint' => 'https://imgproxy.stored.com',
            'allowed_sources' => ['https://example.com/'],
        ]);

        $this->healthClient->nextResponse = [
            'status' => 200,
            'error' => null,
            'headers' => ['content-type' => 'image/avif'],
        ];

        $url = $this->controller->doTestAvif('', 'https://example.com/photo.jpg');

        $this->assertStringContainsString('avif_result=ok', $url);
        $this->assertStringContainsString('imgproxy.stored.com', (string) $this->healthClient->lastUrl);
    }

    public function test_do_test_avif_falls_back_to_allowed_source_for_sample_image(): void
    {
        $this->repository->saveDeliverySettings([
            'enabled' => true,
            'endpoint' => 'https://imgproxy.example.com',
            'allowed_sources' => ['https://example.com/wp-content/uploads/'],
        ]);

        $this->healthClient->nextResponse = [
            'status' => 200,
            'error' => null,
            'headers' => ['content-type' => 'image/avif'],
        ];

        $url = $this->controller->doTestAvif('https://imgproxy.example.com', '');

        $this->assertStringContainsString('avif_result=ok', $url);
        // The sample image should be derived from the first allowed source.
        $this->assertStringContainsString('oxpulse-avif-test.jpg', (string) $this->healthClient->lastUrl);
    }

    public function test_do_test_avif_no_sample_image_and_no_sources_returns_failed(): void
    {
        $url = $this->controller->doTestAvif('https://imgproxy.example.com', '');

        $this->assertStringContainsString('avif_result=failed', $url);
        $this->assertStringContainsString('sample+image', $url);
    }

    public function test_do_test_avif_null_health_check_returns_failed(): void
    {
        $controller = new SettingsController($this->repository, $this->validator, null);

        $url = $controller->doTestAvif('https://imgproxy.example.com', 'https://example.com/photo.jpg');

        $this->assertStringContainsString('avif_result=failed', $url);
        $this->assertStringContainsString('not+available', $url);
    }

    public function test_do_test_avif_transport_error_returns_unreachable(): void
    {
        $this->healthClient->nextResponse = [
            'status' => 0,
            'error' => 'Connection refused',
            'headers' => [],
        ];

        $url = $this->controller->doTestAvif(
            'https://imgproxy.example.com',
            'https://example.com/photo.jpg'
        );

        $this->assertStringContainsString('avif_result=unreachable', $url);
    }
}

/**
 * Stub HTTP client for integration tests.
 */
final class StubHealthClient implements HealthCheckHttpClient
{
    public ?string $lastUrl = null;
    public ?int $lastTimeout = null;
    public array $nextResponse = ['status' => 0, 'error' => 'no response stubbed', 'headers' => []];

    public function head(string $url, int $timeoutSeconds): array
    {
        $this->lastUrl = $url;
        $this->lastTimeout = $timeoutSeconds;
        return $this->nextResponse;
    }

    public function get(string $url, int $timeoutSeconds, array $headers = []): array
    {
        $this->lastUrl = $url;
        $this->lastTimeout = $timeoutSeconds;
        return $this->nextResponse;
    }
}
