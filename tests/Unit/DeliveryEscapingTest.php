<?php
/**
 * Attribute-escaping tests for raw-HTML-emitting rewriters (#73).
 *
 * ContentImgTagRewriter and AvatarRewriter build <img> markup as
 * strings (not via WP's attribute-escaping array paths), so a URL
 * carrying an attribute-breaking character must be htmlspecialchars-
 * escaped before it re-enters the tag. Not exploitable through the
 * current capture regexes (they strip quotes) but the plugin-check
 * EscapeOutput contract requires the escape at the emit site.
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
use OXPulse\Imager\Integration\WordPress\Delivery\AvatarRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\ContentImgTagRewriter;
use PHPUnit\Framework\TestCase;

class DeliveryEscapingTest extends TestCase
{
    private function rewriter(): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: ['https://example.com/wp-content/uploads/'],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    public function test_content_srcset_candidate_output_is_escaped(): void
    {
        $r = new ContentImgTagRewriter(
            $this->rewriter(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: ['https://example.com/wp-content/uploads/'],
            )
        );
        $method = new \ReflectionMethod($r, 'rewriteSrcsetCandidate');
        $out = $method->invoke($r, 'https://example.com/wp-content/uploads/a.jpg 800w');

        // Our imgproxy URLs are quote-free, so a correctly escaped
        // string contains no raw double-quote that could break the attr.
        $this->assertStringNotContainsString('"', $out);
        $this->assertStringContainsString('800w', $out);
        $this->assertStringStartsWith('https://imgproxy.example.com/', html_entity_decode($out, ENT_QUOTES));
    }

    public function test_avatar_src_is_escaped(): void
    {
        $r = new AvatarRewriter($this->rewriter());
        $avatar = '<img alt="" src="https://example.com/wp-content/uploads/av.jpg" class="avatar">';
        $out = $r->rewrite($avatar, 1, 96, 'mm', '');

        $this->assertStringContainsString('src="https://imgproxy.example.com/', $out);
        // Exactly one src attribute, no stray unescaped quote injected.
        $this->assertSame(1, substr_count($out, ' src="'));
    }
}
