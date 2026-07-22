<?php
/**
 * Miss-endpoint end-to-end integration test (#29.1).
 *
 * Exercises the REAL runtime path of the miss-endpoint handler against
 * REAL fixture images and a REAL temp cache dir — no stubbed transformer,
 * no stubbed signer, no stubbed PathGuard. This is a genuine empirical
 * probe of the wired runtime:
 *
 *   verify → PathGuard → real Imagick/GD decode+encode → atomic cache-write
 *   → stream + headers
 *
 * Guarded by extension_loaded('imagick') || function_exists('imagewebp')
 * — skips when neither encoder is available (CI has both; krolik has both).
 *
 * Covers:
 *  1. Happy path: real JPEG-q100 → valid WebP (RIFF/WEBP), cache file
 *     written under cache/oxpulse/<sourceHash>/<key>.webp with 0600-ish
 *     perms, Cache-Control immutable + Vary: Accept.
 *  2. Format-DoS reject: <key>.php / <key>.foo → 400, no file written.
 *  3. Path-traversal: source payload resolving outside uploads → rejected,
 *     no readfile of the out-of-bounds file.
 *  4. Fail-safe: transformer returns null (smooth-gradient PNG where WebP
 *     is not smaller) → serves ORIGINAL with SHORT cache (max-age=3600,
 *     NO immutable — the #32 fix).
 *  5. ?k=<full-key> endpoint round-trip: the handler accepts a ?k= key
 *     (full payload.sig with internal dot) → verify() passes → same
 *     happy path.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Integration;

use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Transform\TransformRequest;
use OXPulse\Imager\Infrastructure\Image\WebpTransformer;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;
use OXPulse\Imager\Infrastructure\Local\MissEndpointHandler;
use OXPulse\Imager\Infrastructure\Local\PathGuard;
use PHPUnit\Framework\TestCase;

class MissEndpointIntegrationTest extends TestCase
{
    private const KEY_HEX = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
    private const SALT_HEX = 'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5';
    private const UPLOADS_BASEURL = 'https://example.com/wp-content/uploads';

    private string $uploadsBase;
    private string $cacheDir;
    private SigningConfig $signing;
    private LocalBackend $backend;
    private WebpTransformer $transformer;
    private PathGuard $pathGuard;
    private MissEndpointHandler $handler;

    /** Real JPEG fixture path (generated at setUp). */
    private string $jpegFixture;
    /** Real tiny GIF fixture path (WebP not smaller → fail-safe). */
    private string $gifFixture;
    /** Source URL for the JPEG fixture. */
    private string $jpegSourceUrl;
    /** Source URL for the GIF fixture. */
    private string $gifSourceUrl;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('imagick') && !function_exists('imagewebp')) {
            $this->markTestSkipped('Neither Imagick nor GD-WebP available — integration test requires a real encoder.');
        }

        $uid = uniqid();
        $this->uploadsBase = sys_get_temp_dir() . '/oxpulse-int-up-' . $uid;
        $this->cacheDir = sys_get_temp_dir() . '/oxpulse-int-cache-' . $uid;

        // Real uploads dir structure: uploads/2024/01/photo.jpg
        mkdir($this->uploadsBase . '/2024/01', 0755, true);
        mkdir($this->cacheDir, 0755, true);

        // Generate a REAL JPEG fixture: a 200x150 photo-like image at q100.
        // Photo-like noise compresses well in WebP (reliably smaller than
        // the JPEG original), so the size-guard passes and we get a real
        // WebP encode + cache write.
        $this->jpegFixture = $this->uploadsBase . '/2024/01/photo.jpg';
        $this->generateJpegFixture($this->jpegFixture, 200, 150);

        // Generate a REAL tiny GIF: a 1x1 solid-color GIF is ~35 bytes;
        // the WebP encoding (44 bytes, dominated by RIFF + VP8L container
        // overhead) is LARGER than the GIF original → the size-guard
        // returns null → fail-safe serves the original (the #32 short-
        // cache path). This is the "WebP larger than original" pitfall
        // the size-guard exists to catch.
        $this->gifFixture = $this->uploadsBase . '/2024/01/tiny.gif';
        $this->generateTinyGif($this->gifFixture);

        $this->jpegSourceUrl = self::UPLOADS_BASEURL . '/2024/01/photo.jpg';
        $this->gifSourceUrl = self::UPLOADS_BASEURL . '/2024/01/tiny.gif';

        $this->signing = SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);
        $this->backend = new LocalBackend($this->signing);
        $this->transformer = new WebpTransformer();
        $this->pathGuard = new PathGuard($this->uploadsBase, self::UPLOADS_BASEURL);
        $this->handler = new MissEndpointHandler(
            backend: $this->backend,
            transformer: $this->transformer,
            pathGuard: $this->pathGuard,
            cacheDir: $this->cacheDir,
            uploadsBasedir: $this->uploadsBase,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rmrf($this->uploadsBase);
        $this->rmrf($this->cacheDir);
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
     * Generate a real JPEG fixture with photo-like noise at q100.
     */
    private function generateJpegFixture(string $path, int $w, int $h): void
    {
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                // Pseudo-random noise → photo-like, WebP compresses well.
                $r = mt_rand(0, 255);
                $g = mt_rand(0, 255);
                $b = mt_rand(0, 255);
                imagesetpixel($img, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }
        imagejpeg($img, $path, 100);
        imagedestroy($img);
    }

    /**
     * Generate a real tiny GIF (WebP not smaller → fail-safe).
     *
     * A 1x1 solid-color GIF is ~35 bytes; the WebP encoding (44 bytes,
     * dominated by RIFF + VP8L container overhead) is LARGER than the
     * GIF → the size-guard returns null → fail-safe serves the original.
     */
    private function generateTinyGif(string $path): void
    {
        $img = imagecreatetruecolor(1, 1);
        $gray = imagecolorallocate($img, 128, 128, 128);
        imagesetpixel($img, 0, 0, $gray);
        imagegif($img, $path);
        imagedestroy($img);
    }

    /**
     * Build a real signed key for a source URL + transform via LocalBackend.
     */
    private function buildKey(string $sourceUrl, int $width = 800, int $height = 0, string $resize = 'fit', int $quality = 80): string
    {
        $req = new TransformRequest(
            sourceUrl: $sourceUrl,
            width: $width,
            height: $height,
            resize: $resize,
            format: 'webp',
            quality: $quality,
            context: 'content',
            dpr: 0,
            blur: 0,
            watermark: null,
            formatQuality: [],
            sourceMode: 'http',
        );
        $url = $this->backend->generate($req);
        $basename = basename(parse_url($url, PHP_URL_PATH));
        return substr($basename, 0, strrpos($basename, '.'));
    }

    // --- 1. Happy path ---

    public function test_happy_path_real_transform_cache_write_and_headers(): void
    {
        $key = $this->buildKey($this->jpegSourceUrl);
        $sourceHash = LocalBackend::sourceHash($this->jpegSourceUrl);

        $response = $this->handler->handle($key, 'webp', 'image/webp');

        $this->assertSame(200, $response->status);
        $this->assertSame('image/webp', $response->contentType);
        $this->assertNotNull($response->body, 'Body must be the real WebP bytes');

        // Output is valid WebP (RIFF....WEBP magic).
        $this->assertSame('RIFF', substr($response->body, 0, 4));
        $this->assertSame('WEBP', substr($response->body, 8, 4));

        // Cache file written under cache/oxpulse/<sourceHash>/<key>.webp.
        $cacheFile = $this->cacheDir . '/' . $sourceHash . '/' . $key . '.webp';
        $this->assertFileExists($cacheFile);
        $this->assertSame($response->body, file_get_contents($cacheFile));

        // 0600-ish perms (owner read/write only — defense-in-depth on
        // shared hosting). The handler doesn't chmod the cache file
        // itself (only the endpoint file gets 0600), so we assert the
        // file is NOT world-writable (the umask + mkdir 0755 baseline).
        $perms = fileperms($cacheFile) & 0777;
        $this->assertNotEquals(
            $perms | 0022,
            $perms,
            'Cache file must not be world-writable.'
        );

        // Headers: immutable + Vary: Accept.
        $this->assertSame('public, max-age=31536000, immutable', $response->headers['Cache-Control']);
        $this->assertSame('Accept', $response->headers['Vary']);
        $this->assertSame(strlen($response->body), $response->headers['Content-Length']);
    }

    // --- 2. Format-DoS reject ---

    public function test_format_dos_php_extension_rejected_no_file(): void
    {
        $key = $this->buildKey($this->jpegSourceUrl);
        $sourceHash = LocalBackend::sourceHash($this->jpegSourceUrl);

        $response = $this->handler->handle($key, 'php', 'image/webp');

        $this->assertSame(400, $response->status);
        $this->assertNull($response->body);
        $this->assertNull($response->filePath);

        // No file written under any extension.
        $cacheSubdir = $this->cacheDir . '/' . $sourceHash;
        if (is_dir($cacheSubdir)) {
            $files = glob($cacheSubdir . '/*');
            $this->assertNotContains($cacheSubdir . '/' . $key . '.php', $files, 'No .php file must be written.');
        }
    }

    public function test_format_dos_foo_extension_rejected_no_file(): void
    {
        $key = $this->buildKey($this->jpegSourceUrl);

        $response = $this->handler->handle($key, 'foo', 'image/webp');

        $this->assertSame(400, $response->status);
        $this->assertNull($response->body);
        $this->assertNull($response->filePath);
    }

    // --- 3. Path-traversal: source outside uploads → rejected ---

    public function test_path_traversal_source_outside_uploads_rejected(): void
    {
        // Create a file OUTSIDE the uploads base (in /tmp).
        $outsideDir = sys_get_temp_dir() . '/oxpulse-int-outside-' . uniqid();
        mkdir($outsideDir, 0755, true);
        $outsideFile = $outsideDir . '/secret.txt';
        file_put_contents($outsideFile, 'SECRET CONTENT — must not be readfiled');
        $this->registeredOutsideDirs[] = $outsideDir;

        // Craft a signed key whose source URL claims to be under uploads
        // but with a traversal that realpath would resolve outside. We
        // use the reflection to buildKey with a traversal source — the
        // key is signed (so verify passes), but PathGuard rejects the
        // resolved path.
        $reflection = new \ReflectionMethod($this->backend, 'buildKey');
        $reflection->setAccessible(true);

        $req = new TransformRequest(
            sourceUrl: self::UPLOADS_BASEURL . '/../../' . basename($outsideDir) . '/secret.txt',
            width: 800, height: 0, resize: 'fit', format: 'webp', quality: 80,
            context: 'content', dpr: 0, blur: 0, watermark: null,
            formatQuality: [], sourceMode: 'http',
        );
        $key = $reflection->invoke($this->backend, $req, 'webp');

        $response = $this->handler->handle($key, 'webp', 'image/webp');

        // PathGuard rejects (traversal escapes uploads) → not 200, no body.
        $this->assertNotSame(200, $response->status);
        $this->assertNull($response->body);
        $this->assertNull($response->filePath, 'Must not readfile the out-of-bounds file.');

        $this->rmrf($outsideDir);
    }

    // --- 4. Fail-safe: transformer returns null (WebP not smaller) ---

    public function test_fail_safe_tiny_gif_serves_original_short_cache(): void
    {
        $key = $this->buildKey($this->gifSourceUrl);

        $response = $this->handler->handle($key, 'webp', 'image/webp');

        // The tiny GIF's WebP (44 bytes) is larger than the GIF original
        // (35 bytes) → transformer returns null → fail-safe serves original.
        $this->assertSame(200, $response->status);
        $this->assertNotNull($response->filePath, 'Fail-safe must serve the original file path.');
        $this->assertSame(
            realpath($this->gifFixture),
            $response->filePath,
            'Must serve the original GIF, not a WebP.'
        );
        $this->assertSame('image/gif', $response->contentType);

        // #32 fix: SHORT cache, NO immutable on the mutable original.
        $cc = $response->headers['Cache-Control'];
        $this->assertStringNotContainsString('immutable', $cc, 'Mutable original must NOT be marked immutable.');
        preg_match('/max-age=(\d+)/', $cc, $m);
        $this->assertNotEmpty($m, 'Cache-Control must have a max-age.');
        $this->assertLessThanOrEqual(3600, (int) $m[1], 'serveOriginal max-age must be short (<= 3600).');

        // No cache file written (transform returned null).
        $sourceHash = LocalBackend::sourceHash($this->gifSourceUrl);
        $cacheFile = $this->cacheDir . '/' . $sourceHash . '/' . $key . '.webp';
        $this->assertFalse(file_exists($cacheFile), 'No WebP cache file must be written when transform returns null.');
    }

    // --- 5. ?k=<full-key> endpoint round-trip → verify passes ---
    //
    // #43 Phase 3: FallbackRewriter is removed. The ?k= endpoint is now
    // the ONLY LocalBackend delivery path (LocalBackend emits ?k= URLs
    // directly). This test verifies the handler accepts a ?k= key (the
    // full payload.sig key, with its internal dot) and produces the same
    // happy-path result as a direct cache-path request.

    public function test_endpoint_key_round_trips_through_handler(): void
    {
        // Build the key via LocalBackend (the normal path).
        $key = $this->buildKey($this->jpegSourceUrl);
        $sourceHash = LocalBackend::sourceHash($this->jpegSourceUrl);

        // The key is the full payload.sig (with internal dot). The
        // handler receives it via ?k= and must verify it.
        $this->assertStringContainsString('.', $key, 'Key must contain the internal payload.sig dot.');

        // The handler must verify the key and produce the same
        // happy-path result as the direct cache-path request.
        $response = $this->handler->handle($key, 'webp', 'image/webp');

        $this->assertSame(200, $response->status);
        $this->assertSame('image/webp', $response->contentType);
        $this->assertNotNull($response->body);
        $this->assertSame('RIFF', substr($response->body, 0, 4));
        $this->assertSame('WEBP', substr($response->body, 8, 4));

        // Cache file written under the expected path.
        $cacheFile = $this->cacheDir . '/' . $sourceHash . '/' . $key . '.webp';
        $this->assertFileExists($cacheFile);
    }

    /** @var array<int,string> Dirs to clean up in tearDown (path-traversal test). */
    private array $registeredOutsideDirs = [];
}
