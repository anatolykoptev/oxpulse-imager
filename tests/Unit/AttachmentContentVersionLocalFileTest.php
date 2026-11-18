<?php
/**
 * Local-file fast path of AttachmentContentVersion: files under the uploads
 * directory are versioned from mtime|size with zero attachment lookups.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentContentVersion;
use PHPUnit\Framework\TestCase;

final class AttachmentContentVersionLocalFileTest extends TestCase
{
    private const BASEURL = 'https://example.com/wp-content/uploads';

    private string $root;
    private string $basedir;

    protected function setUp(): void
    {
        $tmp = realpath(sys_get_temp_dir());
        $this->root = $tmp . '/oxpulse-cv-' . bin2hex(random_bytes(6));
        $this->basedir = $this->root . '/uploads';
        mkdir($this->basedir . '/2026/11', 0777, true);
        file_put_contents($this->basedir . '/2026/11/photo.jpg', 'jpeg-bytes');
        file_put_contents($this->root . '/outside.txt', 'secret');

        $GLOBALS['__oxpulse_upload_dir'] = [
            'baseurl' => self::BASEURL,
            'basedir' => $this->basedir,
            'error' => false,
        ];
    }

    protected function tearDown(): void
    {
        @unlink($this->basedir . '/2026/11/my photo.jpg');
        @unlink($this->basedir . '/2026/11/escape.txt');
        @unlink($this->basedir . '/2026/11/photo.jpg');
        @unlink($this->root . '/outside.txt');
        @rmdir($this->basedir . '/2026/11');
        @rmdir($this->basedir . '/2026');
        @rmdir($this->basedir);
        @rmdir($this->root);
        unset(
            $GLOBALS['__oxpulse_upload_dir'],
            $GLOBALS['__oxpulse_url_to_id'],
            $GLOBALS['__oxpulse_url_to_id_calls'],
            $GLOBALS['__oxpulse_post_meta'],
            $GLOBALS['__oxpulse_obj_cache']
        );
        parent::tearDown();
    }

    private function calls(): int
    {
        return (int) ($GLOBALS['__oxpulse_url_to_id_calls'] ?? 0);
    }

    public function test_t1_existing_local_file_is_versioned_without_any_lookup(): void
    {
        $v = (new AttachmentContentVersion())->versionFor(self::BASEURL . '/2026/11/photo.jpg');

        $this->assertMatchesRegularExpression('/^[a-z0-9]{1,16}$/', $v);
        $this->assertSame(0, $this->calls(), 'local file must not hit attachment_url_to_postid');
    }

    public function test_t2_version_changes_when_the_file_mtime_changes(): void
    {
        $file = $this->basedir . '/2026/11/photo.jpg';
        touch($file, 1_700_000_000);
        $a = (new AttachmentContentVersion())->versionFor(self::BASEURL . '/2026/11/photo.jpg');
        touch($file, 1_700_000_500);
        $b = (new AttachmentContentVersion())->versionFor(self::BASEURL . '/2026/11/photo.jpg');

        $this->assertNotSame('', $a);
        $this->assertNotSame($a, $b, 'same size, new mtime must change the version');
    }

    public function test_t3a_dotdot_traversal_url_falls_back_to_the_lookup(): void
    {
        $v = (new AttachmentContentVersion())->versionFor(self::BASEURL . '/2026/11/../../../outside.txt');

        $this->assertSame('', $v, 'the outside file must not be versioned');
        $this->assertGreaterThanOrEqual(1, $this->calls());
    }

    public function test_t3b_symlink_escaping_basedir_falls_back_to_the_lookup(): void
    {
        if (!@symlink($this->root . '/outside.txt', $this->basedir . '/2026/11/escape.txt')) {
            $this->markTestSkipped('symlink() unavailable');
        }

        $v = (new AttachmentContentVersion())->versionFor(self::BASEURL . '/2026/11/escape.txt');

        $this->assertSame('', $v, 'a symlink out of basedir must not be versioned from the target');
        $this->assertGreaterThanOrEqual(1, $this->calls());
    }

    public function test_t4_http_url_on_an_https_site_and_protocol_relative_resolve_locally(): void
    {
        $https = (new AttachmentContentVersion())->versionFor(self::BASEURL . '/2026/11/photo.jpg');
        $http = (new AttachmentContentVersion())->versionFor('http://example.com/wp-content/uploads/2026/11/photo.jpg');
        $rel = (new AttachmentContentVersion())->versionFor('//example.com/wp-content/uploads/2026/11/photo.jpg');

        $this->assertNotSame('', $http);
        $this->assertSame($https, $http);
        $this->assertSame($https, $rel);
        $this->assertSame(0, $this->calls());
    }

    public function test_t5_missing_file_still_uses_the_attachment_lookup(): void
    {
        $url = self::BASEURL . '/2026/11/gone.jpg';
        $GLOBALS['__oxpulse_url_to_id'] = [$url => 9];
        $GLOBALS['__oxpulse_post_meta'] = [9 => [AttachmentContentVersion::META_KEY => 'v9stamp']];

        $this->assertSame('v9stamp', (new AttachmentContentVersion())->versionFor($url));
        $this->assertGreaterThanOrEqual(1, $this->calls());
    }

    public function test_percent_encoded_url_for_a_filename_with_a_space_resolves_locally(): void
    {
        file_put_contents($this->basedir . '/2026/11/my photo.jpg', 'jpeg-bytes');

        $v = (new AttachmentContentVersion())->versionFor(self::BASEURL . '/2026/11/my%20photo.jpg');

        $this->assertNotSame('', $v, 'rawurldecode must map my%20photo.jpg to the file on disk');
        $this->assertSame(0, $this->calls());
    }

    public function test_query_string_does_not_change_the_local_version(): void
    {
        $a = (new AttachmentContentVersion())->versionFor(self::BASEURL . '/2026/11/photo.jpg');
        $b = (new AttachmentContentVersion())->versionFor(self::BASEURL . '/2026/11/photo.jpg?t=1700000000');

        $this->assertSame($a, $b);
    }
}
