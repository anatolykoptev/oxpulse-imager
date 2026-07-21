<?php
/**
 * HealthCheckService tests.
 *
 * Verifies endpoint validation, HTTP status interpretation, error
 * handling, and AVIF format negotiation detection using a stub HTTP
 * client. No network access.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Health\HealthCheckHttpClient;
use OXPulse\Imager\Application\Health\HealthCheckService;
use OXPulse\Imager\Application\Health\HealthResult;
use PHPUnit\Framework\TestCase;

class HealthCheckServiceTest extends TestCase
{
    private StubHealthClient $client;
    private HealthCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new StubHealthClient();
        $this->service = new HealthCheckService($this->client);
    }

    public function test_empty_endpoint_returns_failed(): void
    {
        $result = $this->service->checkEndpoint('   ');

        $this->assertFalse($result->ok);
        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('empty', $result->message);
        $this->assertNull($this->client->lastUrl);
    }

    public function test_malformed_endpoint_returns_failed(): void
    {
        $result = $this->service->checkEndpoint('not-a-url');

        $this->assertFalse($result->ok);
        $this->assertSame('failed', $result->status);
        $this->assertNull($this->client->lastUrl);
    }

    public function test_200_response_returns_ok(): void
    {
        $this->client->nextHeadResponse = ['status' => 200, 'error' => null, 'headers' => []];
        $result = $this->service->checkEndpoint('https://imgproxy.example.com');

        $this->assertTrue($result->ok);
        $this->assertSame('ok', $result->status);
        $this->assertSame('https://imgproxy.example.com/health', $this->client->lastUrl);
    }

    public function test_endpoint_trailing_slash_normalized(): void
    {
        $this->client->nextHeadResponse = ['status' => 200, 'error' => null, 'headers' => []];
        $this->service->checkEndpoint('https://imgproxy.example.com/');

        $this->assertSame('https://imgproxy.example.com/health', $this->client->lastUrl);
    }

    public function test_404_returns_failed_with_status_code(): void
    {
        $this->client->nextHeadResponse = ['status' => 404, 'error' => null, 'headers' => []];
        $result = $this->service->checkEndpoint('https://imgproxy.example.com');

        $this->assertFalse($result->ok);
        $this->assertSame('failed', $result->status);
        $this->assertSame(404, $result->statusCode);
    }

    public function test_500_returns_failed_with_status_code(): void
    {
        $this->client->nextHeadResponse = ['status' => 503, 'error' => null, 'headers' => []];
        $result = $this->service->checkEndpoint('https://imgproxy.example.com');

        $this->assertFalse($result->ok);
        $this->assertSame('failed', $result->status);
        $this->assertSame(503, $result->statusCode);
    }

    public function test_transport_error_returns_unreachable(): void
    {
        $this->client->nextHeadResponse = ['status' => 0, 'error' => 'Connection refused', 'headers' => []];
        $result = $this->service->checkEndpoint('https://imgproxy.example.com');

        $this->assertFalse($result->ok);
        $this->assertSame('unreachable', $result->status);
        $this->assertStringContainsString('Connection refused', $result->message);
    }

    public function test_unexpected_status_returns_failed(): void
    {
        $this->client->nextHeadResponse = ['status' => 418, 'error' => null, 'headers' => []];
        $result = $this->service->checkEndpoint('https://imgproxy.example.com');

        $this->assertFalse($result->ok);
        $this->assertSame('failed', $result->status);
        $this->assertSame(418, $result->statusCode);
    }

    public function test_timeout_is_passed_to_client(): void
    {
        $this->client->nextHeadResponse = ['status' => 200, 'error' => null, 'headers' => []];
        $this->service->checkEndpoint('https://imgproxy.example.com');

        $this->assertSame(10, $this->client->lastTimeout);
    }

    // --- AVIF format negotiation tests ---

    public function test_avif_support_detected_when_content_type_is_avif(): void
    {
        $this->client->nextGetResponse = [
            'status' => 200,
            'error' => null,
            'headers' => ['content-type' => 'image/avif'],
        ];

        $result = $this->service->checkAvifSupport(
            'https://imgproxy.example.com',
            'https://example.com/photo.jpg'
        );

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('AVIF', $result->message);
    }

    public function test_avif_support_failed_when_content_type_is_webp(): void
    {
        $this->client->nextGetResponse = [
            'status' => 200,
            'error' => null,
            'headers' => ['content-type' => 'image/webp'],
        ];

        $result = $this->service->checkAvifSupport(
            'https://imgproxy.example.com',
            'https://example.com/photo.jpg'
        );

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('IMGPROXY_AUTO_AVIF', $result->message);
    }

    public function test_avif_support_failed_when_content_type_is_jpeg(): void
    {
        $this->client->nextGetResponse = [
            'status' => 200,
            'error' => null,
            'headers' => ['content-type' => 'image/jpeg'],
        ];

        $result = $this->service->checkAvifSupport(
            'https://imgproxy.example.com',
            'https://example.com/photo.jpg'
        );

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('IMGPROXY_AUTO_AVIF', $result->message);
    }

    public function test_avif_support_failed_on_non_200(): void
    {
        $this->client->nextGetResponse = [
            'status' => 404,
            'error' => null,
            'headers' => [],
        ];

        $result = $this->service->checkAvifSupport(
            'https://imgproxy.example.com',
            'https://example.com/photo.jpg'
        );

        $this->assertFalse($result->ok);
        $this->assertSame(404, $result->statusCode);
    }

    public function test_avif_support_failed_on_transport_error(): void
    {
        $this->client->nextGetResponse = [
            'status' => 0,
            'error' => 'Connection refused',
            'headers' => [],
        ];

        $result = $this->service->checkAvifSupport(
            'https://imgproxy.example.com',
            'https://example.com/photo.jpg'
        );

        $this->assertFalse($result->ok);
        $this->assertSame('unreachable', $result->status);
    }

    public function test_avif_support_empty_endpoint_returns_failed(): void
    {
        $result = $this->service->checkAvifSupport('', 'https://example.com/photo.jpg');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('empty', $result->message);
    }

    public function test_avif_support_empty_sample_image_returns_failed(): void
    {
        $result = $this->service->checkAvifSupport('https://imgproxy.example.com', '');

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('empty', $result->message);
    }

    public function test_avif_support_sends_accept_header(): void
    {
        $this->client->nextGetResponse = [
            'status' => 200,
            'error' => null,
            'headers' => ['content-type' => 'image/avif'],
        ];

        $this->service->checkAvifSupport(
            'https://imgproxy.example.com',
            'https://example.com/photo.jpg'
        );

        $this->assertNotNull($this->client->lastHeaders);
        $this->assertStringContainsString('image/avif', $this->client->lastHeaders['Accept'] ?? '');
    }

    public function test_avif_support_case_insensitive_content_type(): void
    {
        $this->client->nextGetResponse = [
            'status' => 200,
            'error' => null,
            'headers' => ['Content-Type' => 'image/AVIF'],
        ];

        $result = $this->service->checkAvifSupport(
            'https://imgproxy.example.com',
            'https://example.com/photo.jpg'
        );

        $this->assertTrue($result->ok);
    }
}

/**
 * Stub HTTP client for HealthCheckService tests.
 */
final class StubHealthClient implements HealthCheckHttpClient
{
    public ?string $lastUrl = null;
    public ?int $lastTimeout = null;
    public ?array $lastHeaders = null;
    public array $nextHeadResponse = ['status' => 0, 'error' => 'no response stubbed', 'headers' => []];
    public array $nextGetResponse = ['status' => 0, 'error' => 'no response stubbed', 'headers' => []];

    public function head(string $url, int $timeoutSeconds): array
    {
        $this->lastUrl = $url;
        $this->lastTimeout = $timeoutSeconds;
        return $this->nextHeadResponse;
    }

    public function get(string $url, int $timeoutSeconds, array $headers = []): array
    {
        $this->lastUrl = $url;
        $this->lastTimeout = $timeoutSeconds;
        $this->lastHeaders = $headers;
        return $this->nextGetResponse;
    }
}
