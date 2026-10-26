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
        unset(
            $GLOBALS['__oxpulse_upload_dir'],
            $GLOBALS['__oxpulse_post_meta'],
            $GLOBALS['__oxpulse_is_ssl'],
            $GLOBALS['__oxpulse_is_admin']
        );
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

    /**
     * Absolute _wp_attached_file inside the uploads basedir: core's
     * wp_get_attachment_url replaces basedir with baseurl. The naive
     * baseurl.concat would produce
     * .../uploads/var/www/wp-content/uploads/... — a garbage URL.
     */
    public function test_resolve_absolute_file_inside_basedir_swaps_basedir_for_baseurl(): void
    {
        $GLOBALS['__oxpulse_upload_dir'] = [
            'baseurl' => 'https://example.com/wp-content/uploads',
            'basedir' => '/var/www/wp-content/uploads',
            'error'   => false,
        ];
        $GLOBALS['__oxpulse_post_meta'] = [
            42 => ['_wp_attached_file' => '/var/www/wp-content/uploads/2024/01/photo.jpg'],
        ];

        $url = AttachmentOriginResolver::resolveOriginalUrl(42);

        $this->assertSame('https://example.com/wp-content/uploads/2024/01/photo.jpg', $url);
    }

    /**
     * Absolute _wp_attached_file outside basedir but still under
     * wp-content/uploads: core keeps the part after 'wp-content/uploads'
     * (back-compat branch for pre-2.7 uploads).
     */
    public function test_resolve_absolute_file_outside_basedir_uses_wp_content_uploads_tail(): void
    {
        $GLOBALS['__oxpulse_upload_dir'] = [
            'baseurl' => 'https://example.com/wp-content/uploads',
            'basedir' => '/other/dir/wp-content/uploads',
            'error'   => false,
        ];
        $GLOBALS['__oxpulse_post_meta'] = [
            42 => ['_wp_attached_file' => '/srv/site/htdocs/wp-content/uploads/2024/02/pic.png'],
        ];

        $url = AttachmentOriginResolver::resolveOriginalUrl(42);

        $this->assertSame('https://example.com/wp-content/uploads/2024/02/pic.png', $url);
    }

    /**
     * #149: when the `wp-content/uploads` marker runs into the filename the
     * tail must be cut from the DIRECTORY only (core's
     * _wp_get_attachment_relative_path + wp_basename), not from the whole
     * path — otherwise the filename is mangled to "-photo.jpg" (404).
     */
    public function test_resolve_marker_inside_filename_is_not_mangled(): void
    {
        $GLOBALS['__oxpulse_post_meta'] = [
            42 => ['_wp_attached_file' => '2024/01/wp-content/uploads-photo.jpg'],
        ];

        $url = AttachmentOriginResolver::resolveOriginalUrl(42);

        $this->assertSame('https://example.com/wp-content/uploads/2024/01/wp-content/uploads-photo.jpg', $url);
    }

    /** The dirname split must keep every directory below the marker. */
    public function test_resolve_legacy_absolute_path_keeps_all_subdirectories(): void
    {
        $GLOBALS['__oxpulse_upload_dir'] = [
            'baseurl' => 'https://example.com/wp-content/uploads',
            'basedir' => '/other/wp-content/uploads',
            'error'   => false,
        ];
        $GLOBALS['__oxpulse_post_meta'] = [
            42 => ['_wp_attached_file' => '/legacy/wp-content/uploads/2024/01/photo.jpg'],
        ];

        $this->assertSame(
            'https://example.com/wp-content/uploads/2024/01/photo.jpg',
            AttachmentOriginResolver::resolveOriginalUrl(42)
        );
    }

    /**
     * On an SSL front end, core upgrades the resolved URL to https via
     * set_url_scheme() — an og:image must never emit http://.
     */
    public function test_resolve_upgrades_to_https_on_ssl_front_end(): void
    {
        $GLOBALS['__oxpulse_is_ssl'] = true;
        $GLOBALS['__oxpulse_upload_dir'] = [
            'baseurl' => 'http://example.com/wp-content/uploads',
            'basedir' => '/var/www/wp-content/uploads',
            'error'   => false,
        ];
        $GLOBALS['__oxpulse_post_meta'] = [
            42 => ['_wp_attached_file' => '2024/01/photo.jpg'],
        ];

        $url = AttachmentOriginResolver::resolveOriginalUrl(42);

        $this->assertSame('https://example.com/wp-content/uploads/2024/01/photo.jpg', $url);
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
