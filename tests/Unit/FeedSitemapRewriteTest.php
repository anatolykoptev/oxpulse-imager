<?php
/**
 * Feed / core-sitemap URL preservation tests.
 *
 * Feed readers and search engines persist the URLs they see — a signed
 * proxy URL baked into a feed or a wp-sitemap.xml sitemap breaks after
 * deactivation or key rotation, so the central rewrite guard preserves
 * originals in those contexts. The assertions go through the two
 * frontend filter adapters (attachment URL + content <img>) since that
 * is where the URLs would leak.
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
use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentUrlRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\ContentImgTagRewriter;
use PHPUnit\Framework\TestCase;

class FeedSitemapRewriteTest extends TestCase
{
    private UrlRewriter $rewriter;
    private DeliveryConfig $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_is_feed'] = false;
        $GLOBALS['__oxpulse_query_vars'] = [];

        $this->delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: ['https://example.com/wp-content/uploads/'],
        );
        $this->rewriter = new UrlRewriter(
            new SourcePolicy(),
            $this->delivery,
            SigningConfig::fromHex('736563726574', '68656C6C6F'),
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['__oxpulse_is_feed'], $GLOBALS['__oxpulse_query_vars']);
    }

    private function attachmentRewrite(string $url): string
    {
        return (new AttachmentUrlRewriter($this->rewriter))->rewrite($url, 1);
    }

    private function contentRewrite(string $tag): string
    {
        return (new ContentImgTagRewriter($this->rewriter, $this->delivery))
            ->rewrite($tag, 'the_content', 1);
    }

    /**
     * E1: with is_feed() true, both frontend filters must return the
     * input unchanged — no signed URLs in feed bodies.
     */
    public function test_feed_attachment_url_filter_returns_input_unchanged(): void
    {
        $GLOBALS['__oxpulse_is_feed'] = true;

        $url = 'https://example.com/wp-content/uploads/photo.jpg';
        $this->assertSame(
            $url,
            $this->attachmentRewrite($url),
            'feed contexts must keep the original URL — a persisted signed URL breaks after deactivation',
        );
    }

    public function test_feed_content_img_filter_returns_input_unchanged(): void
    {
        $GLOBALS['__oxpulse_is_feed'] = true;

        $tag = '<img src="https://example.com/wp-content/uploads/photo.jpg" width="100" height="100">';
        $this->assertSame(
            $tag,
            $this->contentRewrite($tag),
            'feed contexts must keep the original <img> tag unchanged',
        );
    }

    /**
     * A core sitemap request (the 'sitemap' query var is set while
     * WP_Sitemaps renders) must likewise keep original URLs.
     */
    public function test_core_sitemap_attachment_url_returns_input_unchanged(): void
    {
        $GLOBALS['__oxpulse_query_vars'] = ['sitemap' => 'posts'];

        $url = 'https://example.com/wp-content/uploads/photo.jpg';
        $this->assertSame(
            $url,
            $this->attachmentRewrite($url),
            'core sitemap contexts must keep the original URL — search engines persist them',
        );
    }

    /**
     * Control: the same adapters DO rewrite on a normal request — the
     * preservation above is the feed/sitemap guard, not a dead rewriter.
     */
    public function test_normal_request_still_rewrites(): void
    {
        $url = 'https://example.com/wp-content/uploads/photo.jpg';
        $this->assertNotSame(
            $url,
            $this->attachmentRewrite($url),
            'control: a normal request must still rewrite (guard is feed/sitemap-specific)',
        );
    }
}
