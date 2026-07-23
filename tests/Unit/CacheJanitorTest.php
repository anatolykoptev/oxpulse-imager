<?php
/**
 * CacheJanitor tests (#93).
 *
 * Verifies the bounded LRU eviction of the LocalBackend on-disk cache:
 *  - Under-cap: evicts nothing.
 *  - Over-cap: evicts oldest-mtime files until under the low-water mark;
 *    newest files survive.
 *  - Hardened files (index.html, .htaccess) are NEVER evicted.
 *  - Path-safety: eviction never touches files outside the cache root
 *    (reuses Uninstaller::isWithinRoot containment guard).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Local\CacheJanitor;
use PHPUnit\Framework\TestCase;

class CacheJanitorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/oxpulse-janitor-' . uniqid();
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rmrf($this->root);
    }

    private function rmrf(string $dir): void
    {
        if (is_link($dir)) {
            unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if (is_link($file->getPathname())) {
                unlink($file->getPathname());
            } else {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }

    /**
     * Write a cache image file with a deterministic size + mtime.
     */
    private function writeCacheFile(string $relPath, int $bytes, int $mtime): void
    {
        $full = $this->root . '/' . $relPath;
        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($full, str_repeat('x', $bytes));
        touch($full, $mtime);
    }

    /**
     * Write a hardened non-image file (index.html / .htaccess).
     */
    private function writeHardenedFile(string $relPath, string $content = ''): void
    {
        $full = $this->root . '/' . $relPath;
        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($full, $content);
    }

    public function test_under_cap_evicts_nothing(): void
    {
        // 2 files of 100 KB each = 200 KB. Cap = 1 MB → under cap.
        $this->writeCacheFile('hashA/key1.webp', 100 * 1024, time() - 200);
        $this->writeCacheFile('hashB/key2.avif', 100 * 1024, time() - 100);

        $janitor = new CacheJanitor($this->root);
        $evicted = $janitor->run(1, 90);

        $this->assertSame(0, $evicted, 'Under-cap cache must evict nothing');
        $this->assertFileExists($this->root . '/hashA/key1.webp');
        $this->assertFileExists($this->root . '/hashB/key2.avif');
    }

    public function test_over_cap_evicts_oldest_mtime_until_low_water(): void
    {
        // 4 files of 500 KB = 2 MB total. Cap = 1 MB, low-water 90% = 900 KB.
        // Oldest two (1 MB) must be evicted to get under 900 KB → 1 file (500 KB) remains.
        // Actually: evict oldest (500KB) → 1.5MB; evict 2nd oldest (500KB) → 1.0MB; still > 900KB;
        // evict 3rd oldest (500KB) → 500KB < 900KB. So 3 evicted, newest survives.
        $old = time() - 300;
        $this->writeCacheFile('h1/oldest.webp', 500 * 1024, $old);
        $this->writeCacheFile('h2/older.avif', 500 * 1024, $old + 1);
        $this->writeCacheFile('h3/newer.webp', 500 * 1024, $old + 2);
        $this->writeCacheFile('h4/newest.avif', 500 * 1024, $old + 3);

        $janitor = new CacheJanitor($this->root);
        $evicted = $janitor->run(1, 90);

        $this->assertGreaterThanOrEqual(3, $evicted, 'Must evict at least 3 oldest files to reach low-water');
        // Newest must survive.
        $this->assertFileExists($this->root . '/h4/newest.avif', 'Newest-mtime file must survive');
        // Oldest must be gone.
        $this->assertFileDoesNotExist($this->root . '/h1/oldest.webp', 'Oldest-mtime file must be evicted first');
    }

    public function test_hardened_files_surve_eviction(): void
    {
        // Over-cap so eviction runs. index.html + .htaccess must survive.
        $this->writeCacheFile('h1/old.webp', 2 * 1024 * 1024, time() - 100);
        $this->writeCacheFile('h1/new.webp', 1 * 1024 * 1024, time());
        $this->writeHardenedFile('index.html', '');
        $this->writeHardenedFile('.htaccess', "RemoveHandler .php\n");

        $janitor = new CacheJanitor($this->root);
        $janitor->run(1, 90);

        $this->assertFileExists($this->root . '/index.html', 'index.html must never be evicted');
        $this->assertFileExists($this->root . '/.htaccess', '.htaccess must never be evicted');
    }

    public function test_non_image_files_are_not_counted_or_evicted(): void
    {
        // A .lock sidecar + a .tmp file must not be counted toward cache
        // size and must not be evicted (they are not cache image files).
        $this->writeCacheFile('h1/img.webp', 2 * 1024 * 1024, time() - 100);
        $this->writeCacheFile('h1/img2.webp', 1 * 1024 * 1024, time());
        // Non-image artifacts.
        $this->writeHardenedFile('h1/img.webp.lock', 'lock');
        $this->writeHardenedFile('h1/img.webp.tmp.123', 'tmp');

        $janitor = new CacheJanitor($this->root);
        $janitor->run(1, 90);

        // The lock + tmp files must survive (not in the image allowlist).
        $this->assertFileExists($this->root . '/h1/img.webp.lock');
        $this->assertFileExists($this->root . '/h1/img.webp.tmp.123');
    }

    public function test_path_safety_never_deletes_outside_cache_root(): void
    {
        // Symlink a directory inside the cache root that points OUTSIDE.
        // The janitor must not follow it to delete files outside the root.
        $outside = sys_get_temp_dir() . '/oxpulse-janitor-outside-' . uniqid();
        mkdir($outside, 0755, true);
        file_put_contents($outside . '/innocent.webp', str_repeat('y', 5 * 1024 * 1024));

        symlink($outside, $this->root . '/escaped');

        // Over-cap to force eviction. The symlinked dir's file resolves
        // outside the root → the guard must refuse to delete it.
        $this->writeCacheFile('real/img.webp', 2 * 1024 * 1024, time() - 100);
        $this->writeCacheFile('real/img2.webp', 1 * 1024 * 1024, time());

        $janitor = new CacheJanitor($this->root);
        $janitor->run(1, 90);

        $this->assertFileExists(
            $outside . '/innocent.webp',
            'Eviction must never delete a file outside the cache root (symlink traversal guard)',
        );
        $this->rmrf($outside);
    }

    public function test_missing_cache_dir_is_noop(): void
    {
        $janitor = new CacheJanitor($this->root . '/does-not-exist');
        $this->assertSame(0, $janitor->run(1, 90));
    }

    public function test_zero_or_negative_cap_is_noop(): void
    {
        $this->writeCacheFile('h1/img.webp', 1024, time());
        $janitor = new CacheJanitor($this->root);
        $this->assertSame(0, $janitor->run(0, 90), 'A zero cap must disable eviction (not wipe everything)');
        $this->assertFileExists($this->root . '/h1/img.webp');
    }
}
