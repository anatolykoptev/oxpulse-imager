<?php
/**
 * MissEndpointHandler tests.
 *
 * Verifies the security-critical miss-endpoint logic:
 * - Valid key → transform + atomic-write + cache + response
 * - Tampered/malformed key → 400 reject
 * - Source outside uploads (traversal) → reject, no readfile
 * - Missing source file → no-serve (404 or fail-safe nothing)
 * - Transformer null → serves ORIGINAL (fail-safe)
 * - Atomic write (temp → rename) asserted
 * - Cache headers set (Cache-Control, Vary, Content-Type, Content-Length)
 * - flock miss-dedupe
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Transform\TransformRequest;
use OXPulse\Imager\Infrastructure\Image\ImageTransformer;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;
use OXPulse\Imager\Infrastructure\Local\MissEndpointHandler;
use OXPulse\Imager\Infrastructure\Local\MissEndpointResponse;
use OXPulse\Imager\Infrastructure\Local\PathGuard;
use PHPUnit\Framework\TestCase;

class MissEndpointHandlerTest extends TestCase
{
    private const KEY_HEX = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
    private const SALT_HEX = 'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5';
    private const SOURCE = 'https://example.com/wp-content/uploads/2024/01/photo.jpg';

    private string $uploadsBase;
    private string $cacheDir;
    private string $uploadsBaseUrl;
    private LocalBackend $backend;
    private SigningConfig $signing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploadsBase = sys_get_temp_dir() . '/oxpulse-ep-up-' . uniqid();
        $this->cacheDir = sys_get_temp_dir() . '/oxpulse-ep-cache-' . uniqid();
        $this->uploadsBaseUrl = 'https://example.com/wp-content/uploads';
        mkdir($this->uploadsBase . '/2024/01', 0755, true);
        mkdir($this->cacheDir, 0755, true);
        // A fake "original" JPEG (larger than the stub webp output so
        // the size-guard in the real transformer would pass, but we
        // use a stub transformer that bypasses size-guard).
        file_put_contents($this->uploadsBase . '/2024/01/photo.jpg', str_repeat('jpeg-original-bytes-' . PHP_EOL, 50));

        $this->signing = SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);
        $this->backend = new LocalBackend($this->signing);
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

    private function handler(?ImageTransformer $transformer = null): MissEndpointHandler
    {
        return new MissEndpointHandler(
            backend: $this->backend,
            transformer: $transformer ?? new StubTransformer('webp-bytes-from-stub'),
            pathGuard: new PathGuard($this->uploadsBase, $this->uploadsBaseUrl),
            cacheDir: $this->cacheDir,
            uploadsBasedir: $this->uploadsBase,
        );
    }

    private function validKey(): string
    {
        $req = new TransformRequest(
            sourceUrl: self::SOURCE,
            width: 800,
            height: 0,
            resize: 'fit',
            format: 'webp',
            quality: 80,
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

    // --- Valid key → transform + cache + response ---

    public function test_valid_key_transforms_caches_and_streams(): void
    {
        $handler = $this->handler();
        $key = $this->validKey();

        $response = $handler->handle($key, 'webp', 'image/webp');

        $this->assertSame(200, $response->status);
        $this->assertSame('image/webp', $response->contentType);
        $this->assertNotNull($response->body);
        $this->assertSame('webp-bytes-from-stub', $response->body);
        // Cache file was written.
        $sourceHash = LocalBackend::sourceHash(self::SOURCE);
        $cacheFile = $this->cacheDir . '/' . $sourceHash . '/' . $key . '.webp';
        $this->assertFileExists($cacheFile);
        $this->assertSame('webp-bytes-from-stub', file_get_contents($cacheFile));
    }

    public function test_valid_key_sets_cache_headers(): void
    {
        $handler = $this->handler();
        $key = $this->validKey();

        $response = $handler->handle($key, 'webp', 'image/webp');

        $this->assertArrayHasKey('Cache-Control', $response->headers);
        $this->assertSame('public, max-age=31536000, immutable', $response->headers['Cache-Control']);
        $this->assertSame('Accept', $response->headers['Vary']);
        $this->assertSame(strlen($response->body), $response->headers['Content-Length']);
    }

    // --- Tampered key → reject ---

    public function test_tampered_key_rejected_with_400(): void
    {
        $handler = $this->handler();
        $key = $this->validKey();
        $parts = explode('.', $key);
        $tampered = $parts[0] . '.' . ($parts[1] === 'A' ? 'B' : 'A');

        $response = $handler->handle($tampered, 'webp', 'image/webp');

        $this->assertSame(400, $response->status);
        $this->assertNull($response->body);
        $this->assertNull($response->filePath);
    }

    public function test_malformed_key_rejected_with_400(): void
    {
        $handler = $this->handler();

        $response = $handler->handle('garbage', 'webp', 'image/webp');

        $this->assertSame(400, $response->status);
    }

    // --- Source outside uploads (traversal) → reject ---

    public function test_traversal_source_rejected_no_readfile(): void
    {
        // Build a key with a traversal source. We need to craft a
        // payload+sig that the backend will accept — but the source
        // points outside uploads. Since the key is signed, we sign it
        // with the same backend.
        $reflection = new \ReflectionMethod($this->backend, 'buildKey');
        $reflection->setAccessible(true);

        $req = new TransformRequest(
            sourceUrl: 'https://example.com/wp-content/uploads/../../etc/passwd',
            width: 800, height: 0, resize: 'fit', format: 'webp', quality: 80,
            context: 'content', dpr: 0, blur: 0, watermark: null,
            formatQuality: [], sourceMode: 'http',
        );
        $key = $reflection->invoke($this->backend, $req, 'webp');

        $handler = $this->handler();
        $response = $handler->handle($key, 'webp', 'image/webp');

        // PathGuard rejects → fail-safe serves nothing (404 or 400).
        $this->assertNotSame(200, $response->status);
        $this->assertNull($response->body);
        $this->assertNull($response->filePath);
    }

    // --- Missing source file → no-serve ---

    public function test_missing_source_file_no_serve(): void
    {
        $reflection = new \ReflectionMethod($this->backend, 'buildKey');
        $reflection->setAccessible(true);

        $req = new TransformRequest(
            sourceUrl: 'https://example.com/wp-content/uploads/2024/01/nonexistent.jpg',
            width: 800, height: 0, resize: 'fit', format: 'webp', quality: 80,
            context: 'content', dpr: 0, blur: 0, watermark: null,
            formatQuality: [], sourceMode: 'http',
        );
        $key = $reflection->invoke($this->backend, $req, 'webp');

        $handler = $this->handler();
        $response = $handler->handle($key, 'webp', 'image/webp');

        $this->assertNotSame(200, $response->status);
        $this->assertNull($response->body);
    }

    // --- Transformer null → serves ORIGINAL (fail-safe) ---

    public function test_transformer_null_serves_original(): void
    {
        $handler = $this->handler(new NullTransformer());
        $key = $this->validKey();

        $response = $handler->handle($key, 'webp', 'image/webp');

        $this->assertSame(200, $response->status);
        // Fail-safe: serves the original file bytes.
        $this->assertNotNull($response->filePath);
        $this->assertSame(
            realpath($this->uploadsBase . '/2024/01/photo.jpg'),
            $response->filePath
        );
        // Content-Type should be the original's (image/jpeg), not webp.
        $this->assertSame('image/jpeg', $response->contentType);
    }

    // --- Atomic write (temp → rename) ---

    public function test_atomic_write_no_temp_file_left(): void
    {
        $handler = $this->handler();
        $key = $this->validKey();

        $handler->handle($key, 'webp', 'image/webp');

        $sourceHash = LocalBackend::sourceHash(self::SOURCE);
        $cacheSubdir = $this->cacheDir . '/' . $sourceHash;
        // No .lock, .tmp, or partial files left behind — only the final
        // cache file + the hardening files (index.html, .htaccess).
        $allFiles = glob($cacheSubdir . '/{*,.*}', GLOB_BRACE);
        $allFiles = array_filter($allFiles, fn($f) => basename($f) !== '.' && basename($f) !== '..');
        $basenames = array_map('basename', $allFiles);
        $this->assertContains($key . '.webp', $basenames);
        $this->assertNotContains($key . '.webp.lock', $basenames, 'Lock file must be cleaned up');
        foreach ($basenames as $name) {
            $this->assertStringNotContainsString('.tmp.', $name, 'Temp file must not remain');
        }
    }

    public function test_cache_dir_has_index_html_and_htaccess(): void
    {
        $handler = $this->handler();
        $key = $this->validKey();

        $handler->handle($key, 'webp', 'image/webp');

        $sourceHash = LocalBackend::sourceHash(self::SOURCE);
        $cacheSubdir = $this->cacheDir . '/' . $sourceHash;
        $this->assertFileExists($cacheSubdir . '/index.html');
        $this->assertFileExists($cacheSubdir . '/.htaccess');
        $htaccess = file_get_contents($cacheSubdir . '/.htaccess');
        $this->assertStringContainsString('php_flag engine off', $htaccess);
    }

    // --- Cache hit serves directly ---

    public function test_cache_hit_serves_existing_file(): void
    {
        $handler = $this->handler();
        $key = $this->validKey();
        $sourceHash = LocalBackend::sourceHash(self::SOURCE);

        // Pre-populate the cache file.
        $cacheSubdir = $this->cacheDir . '/' . $sourceHash;
        mkdir($cacheSubdir, 0755, true);
        file_put_contents($cacheSubdir . '/' . $key . '.webp', 'cached-webp-bytes');

        $response = $handler->handle($key, 'webp', 'image/webp');

        $this->assertSame(200, $response->status);
        $this->assertSame('image/webp', $response->contentType);
        // Body should be the cached bytes (no re-transform).
        $this->assertSame('cached-webp-bytes', $response->body);
    }

    // --- Non-webp Accept → serve original ---

    public function test_non_webp_accept_serves_original(): void
    {
        $handler = $this->handler();
        $key = $this->validKey();

        $response = $handler->handle($key, 'webp', 'text/html');

        // Client doesn't accept webp → fail-safe: serve original.
        $this->assertSame(200, $response->status);
        $this->assertNotNull($response->filePath);
        $this->assertSame('image/jpeg', $response->contentType);
    }

    // --- FIX #2: format allowlist (transcode/disk-fill DoS guard) ---
    //
    // $format comes from the request basename and is NOT covered by the
    // signature. Without an allowlist an attacker can request <key>.foo,
    // <key>.php, etc. and either fill the disk with one file per extension
    // or attempt to write executable extensions. The allowlist bounds the
    // cache to one entry per signed key (only <key>.webp is ever written).

    public function test_arbitrary_format_extension_rejected_with_400(): void
    {
        $handler = $this->handler();
        $key = $this->validKey();

        $response = $handler->handle($key, 'foo', 'image/webp');

        $this->assertSame(400, $response->status);
        $this->assertNull($response->body);
        $this->assertNull($response->filePath);
        // No cache file written under any extension.
        $sourceHash = LocalBackend::sourceHash(self::SOURCE);
        $cacheSubdir = $this->cacheDir . '/' . $sourceHash;
        if (is_dir($cacheSubdir)) {
            $this->assertSame([], glob($cacheSubdir . '/*'), 'No file must be written for a disallowed format.');
        }
    }

    public function test_php_extension_rejected_with_400_no_file_written(): void
    {
        $handler = $this->handler();
        $key = $this->validKey();

        $response = $handler->handle($key, 'php', 'image/webp');

        $this->assertSame(400, $response->status);
        $this->assertNull($response->body);
        $sourceHash = LocalBackend::sourceHash(self::SOURCE);
        $cacheSubdir = $this->cacheDir . '/' . $sourceHash;
        if (is_dir($cacheSubdir)) {
            $files = glob($cacheSubdir . '/*');
            $this->assertNotContains($cacheSubdir . '/' . $key . '.php', $files, 'No .php file must be written.');
        }
    }

    public function test_allowed_webp_format_still_transforms_and_caches(): void
    {
        $handler = $this->handler();
        $key = $this->validKey();

        $response = $handler->handle($key, 'webp', 'image/webp');

        $this->assertSame(200, $response->status);
        $this->assertNotNull($response->body);
        $sourceHash = LocalBackend::sourceHash(self::SOURCE);
        $this->assertFileExists($this->cacheDir . '/' . $sourceHash . '/' . $key . '.webp');
    }

    // --- FIX #32: serveOriginal must NOT send immutable on the MUTABLE
    // original ---
    //
    // The fail-safe passthrough streamed the ORIGINAL with
    // `Cache-Control: public, max-age=31536000, immutable` — but the
    // original can change (re-upload) at the same URL, so a CDN cached
    // a stale image forever. The original passthrough must use a SHORT
    // cache and NOT immutable. The signed cache-file path keeps
    // immutable (its key is content-stable).

    public function test_serve_original_uses_short_cache_no_immutable(): void
    {
        // Non-webp Accept → serveOriginal path.
        $handler = $this->handler();
        $key = $this->validKey();

        $response = $handler->handle($key, 'webp', 'text/html');

        $this->assertSame(200, $response->status);
        $this->assertNotNull($response->filePath);
        $this->assertArrayHasKey('Cache-Control', $response->headers);
        $cc = $response->headers['Cache-Control'];
        $this->assertStringNotContainsString('immutable', $cc, 'serveOriginal must NOT mark the mutable original as immutable.');
        // Short max-age (<= 3600). The original can be re-uploaded at
        // the same URL — a long CDN cache would serve stale images.
        $this->assertMatchesRegularExpression('/max-age=(\d+)/', $cc);
        preg_match('/max-age=(\d+)/', $cc, $m);
        $this->assertLessThanOrEqual(3600, (int) $m[1], 'serveOriginal max-age must be short (<= 3600).');
    }

    public function test_transformer_null_serve_original_uses_short_cache_no_immutable(): void
    {
        // Transformer null → fail-safe serveOriginal path.
        $handler = $this->handler(new NullTransformer());
        $key = $this->validKey();

        $response = $handler->handle($key, 'webp', 'image/webp');

        $this->assertSame(200, $response->status);
        $this->assertNotNull($response->filePath);
        $cc = $response->headers['Cache-Control'];
        $this->assertStringNotContainsString('immutable', $cc);
        preg_match('/max-age=(\d+)/', $cc, $m);
        $this->assertLessThanOrEqual(3600, (int) $m[1]);
    }

    public function test_webp_cache_path_keeps_immutable(): void
    {
        // Normal webp-serve path → immutable (the cache key is
        // content-stable, so a long CDN cache is safe).
        $handler = $this->handler();
        $key = $this->validKey();

        $response = $handler->handle($key, 'webp', 'image/webp');

        $this->assertSame(200, $response->status);
        $this->assertNotNull($response->body);
        $this->assertSame('public, max-age=31536000, immutable', $response->headers['Cache-Control']);
    }

    // --- #42: Option A — non-webp client gets the ORIGINAL, no .webp
    // cache file written ---
    //
    // The <img src> in the HTML is a `.webp` URL served to ALL clients.
    // A non-webp client (crawler, og:image bot, RSS, old browser)
    // requesting that URL must get the ORIGINAL image (original
    // content-type, short non-immutable cache, Vary: Accept) and MUST
    // NOT produce a `.webp` cache file (pure passthrough — no transform
    // for non-webp). The Accept gate in handle() returns serveOriginal
    // BEFORE the cache-hit/miss/transform path, so no cache file is
    // written. Reverting the gate (step 3) makes this test RED: a
    // non-webp client would fall through to the transform+cache path,
    // write a `.webp` file, and return `image/webp`.

    public function test_non_webp_accept_serves_original_no_cache_file_vary(): void
    {
        $handler = $this->handler();
        $key = $this->validKey();

        $response = $handler->handle($key, 'webp', 'text/html,application/xhtml+xml');

        // Original image, not webp.
        $this->assertSame(200, $response->status);
        $this->assertSame('image/jpeg', $response->contentType);
        $this->assertNotNull($response->filePath);
        // Short, non-immutable cache (the original is mutable).
        $cc = $response->headers['Cache-Control'];
        $this->assertStringNotContainsString('immutable', $cc);
        preg_match('/max-age=(\d+)/', $cc, $m);
        $this->assertLessThanOrEqual(3600, (int) $m[1]);
        // Vary: Accept so caches keep webp + original variants apart.
        $this->assertArrayHasKey('Vary', $response->headers);
        $this->assertSame('Accept', $response->headers['Vary']);
        // NO .webp cache file written for the non-webp branch.
        $sourceHash = LocalBackend::sourceHash(self::SOURCE);
        $cacheSubdir = $this->cacheDir . '/' . $sourceHash;
        if (is_dir($cacheSubdir)) {
            $files = glob($cacheSubdir . '/*') ?: [];
            $this->assertNotContains(
                $cacheSubdir . '/' . $key . '.webp',
                $files,
                'Non-webp branch must NOT write a .webp cache file (pure passthrough).',
            );
        }
    }

    public function test_webp_accept_still_transforms_and_caches(): void
    {
        // Existing behavior preserved: webp-capable client gets
        // transformed + cached WebP exactly as today.
        $handler = $this->handler();
        $key = $this->validKey();

        $response = $handler->handle($key, 'webp', 'image/webp,*/*;q=0.8');

        $this->assertSame(200, $response->status);
        $this->assertSame('image/webp', $response->contentType);
        $this->assertSame('webp-bytes-from-stub', $response->body);
        $sourceHash = LocalBackend::sourceHash(self::SOURCE);
        $this->assertFileExists($this->cacheDir . '/' . $sourceHash . '/' . $key . '.webp');
    }

    // --- #42 (c): security checks (key-verify, format allowlist,
    // path-guard) run BEFORE the Accept gate, so they reject for BOTH
    // Accept variants. A non-webp client must not bypass security. ---

    public function test_tampered_key_rejected_with_400_non_webp_accept(): void
    {
        $handler = $this->handler();
        $key = $this->validKey();
        $parts = explode('.', $key);
        $tampered = $parts[0] . '.' . ($parts[1] === 'A' ? 'B' : 'A');

        $response = $handler->handle($tampered, 'webp', 'text/html');

        $this->assertSame(400, $response->status);
        $this->assertNull($response->body);
        $this->assertNull($response->filePath);
    }

    public function test_malformed_key_rejected_with_400_non_webp_accept(): void
    {
        $handler = $this->handler();

        $response = $handler->handle('garbage', 'webp', 'text/html');

        $this->assertSame(400, $response->status);
    }

    public function test_traversal_source_rejected_non_webp_accept(): void
    {
        $reflection = new \ReflectionMethod($this->backend, 'buildKey');
        $reflection->setAccessible(true);

        $req = new TransformRequest(
            sourceUrl: 'https://example.com/wp-content/uploads/../../etc/passwd',
            width: 800, height: 0, resize: 'fit', format: 'webp', quality: 80,
            context: 'content', dpr: 0, blur: 0, watermark: null,
            formatQuality: [], sourceMode: 'http',
        );
        $key = $reflection->invoke($this->backend, $req, 'webp');

        $handler = $this->handler();
        $response = $handler->handle($key, 'webp', 'text/html');

        $this->assertNotSame(200, $response->status);
        $this->assertNull($response->body);
        $this->assertNull($response->filePath);
    }

    public function test_arbitrary_format_rejected_with_400_non_webp_accept(): void
    {
        $handler = $this->handler();
        $key = $this->validKey();

        $response = $handler->handle($key, 'foo', 'text/html');

        $this->assertSame(400, $response->status);
        $this->assertNull($response->body);
        $this->assertNull($response->filePath);
    }

    // --- #43 Phase 3: endpoint-header lock (regression) ---
    //
    // Locks the three cache-header invariants for the ?k= endpoint in
    // one place so a future refactor can't silently drop any of them:
    //   1. Vary: Accept on BOTH webp and original responses (so caches
    //      keep the two variants apart — without Vary, a CDN would
    //      serve the first-fetched variant to all clients).
    //   2. WebP response: Cache-Control: public, max-age=31536000,
    //      immutable (the cache key is content-stable → safe to cache
    //      for a year).
    //   3. Original response: Cache-Control: public, max-age=3600 and
    //      NOT immutable (the original is mutable — re-uploadable at
    //      the same URL — so a long/immutable CDN cache would serve
    //      stale images).

    public function test_endpoint_header_lock_webp_response(): void
    {
        $handler = $this->handler();
        $key = $this->validKey();

        $response = $handler->handle($key, 'webp', 'image/webp');

        $this->assertSame(200, $response->status);
        $this->assertSame('image/webp', $response->contentType);
        // Vary: Accept present.
        $this->assertArrayHasKey('Vary', $response->headers);
        $this->assertSame('Accept', $response->headers['Vary']);
        // Cache-Control: public, max-age=31536000, immutable.
        $this->assertArrayHasKey('Cache-Control', $response->headers);
        $this->assertSame(
            'public, max-age=31536000, immutable',
            $response->headers['Cache-Control'],
            'WebP response must be cached for 1 year with immutable.',
        );
    }

    public function test_endpoint_header_lock_original_response(): void
    {
        $handler = $this->handler();
        $key = $this->validKey();

        // Non-webp Accept → serveOriginal path.
        $response = $handler->handle($key, 'webp', 'text/html,application/xhtml+xml');

        $this->assertSame(200, $response->status);
        $this->assertSame('image/jpeg', $response->contentType);
        // Vary: Accept present (same as webp — caches must vary on Accept).
        $this->assertArrayHasKey('Vary', $response->headers);
        $this->assertSame('Accept', $response->headers['Vary']);
        // Cache-Control: public, max-age=3600, NO immutable.
        $this->assertArrayHasKey('Cache-Control', $response->headers);
        $cc = $response->headers['Cache-Control'];
        $this->assertStringNotContainsString(
            'immutable',
            $cc,
            'Original response must NOT be marked immutable (mutable source).',
        );
        $this->assertMatchesRegularExpression('/max-age=(\d+)/', $cc);
        preg_match('/max-age=(\d+)/', $cc, $m);
        $this->assertLessThanOrEqual(
            3600,
            (int) $m[1],
            'Original response max-age must be <= 3600 (short cache for mutable source).',
        );
    }

    // --- #47: AVIF negotiation matrix (fake transformer, no real encoder) ---
    //
    // The negotiation is the LOAD-BEARING logic. It must be tested with
    // a FAKE transformer whose supportsAvif/supportsWebp are deterministic
    // (no real encoder dependency → no synthetic-green when CI lacks AVIF).

    public function test_negotiate_avif_when_accepts_avif_and_capable(): void
    {
        $handler = $this->handler(new FakeCapableTransformer(true, true, 'avif-bytes'));
        $key = $this->validKey();

        $response = $handler->handle($key, 'auto', 'image/avif,image/webp,*/*;q=0.8');

        $this->assertSame(200, $response->status);
        $this->assertSame('image/avif', $response->contentType);
        $this->assertSame('avif-bytes', $response->body);
    }

    public function test_negotiate_webp_when_accepts_avif_but_no_avif_cap(): void
    {
        $handler = $this->handler(new FakeCapableTransformer(false, true, 'webp-bytes'));
        $key = $this->validKey();

        $response = $handler->handle($key, 'auto', 'image/avif,image/webp,*/*;q=0.8');

        $this->assertSame(200, $response->status);
        $this->assertSame('image/webp', $response->contentType);
        $this->assertSame('webp-bytes', $response->body);
    }

    public function test_negotiate_webp_when_accepts_webp_only(): void
    {
        $handler = $this->handler(new FakeCapableTransformer(true, true, 'webp-bytes'));
        $key = $this->validKey();

        $response = $handler->handle($key, 'auto', 'image/webp,*/*;q=0.8');

        // No image/avif in Accept → must not upgrade to avif.
        $this->assertSame(200, $response->status);
        $this->assertSame('image/webp', $response->contentType);
        $this->assertSame('webp-bytes', $response->body);
    }

    public function test_negotiate_original_when_accepts_neither(): void
    {
        $handler = $this->handler(new FakeCapableTransformer(true, true, 'avif-bytes'));
        $key = $this->validKey();

        $response = $handler->handle($key, 'auto', 'text/html,application/xhtml+xml');

        // No image/avif or image/webp in Accept → serve original.
        $this->assertSame(200, $response->status);
        $this->assertSame('image/jpeg', $response->contentType);
        $this->assertNotNull($response->filePath);
        $this->assertNull($response->body);
    }

    public function test_negotiate_original_when_no_encoder_cap(): void
    {
        $handler = $this->handler(new FakeCapableTransformer(false, false, ''));
        $key = $this->validKey();

        $response = $handler->handle($key, 'auto', 'image/avif,image/webp,*/*;q=0.8');

        // Neither avif nor webp capable → serve original.
        $this->assertSame(200, $response->status);
        $this->assertSame('image/jpeg', $response->contentType);
        $this->assertNotNull($response->filePath);
    }

    public function test_explicit_webp_format_never_upgrades_to_avif(): void
    {
        // Explicit 'webp' format (clean-URL path) must NOT negotiate to
        // avif even when the client accepts avif and the host is capable.
        $handler = $this->handler(new FakeCapableTransformer(true, true, 'webp-bytes'));
        $key = $this->validKey();

        $response = $handler->handle($key, 'webp', 'image/avif,image/webp');

        $this->assertSame(200, $response->status);
        $this->assertSame('image/webp', $response->contentType);
        $this->assertSame('webp-bytes', $response->body);
    }

    public function test_explicit_avif_format_served_when_accepted(): void
    {
        // Explicit 'avif' format (clean-URL .avif path) → serve avif.
        $handler = $this->handler(new FakeCapableTransformer(true, true, 'avif-bytes'));
        $key = $this->validKey();

        $response = $handler->handle($key, 'avif', 'image/avif,image/webp');

        $this->assertSame(200, $response->status);
        $this->assertSame('image/avif', $response->contentType);
        $this->assertSame('avif-bytes', $response->body);
    }

    public function test_explicit_avif_format_serves_original_when_not_accepted(): void
    {
        // Explicit 'avif' but client doesn't accept avif → serve original.
        $handler = $this->handler(new FakeCapableTransformer(true, true, 'avif-bytes'));
        $key = $this->validKey();

        $response = $handler->handle($key, 'avif', 'image/webp,*/*;q=0.8');

        $this->assertSame(200, $response->status);
        $this->assertSame('image/jpeg', $response->contentType);
        $this->assertNotNull($response->filePath);
    }

    // --- #47: fail-safe chain — negotiated avif null → webp → original ---

    public function test_negotiated_avif_null_falls_back_to_webp(): void
    {
        // Transformer returns null for avif but bytes for webp.
        $handler = $this->handler(new class extends ImageTransformer {
            public function supportsAvif(): bool { return true; }
            public function supportsWebp(): bool { return true; }
            public function transform(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
            {
                return $format === 'avif' ? null : 'webp-fallback-bytes';
            }
        });
        $key = $this->validKey();

        $response = $handler->handle($key, 'auto', 'image/avif,image/webp');

        $this->assertSame(200, $response->status);
        $this->assertSame('image/webp', $response->contentType);
        $this->assertSame('webp-fallback-bytes', $response->body);
    }

    public function test_negotiated_avif_null_and_webp_null_serves_original(): void
    {
        // Both avif and webp transform return null → serve original.
        $handler = $this->handler(new NullTransformer());
        $key = $this->validKey();

        $response = $handler->handle($key, 'auto', 'image/avif,image/webp');

        $this->assertSame(200, $response->status);
        $this->assertSame('image/jpeg', $response->contentType);
        $this->assertNotNull($response->filePath);
    }

    // --- #47: avif cache file extension ---

    public function test_avif_cache_file_has_avif_extension(): void
    {
        $handler = $this->handler(new FakeCapableTransformer(true, true, 'avif-bytes'));
        $key = $this->validKey();

        $handler->handle($key, 'auto', 'image/avif,image/webp');

        $sourceHash = LocalBackend::sourceHash(self::SOURCE);
        $this->assertFileExists($this->cacheDir . '/' . $sourceHash . '/' . $key . '.avif');
    }

    // --- #47: avif quality override ---

    public function test_avif_quality_override_used_when_set(): void
    {
        $handler = new MissEndpointHandler(
            backend: $this->backend,
            transformer: new class extends ImageTransformer {
                public int $lastQuality = 0;
                public string $lastFormat = '';
                public function supportsAvif(): bool { return true; }
                public function supportsWebp(): bool { return true; }
                public function transform(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
                {
                    $this->lastQuality = $quality;
                    $this->lastFormat = $format;
                    return $format . '-q' . $quality;
                }
            },
            pathGuard: new PathGuard($this->uploadsBase, $this->uploadsBaseUrl),
            cacheDir: $this->cacheDir,
            uploadsBasedir: $this->uploadsBase,
            avifQualityOverride: 55,
        );
        $key = $this->validKey();

        $response = $handler->handle($key, 'auto', 'image/avif,image/webp');

        $this->assertSame('image/avif', $response->contentType);
        $this->assertSame('avif-q55', $response->body);
    }
}

/** Stub transformer that returns fixed bytes (bypasses ext checks). */
class StubTransformer extends ImageTransformer
{
    public function __construct(private string $bytes) {}

    public function transform(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
    {
        return $this->bytes;
    }
}

/** Stub transformer that always returns null (fail-safe path). */
class NullTransformer extends ImageTransformer
{
    public function transform(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
    {
        return null;
    }
}

/**
 * Fake transformer with deterministic capability flags + fixed bytes.
 * Used by the negotiation matrix tests (no real encoder dependency).
 */
class FakeCapableTransformer extends ImageTransformer
{
    public function __construct(
        private bool $avifCap,
        private bool $webpCap,
        private string $bytes,
    ) {}

    public function supportsAvif(): bool { return $this->avifCap; }
    public function supportsWebp(): bool { return $this->webpCap; }

    public function transform(string $sourcePath, int $width, int $height, string $fit, int $quality, string $format = 'webp'): ?string
    {
        return $this->bytes;
    }
}
