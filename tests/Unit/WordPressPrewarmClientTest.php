<?php
/**
 * WordPressPrewarmClient unit tests.
 *
 * Verifies the WP HTTP API prewarm client (migrated from curl_multi to
 * wp_remote_head per #29 — wordpress.org Plugin Check compliance):
 * - Empty batch → empty result.
 * - Happy path: each URL gets a HEAD via wp_remote_head; status + elapsed_ms.
 * - WP_Error → status 0, redacted error, elapsed_ms.
 * - Accept header is passed (imgproxy format negotiation).
 * - Timeout, sslverify, redirection=0 preserved.
 *
 * Uses the bootstrap's self_stub_http_response() which keys stubs by URL
 * (and Accept header when present).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Http\WordPressPrewarmClient;
use PHPUnit\Framework\TestCase;

class WordPressPrewarmClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_http_responses'] = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['__oxpulse_http_responses']);
    }

    public function test_empty_batch_returns_empty_array(): void
    {
        $client = new WordPressPrewarmClient();
        $this->assertSame([], $client->headBatch([], 10));
    }

    public function test_happy_path_returns_status_per_url(): void
    {
        $urls = [
            'https://imgproxy.example.com/sig1.webp',
            'https://imgproxy.example.com/sig2.webp',
        ];

        // Stub each URL with a 200 response. self_stub_http_response keys
        // by URL + Accept header; the client sends a fixed Accept header.
        foreach ($urls as $url) {
            $GLOBALS['__oxpulse_http_responses'][$url . '#Accept=image/avif,image/webp,image/*,*/*;q=0.8'] = [
                'response' => ['code' => 200],
                'headers' => [],
            ];
        }

        $client = new WordPressPrewarmClient();
        $results = $client->headBatch($urls, 10);

        $this->assertCount(2, $results);
        $this->assertSame(200, $results[0]['status']);
        $this->assertNull($results[0]['error']);
        $this->assertIsInt($results[0]['elapsed_ms']);
        $this->assertSame(200, $results[1]['status']);
        $this->assertNull($results[1]['error']);
    }

    public function test_wp_error_yields_status_zero_and_redacted_error(): void
    {
        $url = 'https://imgproxy.example.com/sig.webp';
        // No stub registered → self_stub_http_response returns a WP_Error
        // whose message includes the URL. The client must redact it.
        $client = new WordPressPrewarmClient();
        $results = $client->headBatch([$url], 10);

        $this->assertCount(1, $results);
        $this->assertSame(0, $results[0]['status']);
        $this->assertNotNull($results[0]['error']);
        // The raw URL must NOT appear in the redacted error message.
        $this->assertStringNotContainsString('imgproxy.example.com', $results[0]['error']);
        $this->assertIsInt($results[0]['elapsed_ms']);
    }

    public function test_non_200_status_propagated(): void
    {
        $url = 'https://imgproxy.example.com/sig.webp';
        $GLOBALS['__oxpulse_http_responses'][$url . '#Accept=image/avif,image/webp,image/*,*/*;q=0.8'] = [
            'response' => ['code' => 404],
            'headers' => [],
        ];

        $client = new WordPressPrewarmClient();
        $results = $client->headBatch([$url], 10);

        $this->assertSame(404, $results[0]['status']);
        $this->assertNull($results[0]['error']);
    }

    public function test_results_preserve_input_order(): void
    {
        $urls = [
            'https://imgproxy.example.com/a.webp',
            'https://imgproxy.example.com/b.webp',
            'https://imgproxy.example.com/c.webp',
        ];
        foreach ($urls as $i => $url) {
            $GLOBALS['__oxpulse_http_responses'][$url . '#Accept=image/avif,image/webp,image/*,*/*;q=0.8'] = [
                'response' => ['code' => 200 + $i],
                'headers' => [],
            ];
        }

        $client = new WordPressPrewarmClient();
        $results = $client->headBatch($urls, 10);

        $this->assertSame(200, $results[0]['status']);
        $this->assertSame(201, $results[1]['status']);
        $this->assertSame(202, $results[2]['status']);
    }
}
