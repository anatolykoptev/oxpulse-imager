<?php
/**
 * PathGuard tests.
 *
 * Verifies the path-traversal defense for the miss-endpoint: maps a
 * source URL to an absolute filesystem path under the uploads base,
 * rejecting directory traversal, null bytes, absolute paths, and
 * symlink escapes. A legit uploads path is accepted.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Local\PathGuard;
use PHPUnit\Framework\TestCase;

class PathGuardTest extends TestCase
{
    private string $uploadsBase;
    private string $uploadsBaseUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploadsBase = sys_get_temp_dir() . '/oxpulse-pg-' . uniqid();
        $this->uploadsBaseUrl = 'https://example.com/wp-content/uploads';
        mkdir($this->uploadsBase, 0755, true);
        mkdir($this->uploadsBase . '/2024/01', 0755, true);
        file_put_contents($this->uploadsBase . '/2024/01/photo.jpg', 'jpeg-bytes');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rmrf($this->uploadsBase);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if (is_link($file->getPathname())) {
                unlink($file->getPathname());
            } elseif ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }

    private function guard(): PathGuard
    {
        return new PathGuard($this->uploadsBase, $this->uploadsBaseUrl);
    }

    // --- Accept ---

    public function test_accepts_legit_uploads_path(): void
    {
        $path = $this->guard()->resolve(
            'https://example.com/wp-content/uploads/2024/01/photo.jpg'
        );
        $this->assertNotNull($path);
        $this->assertSame(
            realpath($this->uploadsBase . '/2024/01/photo.jpg'),
            $path
        );
    }

    public function test_accepts_subdir_path(): void
    {
        $path = $this->guard()->resolve(
            'https://example.com/wp-content/uploads/2026/07/deep/nested/img.png'
        );
        // File does not exist → realpath returns false → null (no readfile).
        $this->assertNull($path);
    }

    // --- Reject ---

    public function test_rejects_directory_traversal(): void
    {
        $path = $this->guard()->resolve(
            'https://example.com/wp-content/uploads/../../etc/passwd'
        );
        $this->assertNull($path);
    }

    public function test_rejects_double_dot_in_middle(): void
    {
        $path = $this->guard()->resolve(
            'https://example.com/wp-content/uploads/2024/../2024/01/photo.jpg'
        );
        // After realpath collapse this resolves to a valid uploads path,
        // but the '..' segment is rejected by the lexical guard.
        $this->assertNull($path);
    }

    public function test_rejects_null_byte(): void
    {
        $path = $this->guard()->resolve(
            "https://example.com/wp-content/uploads/2024/01/photo.jpg\0evil.php"
        );
        $this->assertNull($path);
    }

    public function test_rejects_absolute_file_path_as_source(): void
    {
        $path = $this->guard()->resolve('/etc/passwd');
        $this->assertNull($path);
    }

    public function test_rejects_wrong_host(): void
    {
        $path = $this->guard()->resolve(
            'https://evil.com/wp-content/uploads/2024/01/photo.jpg'
        );
        $this->assertNull($path);
    }

    public function test_rejects_wrong_scheme(): void
    {
        $path = $this->guard()->resolve(
            'http://example.com/wp-content/uploads/2024/01/photo.jpg'
        );
        $this->assertNull($path);
    }

    public function test_rejects_symlink_escape(): void
    {
        if (!function_exists('symlink') || !symlink('/etc', $this->uploadsBase . '/escape')) {
            $this->markTestSkipped('symlink not available');
        }
        $path = $this->guard()->resolve(
            'https://example.com/wp-content/uploads/escape/passwd'
        );
        $this->assertNull($path);
    }

    public function test_rejects_source_outside_uploads_prefix(): void
    {
        $path = $this->guard()->resolve(
            'https://example.com/wp-content/uploads-secret/photo.jpg'
        );
        $this->assertNull($path);
    }

    public function test_rejects_empty_source(): void
    {
        $this->assertNull($this->guard()->resolve(''));
    }

    public function test_rejects_malformed_url(): void
    {
        $this->assertNull($this->guard()->resolve('not-a-url'));
    }
}
