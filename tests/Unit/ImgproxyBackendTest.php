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
use PHPUnit\Framework\TestCase;

class ImgproxyBackendTest extends TestCase
{
    private const KEY_HEX = '736563726574';
    private const SALT_HEX = '68656C6C6F';
    private const ENDPOINT = 'https://imgproxy.example.com';

    private function backend(): ImgproxyBackend
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: ['https://example.com/wp-content/uploads/'],
        );
        return new ImgproxyBackend($delivery, SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX));
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
}
