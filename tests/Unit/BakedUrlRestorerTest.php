<?php
/**
 * BakedUrlRestorer tests.
 *
 * The restorer reverses proxied URLs baked into post_content: the last
 * path segment of a generated URL is base64url("local:///wp-content/...")
 * so the origin is recoverable with no external state. Anything that
 * does not decode cleanly must be left byte-identical and reported.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Repair\BakedUrlRestorer;
use PHPUnit\Framework\TestCase;

class BakedUrlRestorerTest extends TestCase
{
    private function restorer(): BakedUrlRestorer
    {
        return new BakedUrlRestorer('https://example.com/imgproxy', 'https://example.com');
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public function test_restores_src_url(): void
    {
        $b = self::b64('local:///wp-content/uploads/2026/02/photo.webp');
        $content = '<img src="https://example.com/imgproxy/SIGSIG/rs:fill:900:600/fq:avif:56:jpeg:78:webp:70/' . $b . '">';

        $out = $this->restorer()->restore($content);

        $this->assertSame('<img src="https://example.com/wp-content/uploads/2026/02/photo.webp">', $out['content']);
        $this->assertSame(1, $out['replaced']);
        $this->assertSame([], $out['skipped']);
    }

    public function test_restores_url_with_format_suffix(): void
    {
        $b = self::b64('local:///wp-content/uploads/2026/02/photo.webp');
        $content = 'content="https://example.com/imgproxy/SIG/rs:fill:1200:630/' . $b . '.jpg"';

        $out = $this->restorer()->restore($content);

        $this->assertSame('content="https://example.com/wp-content/uploads/2026/02/photo.webp"', $out['content']);
        $this->assertSame(1, $out['replaced']);
    }

    public function test_restores_relative_and_srcset_candidates(): void
    {
        $a = self::b64('local:///wp-content/uploads/a.jpg');
        $b = self::b64('local:///wp-content/uploads/b.jpg');
        $content = 'srcset="/imgproxy/S1/rs:fit:420:0/' . $a . ' 420w, /imgproxy/S2/rs:fit:860:0/' . $b . ' 860w"';

        $out = $this->restorer()->restore($content);

        $this->assertSame(
            'srcset="https://example.com/wp-content/uploads/a.jpg 420w, https://example.com/wp-content/uploads/b.jpg 860w"',
            $out['content']
        );
        $this->assertSame(2, $out['replaced']);
    }

    public function test_foreign_host_endpoint_is_untouched(): void
    {
        $b = self::b64('local:///wp-content/uploads/x.jpg');
        $content = '<img src="https://other.site/imgproxy/SIG/rs:fit:100:0/' . $b . '">';

        $out = $this->restorer()->restore($content);

        $this->assertSame($content, $out['content']);
        $this->assertSame(0, $out['replaced']);
    }

    public function test_undecodable_segment_is_kept_and_reported(): void
    {
        $content = '<img src="https://example.com/imgproxy/SIG/rs:fit:100:0/%%%notbase64%%%">';

        $out = $this->restorer()->restore($content);

        $this->assertSame($content, $out['content']);
        $this->assertSame(0, $out['replaced']);
        $this->assertCount(1, $out['skipped']);
    }

    public function test_decoded_traversal_is_rejected(): void
    {
        $b = self::b64('local:///wp-content/uploads/../../wp-config.php');
        $content = '<img src="https://example.com/imgproxy/SIG/rs:fit:100:0/' . $b . '">';

        $out = $this->restorer()->restore($content);

        $this->assertSame($content, $out['content']);
        $this->assertSame(0, $out['replaced']);
        $this->assertCount(1, $out['skipped']);
    }

    public function test_content_without_proxied_urls_is_untouched(): void
    {
        $content = '<p>text <img src="https://example.com/wp-content/uploads/plain.jpg"></p>';

        $out = $this->restorer()->restore($content);

        $this->assertSame($content, $out['content']);
        $this->assertSame(0, $out['replaced']);
    }
}
