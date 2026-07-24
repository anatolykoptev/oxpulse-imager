<?php
/**
 * RankMathCompatibility tests.
 *
 * Verifies the og:image compatibility layer:
 * - restores direct URL when attachment ID is available
 * - falls back to base64url decode for local:// sources
 * - falls back to plain/ extraction for http sources
 * - clears URL on decode failure (lets RankMath skip gracefully)
 * - rejects path traversal in decoded paths
 * - rejects decoded paths outside /wp-content/
 * - no-op for non-imgproxy URLs (already direct)
 * - no-op for empty url
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Integration\WordPress\Compatibility\RankMathCompatibility;
use PHPUnit\Framework\TestCase;

class RankMathCompatibilityTest extends TestCase
{
    private RankMathCompatibility $compat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compat = new RankMathCompatibility();
        // Reset the attachment URL stub map.
        $GLOBALS['__oxpulse_attachment_urls'] = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['__oxpulse_attachment_urls']);
    }

    public function test_no_op_for_empty_url(): void
    {
        $result = $this->compat->restoreDirectUrl(['url' => '', 'id' => 0]);
        $this->assertSame('', $result['url']);
    }

    public function test_no_op_for_already_direct_url_with_extension(): void
    {
        $attachment = ['url' => 'https://example.com/wp-content/uploads/photo.jpg', 'id' => 0];
        $result = $this->compat->restoreDirectUrl($attachment);
        $this->assertSame($attachment['url'], $result['url']);
    }

    public function test_no_op_for_direct_url_with_webp_extension(): void
    {
        $attachment = ['url' => 'https://example.com/wp-content/uploads/photo.webp', 'id' => 0];
        $result = $this->compat->restoreDirectUrl($attachment);
        $this->assertSame($attachment['url'], $result['url']);
    }

    public function test_restores_direct_url_when_attachment_id_available(): void
    {
        // Stub: wp_get_attachment_url(42) returns a direct URL.
        $GLOBALS['__oxpulse_attachment_urls'] = [
            42 => 'https://example.com/wp-content/uploads/2024/01/photo.jpg',
        ];

        // An imgproxy URL (no extension) with attachment ID.
        $imgproxyUrl = 'https://imgproxy.example.com/abc123/rs:fit:1200:630/local://XYZ';
        $attachment = ['url' => $imgproxyUrl, 'id' => 42];

        $result = $this->compat->restoreDirectUrl($attachment);

        $this->assertSame(
            'https://example.com/wp-content/uploads/2024/01/photo.jpg',
            $result['url']
        );
    }

    public function test_falls_back_to_base64url_decode_for_local_source(): void
    {
        // Build an imgproxy URL with a local:// source pointing to a
        // known filesystem path under /wp-content/.
        $fsPath = '/var/www/wordpress/wp-content/uploads/2024/01/photo.jpg';
        $encoded = rtrim(strtr(base64_encode($fsPath), '+/', '-_'), '=');
        $imgproxyUrl = 'https://imgproxy.example.com/sig/rs:fit:1200:630/local://' . $encoded;

        $attachment = ['url' => $imgproxyUrl, 'id' => 0];
        $result = $this->compat->restoreDirectUrl($attachment);

        // home_url stub returns https://example.test/{path}
        $this->assertSame(
            'https://example.test/wp-content/uploads/2024/01/photo.jpg',
            $result['url']
        );
    }

    public function test_falls_back_to_plain_extraction_for_http_source(): void
    {
        $sourceUrl = 'https://example.com/wp-content/uploads/photo.jpg';
        $imgproxyUrl = 'https://imgproxy.example.com/sig/rs:fit:1200:630/plain/' . $sourceUrl . '@avif';

        $attachment = ['url' => $imgproxyUrl, 'id' => 0];
        $result = $this->compat->restoreDirectUrl($attachment);

        // @avif suffix stripped, direct URL extracted.
        $this->assertSame($sourceUrl, $result['url']);
    }

    public function test_clears_url_on_decode_failure(): void
    {
        // An imgproxy-looking URL (no extension) with a garbage source
        // segment that won't decode to a valid path.
        $imgproxyUrl = 'https://imgproxy.example.com/sig/rs:fit:1200:630/local://!!!invalid-base64!!!';
        $attachment = ['url' => $imgproxyUrl, 'id' => 0];

        $result = $this->compat->restoreDirectUrl($attachment);

        // Decode failed → URL cleared so RankMath skips gracefully.
        $this->assertSame('', $result['url']);
    }

    public function test_rejects_path_traversal_in_decoded_local_path(): void
    {
        // Build a local:// source with a path traversal segment.
        $fsPath = '/var/www/wordpress/wp-content/uploads/../../../etc/passwd';
        $encoded = rtrim(strtr(base64_encode($fsPath), '+/', '-_'), '=');
        $imgproxyUrl = 'https://imgproxy.example.com/sig/rs:fit:1200:630/local://' . $encoded;

        $attachment = ['url' => $imgproxyUrl, 'id' => 0];
        $result = $this->compat->restoreDirectUrl($attachment);

        // Path traversal detected → URL cleared.
        $this->assertSame('', $result['url']);
    }

    public function test_rejects_decoded_path_outside_wp_content(): void
    {
        // A local:// source that decodes to a path NOT under /wp-content/.
        $fsPath = '/etc/shadow';
        $encoded = rtrim(strtr(base64_encode($fsPath), '+/', '-_'), '=');
        $imgproxyUrl = 'https://imgproxy.example.com/sig/rs:fit:1200:630/local://' . $encoded;

        $attachment = ['url' => $imgproxyUrl, 'id' => 0];
        $result = $this->compat->restoreDirectUrl($attachment);

        // Path outside /wp-content/ → URL cleared.
        $this->assertSame('', $result['url']);
    }

    public function test_clears_url_when_attachment_id_lookup_fails(): void
    {
        // Attachment ID present but wp_get_attachment_url returns false.
        $GLOBALS['__oxpulse_attachment_urls'] = [];

        $imgproxyUrl = 'https://imgproxy.example.com/sig/rs:fit:1200:630/local://XYZnotdecodable';
        $attachment = ['url' => $imgproxyUrl, 'id' => 999];

        $result = $this->compat->restoreDirectUrl($attachment);

        // ID lookup failed + decode failed → URL cleared.
        $this->assertSame('', $result['url']);
    }

    public function test_register_adds_rankmath_filters(): void
    {
        $GLOBALS['__oxpulse_filters'] = [];
        $this->compat->register();

        $filterHooks = array_column($GLOBALS['__oxpulse_filters'], 'hook');
        $this->assertContains('rank_math/opengraph/facebook/image_array', $filterHooks);
        $this->assertContains('rank_math/opengraph/twitter/image_array', $filterHooks);
    }

    /**
     * WordPress filter callbacks must tolerate the actual value type
     * they receive — `apply_filters` carries any type. RankMath (or
     * another plugin earlier in the chain) can pass a non-array
     * (empty string, URL string, or false) to the image_array filter
     * when no image is resolved. Under strict_types=1 the `array`
     * param hint throws a TypeError → 500. The callback must pass the
     * non-array value through unchanged.
     */
    public function test_passes_empty_string_through_unchanged(): void
    {
        $result = $this->compat->restoreDirectUrl('');
        $this->assertSame('', $result);
    }

    public function test_passes_url_string_through_unchanged(): void
    {
        $url = 'https://piter.now/wp-content/uploads/2025/04/x.jpg';
        $result = $this->compat->restoreDirectUrl($url);
        $this->assertSame($url, $result);
    }

    public function test_passes_false_through_unchanged(): void
    {
        $result = $this->compat->restoreDirectUrl(false);
        $this->assertSame(false, $result);
    }
}
