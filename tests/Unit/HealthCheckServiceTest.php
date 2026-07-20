<?php
/**
 * HealthCheckService tests.
 *
 * Verifies endpoint validation, HTTP status interpretation, and error
 * handling using a stub HTTP client. No network access.
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
        $this->client->nextResponse = ['status' => 200, 'error' => null];
        $result = $this->service->checkEndpoint('https://imgproxy.example.com');

        $this->assertTrue($result->ok);
        $this->assertSame('ok', $result->status);
        $this->assertSame('https://imgproxy.example.com/health', $this->client->lastUrl);
    }

    public function test_endpoint_trailing_slash_normalized(): void
    {
        $this->client->nextResponse = ['status' => 200, 'error' => null];
        $this->service->checkEndpoint('https://imgproxy.example.com/');

        $this->assertSame('https://imgproxy.example.com/health', $this->client->lastUrl);
    }

    public function test_404_returns_failed_with_status_code(): void
    {
        $this->client->nextResponse = ['status' => 404, 'error' => null];
        $result = $this->service->checkEndpoint('https://imgproxy.example.com');

        $this->assertFalse($result->ok);
        $this->assertSame('failed', $result->status);
        $this->assertSame(404, $result->statusCode);
    }

    public function test_500_returns_failed_with_status_code(): void
    {
        $this->client->nextResponse = ['status' => 503, 'error' => null];
        $result = $this->service->checkEndpoint('https://imgproxy.example.com');

        $this->assertFalse($result->ok);
        $this->assertSame('failed', $result->status);
        $this->assertSame(503, $result->statusCode);
    }

    public function test_transport_error_returns_unreachable(): void
    {
        $this->client->nextResponse = ['status' => 0, 'error' => 'Connection refused'];
        $result = $this->service->checkEndpoint('https://imgproxy.example.com');

        $this->assertFalse($result->ok);
        $this->assertSame('unreachable', $result->status);
        $this->assertStringContainsString('Connection refused', $result->message);
    }

    public function test_unexpected_status_returns_failed(): void
    {
        $this->client->nextResponse = ['status' => 418, 'error' => null];
        $result = $this->service->checkEndpoint('https://imgproxy.example.com');

        $this->assertFalse($result->ok);
        $this->assertSame('failed', $result->status);
        $this->assertSame(418, $result->statusCode);
    }

    public function test_timeout_is_passed_to_client(): void
    {
        $this->client->nextResponse = ['status' => 200, 'error' => null];
        $this->service->checkEndpoint('https://imgproxy.example.com');

        // The service uses a 10-second timeout constant; verify it is
        // forwarded to the HTTP client rather than being silently dropped.
        $this->assertSame(10, $this->client->lastTimeout);
    }
}

/**
 * Stub HTTP client for HealthCheckService tests.
 */
final class StubHealthClient implements HealthCheckHttpClient
{
    public ?string $lastUrl = null;
    public ?int $lastTimeout = null;
    public array $nextResponse = ['status' => 0, 'error' => 'no response stubbed'];

    public function head(string $url, int $timeoutSeconds): array
    {
        $this->lastUrl = $url;
        $this->lastTimeout = $timeoutSeconds;
        return $this->nextResponse;
    }
}
