<?php
/**
 * ImgproxyBackend tests.
 *
 * Verifies the socialSafeUrl() capability seam:
 * - local source + jpeg + extensionFormat → non-null URL ending .jpg
 * - http source → null (the .jpg encoded-source form is unreliable
 *   for http sources, so the backend answers honestly: cannot produce)
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Transform\TransformRequest;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackend;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use OXPulse\Imager\Infrastructure\Imgproxy\SocialJpegCapabilityCache;
use PHPUnit\Framework\TestCase;

class ImgproxyBackendTest extends TestCase
{
    private const KEY_HEX = '736563726574';
    private const SALT_HEX = '68656C6C6F';
    private const ENDPOINT = 'https://imgproxy.example.com';

    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
    }

    private function backend(): ImgproxyBackend
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: ['https://example.com/wp-content/uploads/'],
        );
        return new ImgproxyBackend($delivery, SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX));
    }

    private function localRequest(): TransformRequest
    {
        return new TransformRequest(
            sourceUrl: 'wp-content/uploads/2026/07/photo.webp',
            width: 1200,
            height: 630,
            resize: 'fill',
            format: 'jpeg',
            sourceMode: 'local',
            extensionFormat: true,
        );
    }

    public function test_social_safe_url_local_jpeg_extension_format_returns_jpg_url(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'wp-content/uploads/2026/07/photo.webp',
            width: 1200,
            height: 630,
            resize: 'fill',
            format: 'jpeg',
            sourceMode: 'local',
            extensionFormat: true,
        );

        $url = $this->backend()->socialSafeUrl($request);

        $this->assertNotNull($url, 'local+jpeg+extensionFormat must produce a URL');
        $this->assertStringEndsWith('.jpg', $url, 'social-safe URL must end with .jpg');
        $this->assertStringNotContainsString('@jpeg', $url);
    }

    public function test_social_safe_url_http_source_returns_null(): void
    {
        $request = new TransformRequest(
            sourceUrl: 'https://example.com/wp-content/uploads/2026/07/photo.webp',
            width: 1200,
            height: 630,
            resize: 'fill',
            format: 'jpeg',
            sourceMode: 'http',
            extensionFormat: true,
        );

        $url = $this->backend()->socialSafeUrl($request);

        // http-source .jpg form is unreliable → backend answers honestly.
        $this->assertNull($url);
    }

    // ─── T4: conservative capability gate (optional caches) ──────────

    private function delivery(): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: ['https://example.com/wp-content/uploads/'],
        );
    }

    private function signing(): SigningConfig
    {
        return SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);
    }

    public function test_capability_readok_false_returns_null(): void
    {
        // Conservative-default lock: capability cache unset → readOk=false
        // → socialSafeUrl returns null (degrade to webp).
        $capability = new SocialJpegCapabilityCache();
        $backend = new ImgproxyBackend($this->delivery(), $this->signing(), null, $capability);

        $url = $backend->socialSafeUrl($this->localRequest());

        $this->assertNull($url, 'capability readOk=false must return null (conservative degrade to webp)');
    }

    public function test_capability_ok_and_health_up_returns_jpg(): void
    {
        $health = new ImgproxyHealthCache();
        $health->write('up');
        $capability = new SocialJpegCapabilityCache();
        $capability->write('ok');
        $backend = new ImgproxyBackend($this->delivery(), $this->signing(), $health, $capability);

        $url = $backend->socialSafeUrl($this->localRequest());

        $this->assertNotNull($url, 'capability ok + health up must produce a .jpg URL');
        $this->assertStringEndsWith('.jpg', $url);
    }

    public function test_health_down_and_capability_ok_returns_null(): void
    {
        // The health cache is a cheap belt: even if capability says ok,
        // a cached 'down' health blocks the .jpg URL (imgproxy is dead).
        $health = new ImgproxyHealthCache();
        $health->write('down');
        $capability = new SocialJpegCapabilityCache();
        $capability->write('ok');
        $backend = new ImgproxyBackend($this->delivery(), $this->signing(), $health, $capability);

        $url = $backend->socialSafeUrl($this->localRequest());

        $this->assertNull($url, 'health down must return null even when capability is ok');
    }

    public function test_gate_makes_zero_network_calls(): void
    {
        // Front-end-safe lock: the gate + both cache read()s make ZERO
        // HttpRequester/network calls. The caches read options only.
        // Falsification: if the gate called a probe or HttpRequester,
        // this test would need a stub — it doesn't, proving zero I/O.
        $health = new ImgproxyHealthCache();
        $capability = new SocialJpegCapabilityCache();
        $backend = new ImgproxyBackend($this->delivery(), $this->signing(), $health, $capability);

        // No HttpRequester is injected anywhere — if the gate tried
        // network I/O, it would fatal (no requester to call). The call
        // succeeds and returns null (capability unset → conservative).
        $url = $backend->socialSafeUrl($this->localRequest());

        $this->assertNull($url, 'unset capability → null (no network I/O needed)');
    }
}
