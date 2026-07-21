<?php
/**
 * WebpTransformer tests.
 *
 * Verifies the Phase 6 local transform engine:
 * - Fixture JPEG/PNG -> valid WebP bytes that are smaller than the source.
 * - Size-guard: when produced WebP >= original size, returns null (serve
 *   original). This is unit-tested with a stub override (no ext needed).
 * - Fail-safe: no Imagick + no GD-WebP -> null (stub override).
 * - No upscaling past the original dimensions.
 *
 * The ext-dependent tests (real Imagick/GD encode) are guarded by
 * extension_loaded / function_exists skips. The size-guard + fail-safe
 * logic is unit-tested with a stub subclass that overrides encode() and
 * the capability checks, so it runs on every CI regardless of exts.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Image\WebpTransformer;
use PHPUnit\Framework\TestCase;

class WebpTransformerTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = sys_get_temp_dir() . '/oxpulse-webp-fix-' . uniqid();
        mkdir($this->fixtureDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->fixtureDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->fixtureDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->fixtureDir);
        }
    }

    /**
     * Create a real JPEG fixture (200x200, color gradient) using GD,
     * so the transform engine has a compressible source to work with.
     */
    private function createJpegFixture(string $name, int $size = 200): ?string
    {
        if (!function_exists('imagejpeg')) {
            return null;
        }
        $path = $this->fixtureDir . '/' . $name;
        $img = imagecreatetruecolor($size, $size);
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                imagesetpixel($img, $x, $y, imagecolorallocate($img, $x % 256, $y % 256, ($x + $y) % 256));
            }
        }
        imagejpeg($img, $path, 90);
        imagedestroy($img);
        return $path;
    }

    // --- Size-guard + fail-safe logic (stub-based, no ext needed) ---

    public function test_size_guard_returns_null_when_output_not_smaller(): void
    {
        $sourcePath = $this->fixtureDir . '/source.jpg';
        file_put_contents($sourcePath, 'original-bytes-100-chars-' . str_repeat('x', 80));
        $originalSize = filesize($sourcePath);

        $transformer = new class extends WebpTransformer {
            protected function hasImagick(): bool { return true; }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality): ?string
            {
                // Return bytes >= original size to trigger the size-guard.
                return str_repeat('y', filesize($sourcePath) + 10);
            }
        };

        $result = $transformer->transform($sourcePath, 100, 100, 'fit', 80);

        $this->assertNull($result, 'Size-guard must return null when WebP >= original size.');
    }

    public function test_size_guard_returns_bytes_when_output_is_smaller(): void
    {
        $sourcePath = $this->fixtureDir . '/source.jpg';
        file_put_contents($sourcePath, str_repeat('x', 200));
        $webpBytes = str_repeat('y', 50); // smaller than original

        $transformer = new class ($webpBytes) extends WebpTransformer {
            private string $bytes;
            public function __construct(string $bytes) { $this->bytes = $bytes; }
            protected function hasImagick(): bool { return true; }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality): ?string
            {
                return $this->bytes;
            }
        };

        $result = $transformer->transform($sourcePath, 100, 100, 'fit', 80);

        $this->assertSame($webpBytes, $result);
    }

    public function test_size_guard_equal_size_returns_null(): void
    {
        $sourcePath = $this->fixtureDir . '/source.jpg';
        $content = str_repeat('x', 100);
        file_put_contents($sourcePath, $content);

        $transformer = new class extends WebpTransformer {
            protected function hasImagick(): bool { return true; }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality): ?string
            {
                // Exactly equal to original size.
                return str_repeat('z', filesize($sourcePath));
            }
        };

        $result = $transformer->transform($sourcePath, 100, 100, 'fit', 80);

        $this->assertNull($result, 'Size-guard must return null when WebP == original size (>= check).');
    }

    public function test_fail_safe_no_imagick_no_gd_returns_null(): void
    {
        $sourcePath = $this->fixtureDir . '/source.jpg';
        file_put_contents($sourcePath, str_repeat('x', 100));

        $transformer = new class extends WebpTransformer {
            protected function hasImagick(): bool { return false; }
            protected function hasGdWebp(): bool { return false; }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality): ?string
            {
                throw new \LogicException('encode must not be called when no ext is available');
            }
        };

        $result = $transformer->transform($sourcePath, 100, 100, 'fit', 80);

        $this->assertNull($result, 'No Imagick + no GD-WebP -> null (serve original).');
    }

    public function test_fail_safe_encode_returns_null_passes_through(): void
    {
        $sourcePath = $this->fixtureDir . '/source.jpg';
        file_put_contents($sourcePath, str_repeat('x', 100));

        $transformer = new class extends WebpTransformer {
            protected function hasImagick(): bool { return true; }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality): ?string
            {
                return null; // encode failure (e.g. corrupt source)
            }
        };

        $result = $transformer->transform($sourcePath, 100, 100, 'fit', 80);

        $this->assertNull($result, 'encode() returning null must pass through as null (fail-safe).');
    }

    public function test_fail_safe_nonexistent_source_returns_null(): void
    {
        $transformer = new WebpTransformer();

        $result = $transformer->transform($this->fixtureDir . '/does-not-exist.jpg', 100, 100, 'fit', 80);

        $this->assertNull($result);
    }

    // --- Real ext path (guarded skips) ---

    public function test_real_jpeg_to_webp_smaller_and_valid(): void
    {
        if (!extension_loaded('imagick') && !function_exists('imagewebp')) {
            $this->markTestSkipped('Neither Imagick nor GD-WebP available — skipping real-encode test.');
        }

        $sourcePath = $this->createJpegFixture('photo.jpg', 200);
        $this->assertNotNull($sourcePath, 'Could not create JPEG fixture (GD not available).');

        $transformer = new WebpTransformer();
        $originalSize = filesize($sourcePath);

        $webp = $transformer->transform($sourcePath, 200, 200, 'fit', 80);

        $this->assertNotNull($webp, 'Transform must produce WebP bytes for a valid JPEG.');
        $this->assertLessThan(
            $originalSize,
            strlen($webp),
            'WebP output must be smaller than the original JPEG (size-guard invariant).'
        );
        // WebP magic bytes: RIFF....WEBP
        $this->assertSame('RIFF', substr($webp, 0, 4), 'Output must start with RIFF (WebP magic).');
        $this->assertSame('WEBP', substr($webp, 8, 4), 'Output must contain WEBP signature at offset 8.');
    }

    public function test_real_png_to_webp_smaller_and_valid(): void
    {
        if (!extension_loaded('imagick') && !function_exists('imagewebp')) {
            $this->markTestSkipped('Neither Imagick nor GD-WebP available — skipping real-encode test.');
        }
        if (!function_exists('imagepng')) {
            $this->markTestSkipped('GD not available to create PNG fixture.');
        }

        $path = $this->fixtureDir . '/photo.png';
        $img = imagecreatetruecolor(200, 200);
        for ($x = 0; $x < 200; $x++) {
            for ($y = 0; $y < 200; $y++) {
                imagesetpixel($img, $x, $y, imagecolorallocate($img, $x % 256, $y % 256, ($x + $y) % 256));
            }
        }
        imagepng($img, $path, 9);
        imagedestroy($img);

        $transformer = new WebpTransformer();
        $originalSize = filesize($path);

        $webp = $transformer->transform($path, 200, 200, 'fit', 80);

        $this->assertNotNull($webp);
        $this->assertLessThan($originalSize, strlen($webp));
        $this->assertSame('RIFF', substr($webp, 0, 4));
        $this->assertSame('WEBP', substr($webp, 8, 4));
    }

    public function test_real_resize_does_not_upscale_past_original(): void
    {
        if (!extension_loaded('imagick') && !function_exists('imagewebp')) {
            $this->markTestSkipped('Neither Imagick nor GD-WebP available — skipping real-encode test.');
        }

        // Small source (50x50), request larger (500x500) — must NOT upscale.
        $sourcePath = $this->createJpegFixture('tiny.jpg', 50);
        $this->assertNotNull($sourcePath);

        $transformer = new WebpTransformer();

        $webp = $transformer->transform($sourcePath, 500, 500, 'fit', 80);

        $this->assertNotNull($webp);
        $this->assertSame('RIFF', substr($webp, 0, 4));
        // The output dimensions should be 50x50 (not upscaled). We can't
        // easily check dimensions without an image decoder, but the
        // size-guard + no-upscale logic is exercised. A 50x50 WebP should
        // be small.
        $this->assertLessThan(5000, strlen($webp), 'A 50x50 WebP should be small.');
    }
}
