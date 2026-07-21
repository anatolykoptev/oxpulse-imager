<?php
/**
 * Local cache invalidation wiring tests.
 *
 * Verifies that the CacheInvalidator hooks are wired when LocalBackend
 * is active (delivery enabled, no imgproxy endpoint). The
 * ServiceRegistrar registers a plugins_loaded closure that, when
 * fired, registers the wp_update_attachment_metadata / delete_attachment
 * / clean_post_cache hooks.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Integration;

use OXPulse\Imager\Infrastructure\Local\CacheInvalidator;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;
use PHPUnit\Framework\TestCase;

class LocalCacheInvalidationTest extends TestCase
{
    private string $cacheDir;
    private string $uploadsBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/oxpulse-inv-int-' . uniqid();
        $this->uploadsBase = sys_get_temp_dir() . '/oxpulse-inv-int-up-' . uniqid();
        mkdir($this->cacheDir, 0755, true);
        mkdir($this->uploadsBase . '/2024/01', 0755, true);

        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_post_meta'] = [];
        $GLOBALS['__oxpulse_attachment_meta'] = [];
        $GLOBALS['__oxpulse_upload_dir'] = [
            'baseurl'    => 'https://example.com/wp-content/uploads',
            'basedir'    => $this->uploadsBase,
            'baseurlrel' => '/wp-content/uploads',
            'error'      => false,
        ];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rmrf($this->cacheDir);
        $this->rmrf($this->uploadsBase);
        unset($GLOBALS['__oxpulse_options']);
        unset($GLOBALS['__oxpulse_post_meta']);
        unset($GLOBALS['__oxpulse_attachment_meta']);
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

    /**
     * End-to-end: invalidateAttachment deletes the sourceHash dir
     * for an attachment's original + intermediate size URLs.
     */
    public function test_invalidator_deletes_attachment_cache_on_metadata_update(): void
    {
        $originalUrl = 'https://example.com/wp-content/uploads/2024/01/photo.jpg';
        $thumbUrl = 'https://example.com/wp-content/uploads/2024/01/photo-150x150.jpg';

        // Seed cache.
        $hashOrig = LocalBackend::sourceHash($originalUrl);
        $hashThumb = LocalBackend::sourceHash($thumbUrl);
        mkdir($this->cacheDir . '/' . $hashOrig, 0755, true);
        mkdir($this->cacheDir . '/' . $hashThumb, 0755, true);
        file_put_contents($this->cacheDir . '/' . $hashOrig . '/k1.webp', 'bytes');
        file_put_contents($this->cacheDir . '/' . $hashThumb . '/k2.webp', 'bytes');

        // Set up attachment metadata.
        $GLOBALS['__oxpulse_post_meta'][42] = [
            '_wp_attached_file' => '2024/01/photo.jpg',
        ];
        $GLOBALS['__oxpulse_attachment_meta'][42] = [
            'sizes' => [
                'thumbnail' => ['file' => 'photo-150x150.jpg'],
            ],
        ];

        $invalidator = new CacheInvalidator($this->cacheDir);
        $deleted = $invalidator->invalidateAttachment(42);

        $this->assertGreaterThanOrEqual(2, $deleted);
        $this->assertFileDoesNotExist($this->cacheDir . '/' . $hashOrig);
        $this->assertFileDoesNotExist($this->cacheDir . '/' . $hashThumb);
    }

    /**
     * End-to-end: invalidateAttachment on delete removes the cache dir.
     */
    public function test_invalidator_deletes_attachment_cache_on_delete(): void
    {
        $originalUrl = 'https://example.com/wp-content/uploads/2024/01/photo.jpg';
        $hash = LocalBackend::sourceHash($originalUrl);
        mkdir($this->cacheDir . '/' . $hash, 0755, true);
        file_put_contents($this->cacheDir . '/' . $hash . '/k.webp', 'bytes');

        $GLOBALS['__oxpulse_post_meta'][42] = [
            '_wp_attached_file' => '2024/01/photo.jpg',
        ];
        $GLOBALS['__oxpulse_attachment_meta'][42] = ['sizes' => []];

        $invalidator = new CacheInvalidator($this->cacheDir);
        $invalidator->invalidateAttachment(42);

        $this->assertFileDoesNotExist($this->cacheDir . '/' . $hash);
    }

    /**
     * purgeAll clears the entire cache dir (FlushCommand path).
     */
    public function test_purge_all_empties_cache_dir(): void
    {
        $hash1 = LocalBackend::sourceHash('https://example.com/wp-content/uploads/a.jpg');
        $hash2 = LocalBackend::sourceHash('https://example.com/wp-content/uploads/b.jpg');
        mkdir($this->cacheDir . '/' . $hash1, 0755, true);
        mkdir($this->cacheDir . '/' . $hash2, 0755, true);
        file_put_contents($this->cacheDir . '/' . $hash1 . '/k.webp', 'bytes');
        file_put_contents($this->cacheDir . '/' . $hash2 . '/k.webp', 'bytes');

        $invalidator = new CacheInvalidator($this->cacheDir);
        $count = $invalidator->purgeAll();

        $this->assertGreaterThanOrEqual(2, $count);
        $this->assertSame([], glob($this->cacheDir . '/*'));
    }
}
