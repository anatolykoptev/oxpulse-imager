<?php
/**
 * Absolute endpoint resolution tests.
 *
 * Verifies that a RELATIVE imgproxy endpoint (e.g. '/imgproxy' for
 * same-host nginx reverse-proxy setups) is resolved to an ABSOLUTE URL
 * against the site host (home_url), so filtered image URLs are always
 * absolute — required by wp_get_attachment_url contract, JSON-LD
 * ImageObject.url, og:image, RSS/feeds, REST, sitemaps.
 *
 * An ABSOLUTE endpoint config must pass through unchanged (no
 * double-prefix, no regression).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentUrlRewriter;
use PHPUnit\Framework\TestCase;

class AbsoluteEndpointResolutionTest extends TestCase
{
    private const KEY_HEX = '736563726574';
    private const SALT_HEX = '68656C6C6F';
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
    }

    public function test_resolve_relative_endpoint_to_absolute(): void
    {
        // home_url() is stubbed to https://example.test/ in tests/bootstrap.php.
        $resolved = OptionSettingsRepository::resolveEndpoint('/imgproxy');

        $this->assertSame('https://example.test/imgproxy', $resolved);
    }

    public function test_resolve_absolute_endpoint_unchanged(): void
    {
        $resolved = OptionSettingsRepository::resolveEndpoint('https://cdn.example');

        $this->assertSame('https://cdn.example', $resolved);
    }

    public function test_resolve_empty_endpoint_returns_empty(): void
    {
        $resolved = OptionSettingsRepository::resolveEndpoint('');

        $this->assertSame('', $resolved);
    }

    public function test_relative_endpoint_produces_absolute_attachment_url(): void
    {
        // Simulate ServiceRegistrar: load config with a relative endpoint,
        // resolve it to absolute, then build the rewrite pipeline.
        $repository = new OptionSettingsRepository();
        $repository->saveDeliverySettings([
            'enabled' => true,
            'endpoint' => '/imgproxy',
            'allowed_sources' => [self::ALLOWED],
        ]);
        $repository->saveSecrets(self::KEY_HEX, self::SALT_HEX);

        $delivery = $repository->loadDeliveryConfig();
        $signing = $repository->loadSigningConfig();

        // Resolve relative endpoint to absolute — the fix.
        $delivery = $delivery->withEndpoint(
            OptionSettingsRepository::resolveEndpoint($delivery->endpoint)
        );

        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing);
        $attachmentRewriter = new AttachmentUrlRewriter($rewriter);

        $result = $attachmentRewriter->rewrite(
            'https://example.com/wp-content/uploads/photo.jpg',
            1
        );

        $this->assertStringStartsWith('https://example.test/imgproxy/', $result);
    }

    public function test_absolute_endpoint_produces_absolute_attachment_url_no_regression(): void
    {
        $repository = new OptionSettingsRepository();
        $repository->saveDeliverySettings([
            'enabled' => true,
            'endpoint' => 'https://cdn.example',
            'allowed_sources' => [self::ALLOWED],
        ]);
        $repository->saveSecrets(self::KEY_HEX, self::SALT_HEX);

        $delivery = $repository->loadDeliveryConfig();
        $signing = $repository->loadSigningConfig();

        $delivery = $delivery->withEndpoint(
            OptionSettingsRepository::resolveEndpoint($delivery->endpoint)
        );

        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing);
        $attachmentRewriter = new AttachmentUrlRewriter($rewriter);

        $result = $attachmentRewriter->rewrite(
            'https://example.com/wp-content/uploads/photo.jpg',
            1
        );

        $this->assertStringStartsWith('https://cdn.example/', $result);
    }
}
