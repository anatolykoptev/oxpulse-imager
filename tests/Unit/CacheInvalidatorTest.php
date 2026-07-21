<?php
/**
 * CacheInvalidator tests.
 *
 * Verifies per-attachment cache invalidation:
 * - On metadata-update/delete hook → the attachment's sourceHash dir(s)
 *   are removed (all size variants).
 * - The invalidator enumerates the attachment's original + intermediate
 *   size URLs, computes sourceHash for each, and deletes the dirs.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Local\CacheInvalidator;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;
use PHPUnit\Framework\TestCase;

class CacheInvalidatorTest extends TestCase
{
    private string $cacheDir;
    private string $uploadsBase;
    private CacheInvalidator $invalidator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/oxpulse-inv-' . uniqid();
        $this->uploadsBase = sys_get_temp_dir() . '/oxpulse-inv-up-' . uniqid();
        mkdir($this->cacheDir, 0755, true);
        mkdir($this->uploadsBase . '/2024/01', 0755, true);

        $GLOBALS['__oxpulse_post_meta'] = [];
        $GLOBALS['__oxpulse_attachment_urls'] = [];
        $GLOBALS['__oxpulse_upload_dir'] = [
            'baseurl'    => 'https://example.com/wp-content/uploads',
            'basedir'    => $this->uploadsBase,
            'baseurlrel' => '/wp-content/uploads',
            'error'      => false,
        ];

        $this->invalidator = new CacheInvalidator($this->cacheDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rmrf($this->cacheDir);
        $this->rmrf($this->uploadsBase);
        unset($GLOBALS['__oxpulse_post_meta']);
        unset($GLOBALS['__oxpulse_attachment_urls']);
        unset($GLOBALS['__oxpulse_upload_dir']);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    private function seedCacheForSource(string $sourceUrl): void
    {
        $hash = LocalBackend::sourceHash($sourceUrl);
        $dir = $this->cacheDir . '/' . $hash;
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/some-key.webp', 'webp-bytes');
        file_put_contents($dir . '/another-key.webp', 'webp-bytes-2');
    }

    public function test_invalidates_attachment_by_metadata_update(): void
    {
        $originalUrl = 'https://example.com/wp-content/uploads/2024/01/photo.jpg';
        $thumbUrl = 'https://example.com/wp-content/uploads/2024/01/photo-150x150.jpg';

        // Seed cache for both the original and the thumbnail.
        $this->seedCacheForSource($originalUrl);
        $this->seedCacheForSource($thumbUrl);

        // Set up attachment metadata.
        $GLOBALS['__oxpulse_post_meta'][42] = [
            '_wp_attached_file' => '2024/01/photo.jpg',
        ];
        $GLOBALS['__oxpulse_attachment_meta'][42] = [
            'sizes' => [
                'thumbnail' => ['file' => 'photo-150x150.jpg'],
            ],
        ];

        $deleted = $this->invalidator->invalidateAttachment(42);

        $this->assertGreaterThanOrEqual(2, $deleted, 'Should delete at least 2 sourceHash dirs');
        $this->assertFileDoesNotExist($this->cacheDir . '/' . LocalBackend::sourceHash($originalUrl));
        $this->assertFileDoesNotExist($this->cacheDir . '/' . LocalBackend::sourceHash($thumbUrl));
    }

    public function test_invalidates_attachment_by_delete(): void
    {
        $originalUrl = 'https://example.com/wp-content/uploads/2024/01/photo.jpg';
        $this->seedCacheForSource($originalUrl);

        $GLOBALS['__oxpulse_post_meta'][42] = [
            '_wp_attached_file' => '2024/01/photo.jpg',
        ];
        $GLOBALS['__oxpulse_attachment_meta'][42] = ['sizes' => []];

        $deleted = $this->invalidator->invalidateAttachment(42);

        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertFileDoesNotExist($this->cacheDir . '/' . LocalBackend::sourceHash($originalUrl));
    }

    public function test_no_cache_dir_for_attachment_is_noop(): void
    {
        $GLOBALS['__oxpulse_post_meta'][42] = [
            '_wp_attached_file' => '2024/01/nonexistent.jpg',
        ];
        $GLOBALS['__oxpulse_attachment_meta'][42] = ['sizes' => []];

        $deleted = $this->invalidator->invalidateAttachment(42);
        $this->assertSame(0, $deleted);
    }

    public function test_missing_metadata_is_noop(): void
    {
        $deleted = $this->invalidator->invalidateAttachment(999);
        $this->assertSame(0, $deleted);
    }

    public function test_purge_all_clears_entire_cache_dir(): void
    {
        $this->seedCacheForSource('https://example.com/wp-content/uploads/a.jpg');
        $this->seedCacheForSource('https://example.com/wp-content/uploads/b.jpg');

        $count = $this->invalidator->purgeAll();

        $this->assertGreaterThanOrEqual(2, $count);
        // Cache dir exists but is empty.
        $this->assertSame([], glob($this->cacheDir . '/*'));
    }

    /**
     * VERIFY open-Q: the sourceHash at GENERATION (UrlRewriter →
     * LocalBackend, computed over NormalizedUrl::__toString — host
     * lowercased, fragment stripped) MUST match the sourceHash at
     * INVALIDATION (CacheInvalidator, computed over the raw uploads
     * baseurl + attached file). Without normalization on the
     * invalidation side, a baseurl with an UPPERCASE host produces a
     * DIFFERENT hash → invalidation silently misses the generated dir.
     *
     * (Ports are NOT a divergence: NormalizedUrl preserves explicit
     * ports, so both sides already match when baseurl carries :443.
     * Host case IS a divergence: NormalizedUrl lowercases the host,
     * AttachmentOriginResolver preserves it verbatim.)
     *
     * This test seeds the cache with the NORMALIZED form (what
     * LocalBackend would generate) and asserts the invalidator (fed the
     * raw, uppercase-host baseurl) deletes the same dir.
     */
    public function test_invalidation_hits_generation_dir_despite_uppercase_host(): void
    {
        $normalizedUrl = 'https://example.com/wp-content/uploads/2024/01/photo.jpg';
        $this->seedCacheForSource($normalizedUrl);

        $GLOBALS['__oxpulse_upload_dir'] = [
            'baseurl'    => 'https://EXAMPLE.com/wp-content/uploads',
            'basedir'    => $this->uploadsBase,
            'baseurlrel' => '/wp-content/uploads',
            'error'      => false,
        ];
        $GLOBALS['__oxpulse_post_meta'][42] = [
            '_wp_attached_file' => '2024/01/photo.jpg',
        ];
        $GLOBALS['__oxpulse_attachment_meta'][42] = ['sizes' => []];

        $deleted = $this->invalidator->invalidateAttachment(42);

        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertFileDoesNotExist($this->cacheDir . '/' . LocalBackend::sourceHash($normalizedUrl));
    }

    /**
     * Round-trip: a cache dir generated by LocalBackend for an
     * attachment's source URL is the SAME dir CacheInvalidator targets
     * on invalidation. This is the direct "generation dir == invalidation
     * dir" confirmation the open-Q asks for.
     */
    public function test_generation_dir_equals_invalidation_dir(): void
    {
        $sourceUrl = 'https://example.com/wp-content/uploads/2024/01/photo.jpg';

        // Generation path: LocalBackend::generate hashes the source URL
        // (after UrlRewriter normalization) to pick the cache subdir.
        $generationHash = LocalBackend::sourceHash($sourceUrl);
        $generationDir = $this->cacheDir . '/' . $generationHash;
        mkdir($generationDir, 0755, true);
        file_put_contents($generationDir . '/key.webp', 'bytes');

        // Invalidation path: CacheInvalidator resolves the attachment's
        // URL via AttachmentOriginResolver + normalizes, then hashes.
        $GLOBALS['__oxpulse_post_meta'][42] = [
            '_wp_attached_file' => '2024/01/photo.jpg',
        ];
        $GLOBALS['__oxpulse_attachment_meta'][42] = ['sizes' => []];

        $deleted = $this->invalidator->invalidateAttachment(42);

        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertFileDoesNotExist($generationDir);
    }
}
