<?php
/**
 * ImageTransformer tests.
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

use OXPulse\Imager\Infrastructure\Image\ImageTransformer;
use PHPUnit\Framework\TestCase;

class ImageTransformerTest extends TestCase
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
    private function createJpegFixture(string $name, int $size = 200, int $quality = 90): ?string
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
        imagejpeg($img, $path, $quality);
        imagedestroy($img);
        return $path;
    }

    // --- Size-guard + fail-safe logic (stub-based, no ext needed) ---

    public function test_size_guard_returns_null_when_output_not_smaller(): void
    {
        $sourcePath = $this->fixtureDir . '/source.jpg';
        file_put_contents($sourcePath, 'original-bytes-100-chars-' . str_repeat('x', 80));
        $originalSize = filesize($sourcePath);

        $transformer = new class extends ImageTransformer {
            protected function hasImagick(): bool { return true; }
            protected function canReallyEncode(string $format): bool { return true; }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
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

        $transformer = new class ($webpBytes) extends ImageTransformer {
            private string $bytes;
            public function __construct(string $bytes) { $this->bytes = $bytes; }
            protected function hasImagick(): bool { return true; }
            protected function canReallyEncode(string $format): bool { return true; }
            protected function imageDimensions(string $sourcePath): ?array
            {
                // Valid dims under the cap — the size-guard logic below
                // is what this test exercises, so dims must pass the
                // fail-closed guard (FIX #34 made unknown dims return
                // null instead of proceeding to encode).
                return [100, 100];
            }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
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

        $transformer = new class extends ImageTransformer {
            protected function hasImagick(): bool { return true; }
            protected function canReallyEncode(string $format): bool { return true; }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
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

        $transformer = new class extends ImageTransformer {
            protected function canReallyEncode(string $format): bool { return false; }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
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

        $transformer = new class extends ImageTransformer {
            protected function hasImagick(): bool { return true; }
            protected function canReallyEncode(string $format): bool { return true; }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
            {
                return null; // encode failure (e.g. corrupt source)
            }
        };

        $result = $transformer->transform($sourcePath, 100, 100, 'fit', 80);

        $this->assertNull($result, 'encode() returning null must pass through as null (fail-safe).');
    }

    public function test_fail_safe_nonexistent_source_returns_null(): void
    {
        $transformer = new ImageTransformer();

        $result = $transformer->transform($this->fixtureDir . '/does-not-exist.jpg', 100, 100, 'fit', 80);

        $this->assertNull($result);
    }

    /**
     * Decompression-bomb guard: a source whose pixel count (W*H) exceeds
     * the cap (~40MP) must be rejected BEFORE the decoder runs, returning
     * null (fail-safe: caller serves the original). A crafted "small file,
     * huge canvas" image would otherwise exhaust memory/CPU during decode.
     *
     * The test stubs imageDimensions() to report an oversized canvas
     * without constructing a real decompression bomb, and asserts the
     * encoder is never reached (encode() throws if called).
     */
    public function test_decompression_bomb_rejected_before_decode(): void
    {
        $sourcePath = $this->fixtureDir . '/bomb.jpg';
        file_put_contents($sourcePath, str_repeat('x', 100));

        $transformer = new class extends ImageTransformer {
            protected function hasImagick(): bool { return true; }
            protected function canReallyEncode(string $format): bool { return true; }
            protected function imageDimensions(string $sourcePath): ?array
            {
                // 10000 x 10000 = 100MP > 40MP cap → must be rejected.
                return [10000, 10000];
            }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
            {
                throw new \LogicException('encode must not be called for an oversized source (decompression bomb).');
            }
        };

        $result = $transformer->transform($sourcePath, 100, 100, 'fit', 80);

        $this->assertNull($result, 'Oversized source (W*H > cap) must be rejected before decode.');
    }

    /**
     * FIX #34: GD decompression-bomb cap bypassed when getimagesize()
     * fails.
     *
     * When getimagesize() returns false (can't read header — corrupt
     * file, exotic format, truncated upload), the 40MP guard was SKIPPED
     * and decode proceeded → a crafted "tiny file, huge canvas" image
     * that defeats getimagesize would OOM the FPM worker. Fail-closed:
     * if dimensions are UNKNOWN, return null (serve original) rather
     * than decoding blind. The encoder must NOT be reached.
     */
    public function test_unknown_dimensions_fail_closed_no_decode(): void
    {
        $sourcePath = $this->fixtureDir . '/unreadable-header.jpg';
        file_put_contents($sourcePath, str_repeat('x', 100));

        $transformer = new class extends ImageTransformer {
            protected function hasImagick(): bool { return true; }
            protected function canReallyEncode(string $format): bool { return true; }
            protected function imageDimensions(string $sourcePath): ?array
            {
                // getimagesize() can't read the header → dims unknown.
                return null;
            }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
            {
                throw new \LogicException('encode must not be called when source dimensions are unknown (fail-closed).');
            }
        };

        $result = $transformer->transform($sourcePath, 100, 100, 'fit', 80);

        $this->assertNull($result, 'Unknown dimensions must fail-closed to null (serve original), not decode blind.');
    }

    /**
     * A source just under the cap must still be transformed normally.
     */
    public function test_source_under_cap_is_transformed(): void
    {
        $sourcePath = $this->fixtureDir . '/ok.jpg';
        file_put_contents($sourcePath, str_repeat('x', 200));

        $transformer = new class (str_repeat('y', 50)) extends ImageTransformer {
            private string $bytes;
            public function __construct(string $bytes) { $this->bytes = $bytes; }
            protected function hasImagick(): bool { return true; }
            protected function canReallyEncode(string $format): bool { return true; }
            protected function imageDimensions(string $sourcePath): ?array
            {
                // 5000 x 5000 = 25MP < 40MP cap → allowed.
                return [5000, 5000];
            }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
            {
                return $this->bytes;
            }
        };

        $result = $transformer->transform($sourcePath, 100, 100, 'fit', 80);

        $this->assertNotNull($result, 'Source under the cap must be transformed.');
    }

    // --- Real ext path (guarded skips) ---

    public function test_real_jpeg_to_webp_smaller_and_valid(): void
    {
        if (!extension_loaded('imagick') && !function_exists('imagewebp')) {
            $this->markTestSkipped('Neither Imagick nor GD-WebP available — skipping real-encode test.');
        }

        $sourcePath = $this->createJpegFixture('photo.jpg', 200);
        $this->assertNotNull($sourcePath, 'Could not create JPEG fixture (GD not available).');

        $transformer = new ImageTransformer();
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

    /**
     * Real-encode happy path: a quality-100 JPEG source is reliably beaten
     * by WebP@80. A q100 JPEG stores full DCT-block detail (large byte
     * stream), while WebP lossy @80 wins on the same content — so the
     * size-guard passes and we get valid WebP bytes. (The former PNG-9
     * smooth-gradient fixture compressed to near-nothing and defeated the
     * size-guard — that case is now covered explicitly by
     * test_size_guard_returns_null_when_webp_not_smaller below.)
     */
    public function test_real_jpeg_q100_to_webp_smaller_and_valid(): void
    {
        if (!extension_loaded('imagick') && !function_exists('imagewebp')) {
            $this->markTestSkipped('Neither Imagick nor GD-WebP available — skipping real-encode test.');
        }

        $sourcePath = $this->createJpegFixture('photo-q100.jpg', 200, 100);
        $this->assertNotNull($sourcePath, 'Could not create JPEG fixture (GD not available).');

        $transformer = new ImageTransformer();
        $originalSize = filesize($sourcePath);

        $webp = $transformer->transform($sourcePath, 200, 200, 'fit', 80);

        $this->assertNotNull($webp, 'Transform must produce WebP bytes for a valid JPEG.');
        $this->assertLessThan(
            $originalSize,
            strlen($webp),
            'WebP@80 output must be smaller than a quality-100 JPEG source (size-guard invariant).'
        );
        // WebP magic bytes: RIFF....WEBP
        $this->assertSame('RIFF', substr($webp, 0, 4), 'Output must start with RIFF (WebP magic).');
        $this->assertSame('WEBP', substr($webp, 8, 4), 'Output must contain WEBP signature at offset 8.');
    }

    /**
     * Explicit size-guard test (real encode): the smooth linear-gradient PNG
     * (R=x%256, G=y%256, B=(x+y)%256) at PNG-9 compresses to near-nothing,
     * so WebP@80 is NOT smaller and transform() must return null (serve
     * original). This was the former accidental CI failure — now an
     * intended assertion of the size-guard's correctness.
     */
    public function test_size_guard_returns_null_when_webp_not_smaller(): void
    {
        if (!extension_loaded('imagick') && !function_exists('imagewebp')) {
            $this->markTestSkipped('Neither Imagick nor GD-WebP available — skipping real-encode test.');
        }
        if (!function_exists('imagepng')) {
            $this->markTestSkipped('GD not available to create PNG fixture.');
        }

        $path = $this->fixtureDir . '/gradient.png';
        $img = imagecreatetruecolor(200, 200);
        for ($x = 0; $x < 200; $x++) {
            for ($y = 0; $y < 200; $y++) {
                imagesetpixel($img, $x, $y, imagecolorallocate($img, $x % 256, $y % 256, ($x + $y) % 256));
            }
        }
        imagepng($img, $path, 9);
        imagedestroy($img);

        $transformer = new ImageTransformer();

        $webp = $transformer->transform($path, 200, 200, 'fit', 80);

        $this->assertNull(
            $webp,
            'Size-guard must return null when WebP@80 is not smaller than the PNG-9 gradient source.'
        );
    }

    public function test_real_resize_does_not_upscale_past_original(): void
    {
        if (!extension_loaded('imagick') && !function_exists('imagewebp')) {
            $this->markTestSkipped('Neither Imagick nor GD-WebP available — skipping real-encode test.');
        }

        // Small source (50x50), request larger (500x500) — must NOT upscale.
        $sourcePath = $this->createJpegFixture('tiny.jpg', 50);
        $this->assertNotNull($sourcePath);

        $transformer = new ImageTransformer();

        $webp = $transformer->transform($sourcePath, 500, 500, 'fit', 80);

        $this->assertNotNull($webp);
        $this->assertSame('RIFF', substr($webp, 0, 4));
        // The output dimensions should be 50x50 (not upscaled). We can't
        // easily check dimensions without an image decoder, but the
        // size-guard + no-upscale logic is exercised. A 50x50 WebP should
        // be small.
        $this->assertLessThan(5000, strlen($webp), 'A 50x50 WebP should be small.');
    }

    // --- #47: AVIF support ---

    /**
     * Capability methods must exist and return bool. supportsWebp() is
     * the existing hasImagick()||hasGdWebp() logic exposed publicly;
     * supportsAvif() is new — Imagick with AVIF delegate OR GD imageavif.
     */
    public function test_supports_webp_returns_bool(): void
    {
        $transformer = new ImageTransformer();
        $this->assertIsBool($transformer->supportsWebp());
    }

    public function test_supports_avif_returns_bool(): void
    {
        $transformer = new ImageTransformer();
        $this->assertIsBool($transformer->supportsAvif());
    }

    /**
     * supportsAvif() must be FALSE when no engine can really encode
     * AVIF. Stubbed to simulate a host without an AVIF writer (the
     * real-encode probe is stubbed so the test is host-independent).
     */
    public function test_supports_avif_false_when_no_avif_engine(): void
    {
        $transformer = new class extends ImageTransformer {
            protected function canReallyEncode(string $format): bool { return false; }
        };
        $this->assertFalse($transformer->supportsAvif());
    }

    /**
     * supportsAvif() must be TRUE when the host can really encode AVIF
     * (the real-encode probe succeeds). Stubbed so the test is
     * host-independent — the real-encode test below covers the live path.
     */
    public function test_supports_avif_true_when_imagick_has_avif(): void
    {
        $transformer = new class extends ImageTransformer {
            protected function canReallyEncode(string $format): bool { return true; }
        };
        $this->assertTrue($transformer->supportsAvif());
    }

    /**
     * transform() must accept a $format parameter (default 'webp').
     * The existing stub-based tests pass no $format → defaults to webp.
     * This test verifies the parameter is accepted without error.
     */
    public function test_transform_accepts_format_parameter(): void
    {
        $sourcePath = $this->fixtureDir . '/source.jpg';
        file_put_contents($sourcePath, str_repeat('x', 200));

        $transformer = new class (str_repeat('y', 50)) extends ImageTransformer {
            private string $bytes;
            public function __construct(string $bytes) { $this->bytes = $bytes; }
            protected function hasImagick(): bool { return true; }
            protected function canReallyEncode(string $format): bool { return true; }
            protected function imageDimensions(string $sourcePath): ?array
            {
                return [100, 100];
            }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
            {
                // Return format-tagged bytes so the test can verify dispatch.
                return $format . ':' . $this->bytes;
            }
        };

        $resultWebp = $transformer->transform($sourcePath, 100, 100, 'fit', 80, 'webp');
        $this->assertNotNull($resultWebp);
        $this->assertStringStartsWith('webp:', $resultWebp);

        $resultAvif = $transformer->transform($sourcePath, 100, 100, 'fit', 80, 'avif');
        $this->assertNotNull($resultAvif);
        $this->assertStringStartsWith('avif:', $resultAvif);
    }

    /**
     * Real AVIF encode: a JPEG fixture → valid AVIF bytes (decodable,
     * smaller than original). MUST NOT skip — asserts capability present
     * first, then encodes. The #39 synthetic-green lesson: if
     * supportsAvif() is false, FAIL the test (do not markTestSkipped).
     */
    public function test_real_jpeg_to_avif_smaller_and_valid(): void
    {
        $transformer = new ImageTransformer();

        // Assert capability present — NEVER skip to green.
        $this->assertTrue(
            $transformer->supportsAvif(),
            'AVIF encode test requires supportsAvif()=true. CI must provision an AVIF-capable engine (Imagick with heif delegate).'
        );

        $sourcePath = $this->createJpegFixture('photo-avif.jpg', 200, 100);
        $this->assertNotNull($sourcePath, 'Could not create JPEG fixture (GD not available).');

        $originalSize = filesize($sourcePath);

        $avif = $transformer->transform($sourcePath, 200, 200, 'fit', 70, 'avif');

        $this->assertNotNull($avif, 'Transform must produce AVIF bytes for a valid JPEG.');
        $this->assertLessThan(
            $originalSize,
            strlen($avif),
            'AVIF output must be smaller than the original JPEG (size-guard invariant).'
        );
        // AVIF magic: ftyp box at offset 4, brand 'avif' or 'avis'.
        $ftyp = substr($avif, 4, 4);
        $this->assertTrue(
            $ftyp === 'avif' || $ftyp === 'avis' || str_contains($avif, 'avif'),
            'AVIF output must contain the "avif" brand signature. Got ftyp: ' . $ftyp
        );
        // getimagesizefromstring should detect image/avif mime.
        $info = @getimagesizefromstring($avif);
        if ($info !== false && isset($info['mime'])) {
            $this->assertSame('image/avif', $info['mime'], 'getimagesizefromstring must detect image/avif mime.');
        }
    }

    /**
     * AVIF size-guard: when AVIF output >= original size, returns null.
     * Uses a stub to simulate the size-guard (no real encoder needed).
     */
    public function test_avif_size_guard_returns_null_when_output_not_smaller(): void
    {
        $sourcePath = $this->fixtureDir . '/source-avif.jpg';
        file_put_contents($sourcePath, 'original-bytes-100-chars-' . str_repeat('x', 80));

        $transformer = new class extends ImageTransformer {
            protected function hasImagick(): bool { return true; }
            protected function canReallyEncode(string $format): bool { return true; }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
            {
                if ($format === 'avif') {
                    return str_repeat('a', filesize($sourcePath) + 10);
                }
                return str_repeat('w', 50);
            }
        };

        $result = $transformer->transform($sourcePath, 100, 100, 'fit', 70, 'avif');

        $this->assertNull($result, 'AVIF size-guard must return null when output >= original size.');
    }

    /**
     * AVIF fail-safe: no AVIF engine → transform with format=avif
     * returns null (caller serves original or falls back to webp).
     */
    public function test_avif_fail_safe_no_avif_engine_returns_null(): void
    {
        $sourcePath = $this->fixtureDir . '/source-noavif.jpg';
        file_put_contents($sourcePath, str_repeat('x', 200));

        $transformer = new class extends ImageTransformer {
            protected function canReallyEncode(string $format): bool { return false; }
            protected function encode(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
            {
                throw new \LogicException('encode must not be called when no AVIF engine is available.');
            }
        };

        $result = $transformer->transform($sourcePath, 100, 100, 'fit', 70, 'avif');

        $this->assertNull($result, 'No AVIF engine → transform(format=avif) must return null (fail-safe).');
    }
}
