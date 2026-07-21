<?php
/**
 * Tests for AttachmentOriginResolver.
 *
 * Verifies that the resolver correctly builds the original (unrewritten)
 * attachment URL from _wp_attached_file metadata and wp_get_upload_dir(),
 * and that buildIntermediateUrl correctly replaces the basename.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentOriginResolver;
use PHPUnit\Framework\TestCase;

final class AttachmentOriginResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_upload_dir'] = [
            'baseurl' => 'https://example.com/wp-content/uploads',
            'basedir' => '/tmp/wp-content/uploads',
            'error'   => false,
        ];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['__oxpulse_upload_dir'], $GLOBALS['__oxpulse_post_meta']);
    }

    public function test_resolve_original_url_from_attached_file_metadata(): void
    {
        $GLOBALS['__oxpulse_post_meta'] = [
            42 => ['_wp_attached_file' => '2026/07/photo.webp'],
        ];

        $url = AttachmentOriginResolver::resolveOriginalUrl(42);

        $this->assertSame('https://example.com/wp-content/uploads/2026/07/photo.webp', $url);
    }

    public function test_resolve_returns_null_when_metadata_missing(): void
    {
        $GLOBALS['__oxpulse_post_meta'] = [];

        $url = AttachmentOriginResolver::resolveOriginalUrl(999);

        $this->assertNull($url);
    }

    public function test_resolve_returns_null_when_uploads_baseurl_empty(): void
    {
        $GLOBALS['__oxpulse_post_meta'] = [
            42 => ['_wp_attached_file' => '2026/07/photo.webp'],
        ];
        $GLOBALS['__oxpulse_upload_dir'] = ['baseurl' => '', 'error' => false];

        $url = AttachmentOriginResolver::resolveOriginalUrl(42);

        $this->assertNull($url);
    }

    public function test_build_intermediate_url_replaces_basename(): void
    {
        $original = 'https://example.com/wp-content/uploads/2026/07/photo.webp';
        $intermediateFile = 'photo-330x220.webp';

        $url = AttachmentOriginResolver::buildIntermediateUrl($original, $intermediateFile);

        $this->assertSame(
            'https://example.com/wp-content/uploads/2026/07/photo-330x220.webp',
            $url
        );
    }

    public function test_build_intermediate_url_preserves_query_string(): void
    {
        $original = 'https://example.com/wp-content/uploads/2026/07/photo.webp?v=2';
        $intermediateFile = 'photo-330x220.webp';

        $url = AttachmentOriginResolver::buildIntermediateUrl($original, $intermediateFile);

        $this->assertSame(
            'https://example.com/wp-content/uploads/2026/07/photo-330x220.webp?v=2',
            $url
        );
    }

    public function test_build_intermediate_url_returns_null_on_parse_failure(): void
    {
        // Empty URL → parse_url returns no host → null.
        $url = AttachmentOriginResolver::buildIntermediateUrl('', 'photo.webp');
        $this->assertNull($url);
    }

    public function test_build_intermediate_url_handles_root_level_file(): void
    {
        // Edge case: attachment file at the uploads root (no subdirectory).
        $original = 'https://example.com/wp-content/uploads/favicon.webp';
        $intermediateFile = 'favicon-32x32.webp';

        $url = AttachmentOriginResolver::buildIntermediateUrl($original, $intermediateFile);

        $this->assertSame(
            'https://example.com/wp-content/uploads/favicon-32x32.webp',
            $url
        );
    }
}
