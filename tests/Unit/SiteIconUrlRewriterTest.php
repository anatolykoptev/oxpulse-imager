<?php
/**
 * get_site_icon_url filter tests.
 *
 * Verifies the site icon URL is rewritten to imgproxy when delivery is
 * enabled and the source is allowed, and preserved otherwise.
 *
 * Since the filter is registered as a closure in ServiceRegistrar, this
 * test replicates the closure's logic with the same UrlRewriter to
 * verify the behavior. The wiring itself is verified by DeliveryWiringTest.
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
use PHPUnit\Framework\TestCase;

class SiteIconUrlRewriterTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    private function createRewriter(bool $enabled = true, array $allowed = [self::ALLOWED]): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: $enabled,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: $allowed,
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    /**
     * Replicate the closure registered in ServiceRegistrar for testing.
     */
    private function siteIconCallback(UrlRewriter $rewriter): callable
    {
        return static function (string $url, int $size, int $blogId) use ($rewriter): string {
            if ($url === '') {
                return $url;
            }
            $result = $rewriter->rewrite($url, $size, $size, 'site_icon');
            return $result->url;
        };
    }

    public function test_rewrites_site_icon_url_when_delivery_enabled(): void
    {
        $rewriter = $this->createRewriter(true);
        $callback = $this->siteIconCallback($rewriter);

        $url = 'https://example.com/wp-content/uploads/icon-512.png';
        $result = $callback($url, 512, 1);

        $this->assertNotSame($url, $result);
        $this->assertStringContainsString('imgproxy.example.com', $result);
        $this->assertStringContainsString('rs:fill:512:512', $result);
    }

    public function test_preserves_url_when_delivery_disabled(): void
    {
        $rewriter = $this->createRewriter(false);
        $callback = $this->siteIconCallback($rewriter);

        $url = 'https://example.com/wp-content/uploads/icon-512.png';
        $result = $callback($url, 512, 1);

        $this->assertSame($url, $result);
    }

    public function test_preserves_url_when_source_not_allowed(): void
    {
        $rewriter = $this->createRewriter(true, ['https://other.com/wp-content/uploads/']);
        $callback = $this->siteIconCallback($rewriter);

        $url = 'https://example.com/wp-content/uploads/icon-512.png';
        $result = $callback($url, 512, 1);

        $this->assertSame($url, $result);
    }

    public function test_preserves_empty_url(): void
    {
        $rewriter = $this->createRewriter(true);
        $callback = $this->siteIconCallback($rewriter);

        $result = $callback('', 512, 1);

        $this->assertSame('', $result);
    }

    public function test_passes_size_as_both_width_and_height(): void
    {
        // Favicons are square — $size is passed as both width and height.
        $rewriter = $this->createRewriter(true);
        $callback = $this->siteIconCallback($rewriter);

        $url = 'https://example.com/wp-content/uploads/icon.png';
        $result = $callback($url, 192, 1);

        // rs:fill:192:192 — square resize.
        $this->assertStringContainsString('rs:fill:192:192', $result);
    }

    public function test_deterministic_same_args_produce_same_url(): void
    {
        $rewriter = $this->createRewriter(true);
        $callback = $this->siteIconCallback($rewriter);

        $url = 'https://example.com/wp-content/uploads/icon.png';
        $r1 = $callback($url, 512, 1);
        $r2 = $callback($url, 512, 1);

        $this->assertSame($r1, $r2);
    }
}
