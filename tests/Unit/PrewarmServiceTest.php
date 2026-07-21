<?php
/**
 * PrewarmService unit tests.
 *
 * Uses a mock PrewarmHttpClient to verify the service's URL-building
 * + result-mapping logic without real HTTP calls.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Application\Prewarm\PrewarmHttpClient;
use OXPulse\Imager\Application\Prewarm\PrewarmService;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Prewarm\PrewarmRequest;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Infrastructure\Imgproxy\HmacSigner;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyPathBuilder;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyUrlGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Mock PrewarmHttpClient that returns canned results.
 */
class MockPrewarmHttpClient implements PrewarmHttpClient
{
    public array $receivedUrls = [];
    /** @var array<int,array> Pre-set results indexed by position; missing entries default to 200 OK. */
    public array $results = [];

    public function headBatch(array $imgproxyUrls, int $timeoutSeconds): array
    {
        $this->receivedUrls = $imgproxyUrls;
        $out = [];
        foreach ($imgproxyUrls as $idx => $url) {
            // Use pre-set result if provided, otherwise default to 200 OK.
            $out[$idx] = $this->results[$idx] ?? [
                'status' => 200,
                'error' => null,
                'elapsed_ms' => 50,
            ];
        }
        return $out;
    }
}

class PrewarmServiceTest extends TestCase
{
    private DeliveryConfig $delivery;
    private SigningConfig $signing;
    private MockPrewarmHttpClient $httpClient;
    private PrewarmService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: ['https://example.com/uploads/'],
            outputFormat: 'auto',
            defaultQuality: 80,
            devHttpOverride: false,
            lqipEnabled: false,
            lqipBlur: 1,
            dprEnabled: false,
            dprVariants: [1, 2, 3],
            watermark: null,
            formatQuality: [],
        );

        // Real signing config with test key/salt (32 hex chars = 16 bytes).
        $this->signing = new SigningConfig(
            hex2bin('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'),
            hex2bin('f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3')
        );

        $policy = new SourcePolicy();
        $rewriter = new UrlRewriter($policy, $this->delivery, $this->signing);
        $this->httpClient = new MockPrewarmHttpClient();
        $this->service = new PrewarmService($rewriter, $this->httpClient);
    }

    public function test_warm_authorizes_source_and_dispatches_head(): void
    {
        $request = new PrewarmRequest(
            ['https://example.com/uploads/photo.jpg'],
            [800]
        );

        $result = $this->service->warm($request);

        $this->assertSame(1, $result->total());
        $this->assertSame(1, $result->warmedCount());
        $this->assertSame(0, $result->skippedCount());
        $this->assertSame(0, $result->failedCount());

        // The HTTP client received one signed imgproxy URL.
        $this->assertCount(1, $this->httpClient->receivedUrls);
        $this->assertStringStartsWith('https://imgproxy.example.com/', $this->httpClient->receivedUrls[0]);
    }

    public function test_warm_skips_unauthorized_source(): void
    {
        $request = new PrewarmRequest(
            ['https://evil.com/uploads/photo.jpg'],
            [800]
        );

        $result = $this->service->warm($request);

        // Source not in allowedSources → skipped, no HTTP dispatch.
        $this->assertSame(1, $result->total());
        $this->assertSame(0, $result->warmedCount());
        $this->assertSame(1, $result->skippedCount());
        $this->assertCount(0, $this->httpClient->receivedUrls);
    }

    public function test_warm_multiple_urls_and_widths(): void
    {
        $request = new PrewarmRequest(
            [
                'https://example.com/uploads/a.jpg',
                'https://example.com/uploads/b.jpg',
            ],
            [800, 1200]
        );

        $result = $this->service->warm($request);

        // 2 urls × 2 widths = 4 combinations, all warmed.
        $this->assertSame(4, $result->total());
        $this->assertSame(4, $result->warmedCount());
        $this->assertCount(4, $this->httpClient->receivedUrls);
    }

    public function test_warm_records_http_failure(): void
    {
        $this->httpClient->results = [
            0 => ['status' => 0, 'error' => 'Connection refused', 'elapsed_ms' => 100],
        ];

        $request = new PrewarmRequest(
            ['https://example.com/uploads/photo.jpg'],
            [800]
        );

        $result = $this->service->warm($request);

        $this->assertSame(1, $result->failedCount());
        $this->assertSame(0, $result->warmedCount());
        $items = $result->items;
        $this->assertSame('failed', $items[0]->status);
        $this->assertSame('Connection refused', $items[0]->message);
    }

    public function test_warm_records_non_200_as_failed(): void
    {
        $this->httpClient->results = [
            0 => ['status' => 403, 'error' => null, 'elapsed_ms' => 50],
        ];

        $request = new PrewarmRequest(
            ['https://example.com/uploads/photo.jpg'],
            [800]
        );

        $result = $this->service->warm($request);

        $this->assertSame(1, $result->failedCount());
        $items = $result->items;
        $this->assertSame('failed', $items[0]->status);
        $this->assertSame(403, $items[0]->httpStatus);
    }

    public function test_warm_empty_urls_returns_empty_result(): void
    {
        $request = new PrewarmRequest([], [800]);

        $result = $this->service->warm($request);

        $this->assertSame(0, $result->total());
        $this->assertCount(0, $this->httpClient->receivedUrls);
    }

    public function test_warm_mixed_authorized_and_unauthorized(): void
    {
        $request = new PrewarmRequest(
            [
                'https://example.com/uploads/ok.jpg',
                'https://evil.com/uploads/bad.jpg',
            ],
            [800]
        );

        $result = $this->service->warm($request);

        $this->assertSame(2, $result->total());
        $this->assertSame(1, $result->warmedCount());
        $this->assertSame(1, $result->skippedCount());
        // Only the authorized URL went to HTTP.
        $this->assertCount(1, $this->httpClient->receivedUrls);
    }

    public function test_to_array_shape(): void
    {
        $request = new PrewarmRequest(
            ['https://example.com/uploads/photo.jpg'],
            [800]
        );

        $result = $this->service->warm($request);
        $array = $result->toArray();

        $this->assertArrayHasKey('total', $array);
        $this->assertArrayHasKey('warmed', $array);
        $this->assertArrayHasKey('skipped', $array);
        $this->assertArrayHasKey('failed', $array);
        $this->assertArrayHasKey('items', $array);
        $this->assertSame(1, $array['total']);
        $this->assertSame(1, $array['warmed']);
        $this->assertIsArray($array['items']);
        $this->assertCount(1, $array['items']);
        $this->assertArrayHasKey('sourceUrl', $array['items'][0]);
        $this->assertArrayHasKey('width', $array['items'][0]);
        $this->assertArrayHasKey('status', $array['items'][0]);
    }
}
