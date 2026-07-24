<?php
/**
 * SocialJpegCapabilityProbe tests.
 *
 * Verifies the write-time probe that validates imgproxy can actually
 * serve a .jpg transcoded URL for og:image. The probe constructs the
 * EXACT production .jpg URL via an ungated ImgproxyBackend, issues a
 * single getImage() to verify a 200 + image/jpeg response, and writes
 * 'ok'/'no' to SocialJpegCapabilityCache. NEVER live HTTP — the
 * HttpRequester is stubbed.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Infrastructure\Imgproxy\SocialJpegCapabilityCache;
use OXPulse\Imager\Infrastructure\Imgproxy\SocialJpegCapabilityProbe;
use OXPulse\Imager\Infrastructure\Local\HttpRequester;
use PHPUnit\Framework\TestCase;

class SocialJpegCapabilityProbeTest extends TestCase
{
    private const KEY_HEX = '736563726574';
    private const SALT_HEX = '68656C6C6F';
    private const ENDPOINT = 'https://imgproxy.example.com';

    private string $tmpDir = '';
    private string $imagePath = '';

    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_transients'] = [];

        // Create a real temp file so SourcePolicy::authorize succeeds
        // in local mode (realpath must resolve).
        $this->tmpDir = sys_get_temp_dir() . '/oxpulse-social-jpeg-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->imagePath = $this->tmpDir . '/photo.webp';
        file_put_contents($this->imagePath, 'fake-image');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->imagePath)) {
            unlink($this->imagePath);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
        unset($GLOBALS['__oxpulse_options']);
        unset($GLOBALS['__oxpulse_transients']);
    }

    private function delivery(): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: ['https://example.com/'],
            sourceMode: 'local',
            localBasePath: $this->tmpDir,
        );
    }

    private function signing(): SigningConfig
    {
        return SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);
    }

    private function sourceProvider(): callable
    {
        return fn(): ?string => 'https://example.com/photo.webp';
    }

    private function probe(HttpRequester $http, ?callable $sourceProvider = null, ?SocialJpegCapabilityCache $cache = null): SocialJpegCapabilityProbe
    {
        return new SocialJpegCapabilityProbe(
            $this->delivery(),
            $this->signing(),
            $http,
            $cache ?? new SocialJpegCapabilityCache(),
            $sourceProvider ?? $this->sourceProvider(),
        );
    }

    // ─── verdict mapping ─────────────────────────────────────────────

    public function test_200_image_jpeg_writes_ok(): void
    {
        $http = new GetImageStub(status: 200, contentType: 'image/jpeg');
        $cache = new SocialJpegCapabilityCache();

        $this->probe($http, null, $cache)->run();

        $this->assertTrue($cache->readOk(), '200 + image/jpeg must write ok');
    }

    public function test_403_writes_no(): void
    {
        $http = new GetImageStub(status: 403, contentType: 'text/plain');
        $cache = new SocialJpegCapabilityCache();

        $this->probe($http, null, $cache)->run();

        $this->assertFalse($cache->readOk(), '403 must write no');
    }

    public function test_200_text_html_writes_no(): void
    {
        // A 200-with-error-HTML must NOT be trusted — the content-type
        // check catches a 200 that is actually an error page.
        $http = new GetImageStub(status: 200, contentType: 'text/html; charset=UTF-8');
        $cache = new SocialJpegCapabilityCache();

        $this->probe($http, null, $cache)->run();

        $this->assertFalse($cache->readOk(), '200 + text/html must write no (not a real jpeg)');
    }

    public function test_wp_error_writes_no(): void
    {
        $http = new GetImageStub(status: 0, contentType: '', error: 'connection refused');
        $cache = new SocialJpegCapabilityCache();

        $this->probe($http, null, $cache)->run();

        $this->assertFalse($cache->readOk(), 'WP_Error (status 0) must write no');
    }

    // ─── source provider edge cases ──────────────────────────────────

    public function test_null_source_does_not_write(): void
    {
        $http = new GetImageStub(status: 200, contentType: 'image/jpeg');
        $cache = new SocialJpegCapabilityCache();

        $this->probe($http, fn() => null, $cache)->run();

        $this->assertFalse($cache->readOk(), 'null source must NOT write (stay conservative — no images to probe)');
        $this->assertSame(0, $http->getImageCalls, 'null source must not issue any HTTP call');
    }

    public function test_unauthorized_source_writes_no(): void
    {
        $http = new GetImageStub(status: 200, contentType: 'image/jpeg');
        $cache = new SocialJpegCapabilityCache();

        // Source URL NOT in allowedSources → SourcePolicy denies.
        $this->probe($http, fn() => 'https://evil.com/photo.webp', $cache)->run();

        $this->assertFalse($cache->readOk(), 'unauthorized source must write no');
        $this->assertSame(0, $http->getImageCalls, 'unauthorized source must not issue an HTTP call');
    }

    public function test_non_local_source_mode_writes_no(): void
    {
        $http = new GetImageStub(status: 200, contentType: 'image/jpeg');
        $cache = new SocialJpegCapabilityCache();

        // http source mode → fsPath is null even if authorized.
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: ['https://example.com/'],
            sourceMode: 'http',
        );
        $probe = new SocialJpegCapabilityProbe(
            $delivery,
            $this->signing(),
            $http,
            $cache,
            fn() => 'https://example.com/photo.webp',
        );

        $probe->run();

        $this->assertFalse($cache->readOk(), 'non-local source mode must write no (fsPath is null)');
        $this->assertSame(0, $http->getImageCalls, 'non-local source must not issue an HTTP call');
    }

    // ─── URL form + call count ───────────────────────────────────────

    public function test_probed_url_ends_with_jpg(): void
    {
        $http = new GetImageStub(status: 200, contentType: 'image/jpeg');

        $this->probe($http)->run();

        $this->assertStringEndsWith('.jpg', $http->lastGetImageUrl, 'the probed URL must be the .jpg transcoded form');
    }

    public function test_get_image_called_exactly_once(): void
    {
        $http = new GetImageStub(status: 200, contentType: 'image/jpeg');

        $this->probe($http)->run();

        $this->assertSame(1, $http->getImageCalls, 'getImage must be called exactly once per probe run');
    }
}

/**
 * Stub HttpRequester that records getImage() calls and returns a canned
 * {status, content_type, error} response. get()/head() are unused no-ops.
 */
class GetImageStub implements HttpRequester
{
    public int $getImageCalls = 0;
    public string $lastGetImageUrl = '';

    public function __construct(
        private int $status = 200,
        private string $contentType = '',
        private ?string $error = null,
    ) {}

    public function get(string $url): array
    {
        return ['status' => $this->status, 'body' => '', 'error' => $this->error];
    }

    public function head(string $url): array
    {
        return ['status' => $this->status, 'body' => '', 'error' => $this->error];
    }

    public function getImage(string $url): array
    {
        $this->getImageCalls++;
        $this->lastGetImageUrl = $url;
        return ['status' => $this->status, 'content_type' => $this->contentType, 'error' => $this->error];
    }
}
