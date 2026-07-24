<?php
/**
 * WpRemoteHttpRequester::getImage() tests.
 *
 * Verifies the getImage() method returns {status, content_type, error}
 * with the correct status code and content-type header, and maps a
 * WP_Error transport failure to status=0 + error message. Mirrors the
 * head() SSRF posture (redirection:0, sslverify:true, endpoint-only).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Local\WpRemoteHttpRequester;
use PHPUnit\Framework\TestCase;

class WpRemoteHttpRequesterGetImageTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_http_responses'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
        unset($GLOBALS['__oxpulse_http_responses']);
    }

    public function test_get_image_200_returns_status_and_content_type(): void
    {
        $url = 'https://imgproxy.example.com/sig/rs:fill:1200:630/plain/local://abc.jpg';
        $GLOBALS['__oxpulse_http_responses'][$url] = [
            'response' => ['code' => 200, 'message' => 'OK'],
            'headers' => ['content-type' => 'image/jpeg'],
            'body' => 'binary-data',
        ];

        $requester = new WpRemoteHttpRequester();
        $res = $requester->getImage($url);

        $this->assertSame(200, $res['status']);
        $this->assertSame('image/jpeg', $res['content_type']);
        $this->assertNull($res['error']);
    }

    public function test_get_image_wp_error_returns_status_zero_and_error(): void
    {
        $url = 'https://imgproxy.example.com/sig/broken';
        // No stub registered → self_stub_http_response returns WP_Error.

        $requester = new WpRemoteHttpRequester();
        $res = $requester->getImage($url);

        $this->assertSame(0, $res['status']);
        $this->assertSame('', $res['content_type']);
        $this->assertNotNull($res['error']);
        $this->assertIsString($res['error']);
    }

    public function test_get_image_403_returns_status_and_empty_error(): void
    {
        $url = 'https://imgproxy.example.com/sig/forbidden';
        $GLOBALS['__oxpulse_http_responses'][$url] = [
            'response' => ['code' => 403, 'message' => 'Forbidden'],
            'headers' => ['content-type' => 'text/plain'],
            'body' => 'Forbidden',
        ];

        $requester = new WpRemoteHttpRequester();
        $res = $requester->getImage($url);

        $this->assertSame(403, $res['status']);
        $this->assertNull($res['error']);
    }

    public function test_get_image_missing_content_type_returns_empty_string(): void
    {
        $url = 'https://imgproxy.example.com/sig/no-ct';
        $GLOBALS['__oxpulse_http_responses'][$url] = [
            'response' => ['code' => 200, 'message' => 'OK'],
            'headers' => [],
            'body' => 'data',
        ];

        $requester = new WpRemoteHttpRequester();
        $res = $requester->getImage($url);

        $this->assertSame(200, $res['status']);
        $this->assertSame('', $res['content_type']);
    }
}
