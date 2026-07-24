<?php
/**
 * Miss-endpoint request handler (Phase 6, Dispatch 2).
 *
 * The security-critical logic behind the self-contained oxpulse-img.php
 * endpoint. Given a cache key + requested format + Accept header, it:
 *
 *   1. Verifies the key's HMAC signature (LocalBackend::verify).
 *   2. Maps the payload's source URL to an absolute file path under the
 *      uploads base via PathGuard (traversal/symlink/null-byte defense).
 *   3. Negotiates the output format (#47): for $format='auto' (the bare
 *      ?k= endpoint path), picks AVIF > WebP > original from the Accept
 *      header + encoder capability. For explicit formats (clean-URL
 *      .webp/.avif path), serves that EXACT format (Apache static path
 *      stays webp). Non-supporting clients get the original.
 *   4. On cache hit, serves the existing file directly.
 *   5. On miss: flock miss-dedupe → transform (ImageTransformer) →
 *      atomic write (temp → rename) → cache-dir hardening (index.html +
 *      .htaccess no-exec) → return response.
 *   6. Fail-safe: transform null / no Accept / any error → serve the
 *      original file bytes with the original content-type. For a
 *      negotiated-avif null, retries webp before serving original
 *      (never a broken image).
 *
 * Returns a MissEndpointResponse; the generated endpoint file does the
 * actual header() + readfile/echo I/O.
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

use OXPulse\Imager\Infrastructure\Image\ImageTransformer;

final class MissEndpointHandler
{
    /**
     * Allowed output format extensions (the format segment of the cache
     * filename is NOT covered by the signature, so it must be
     * allowlisted). #47: avif added. This is the disk-fill / transcode-DoS
     * boundary: it bounds the cache to one entry per signed key per format.
     */
    private const ALLOWED_FORMATS = ['webp', 'avif'];

    /**
     * Bounded flock retry: how many times to retry a non-blocking lock
     * acquisition before giving up (fail-safe: serve original). Keeps
     * FPM workers from parking indefinitely on a held lock.
     */
    private const LOCK_RETRY_MICROSECONDS = 50000; // 50ms per retry
    private const LOCK_RETRY_ATTEMPTS = 10;        // ~500ms total budget

    public function __construct(
        private LocalBackend $backend,
        private ImageTransformer $transformer,
        private PathGuard $pathGuard,
        private string $cacheDir,
        private string $uploadsBasedir,
        private ?int $avifQualityOverride = null,
        // Gate 1 (ProFeatures::AVIF): when false (free tier), AVIF is
        // NOT an eligible output format — auto negotiation resolves to
        // WebP (or original), and a direct .avif request downgrades to
        // WebP or original, never avif, never fatal. Baked into the
        // generated endpoint as OXPULSE_AVIF_ALLOWED from
        // ServiceRegistrar::isPro() at generation time. Default true
        // preserves the ungated behavior for callers that do not pass
        // it (the gate is applied at the generation site).
        private bool $avifAllowed = true,
    ) {}

    /**
     * Handle a cache-miss request.
     *
     * @param string $key The cache key (base64url(payload).base64url(hmac)).
     * @param string $format The requested output format: 'auto' (negotiate),
     *        or an explicit extension ('webp', 'avif') from the clean-URL path.
     * @param string $accept The HTTP Accept header value.
     * @return MissEndpointResponse
     */
    public function handle(string $key, string $format, string $accept): MissEndpointResponse
    {
        // 1. Verify signature.
        $payload = $this->backend->verify($key);
        if ($payload === null) {
            return new MissEndpointResponse(400, '', [], null, null);
        }

        // 1b. Format allowlist: for EXPLICIT formats (clean-URL path),
        // $format comes from the request basename and is NOT covered by
        // the signature. Reject anything outside the allowlist BEFORE any
        // transform or disk write — this bounds the cache to one entry
        // per signed key per format and blocks attacker-named files
        // (<key>.php, <key>.foo). 'auto' bypasses the raw allowlist
        // check (it never reaches disk as a literal extension —
        // negotiate() picks from the allowlist only). See FIX #2.
        $formatLower = strtolower($format);
        if ($format !== 'auto' && !in_array($formatLower, self::ALLOWED_FORMATS, true)) {
            return new MissEndpointResponse(400, '', [], null, null);
        }

        $sourceUrl = $payload['source'];
        $width = $payload['width'];
        $height = $payload['height'];
        $resize = $payload['resize'];
        $quality = $payload['quality'];

        // 2. Path-guard: resolve source URL to a safe filesystem path.
        $sourcePath = $this->pathGuard->resolve($sourceUrl);
        if ($sourcePath === null) {
            // Traversal / missing file / outside uploads → no serve.
            return new MissEndpointResponse(404, '', [], null, null);
        }

        // 3. Format resolution:
        // - 'auto' (bare ?k= endpoint path) → negotiate AVIF > WebP > original.
        // - explicit (clean-URL .webp/.avif path) → serve that EXACT format.
        if ($format === 'auto') {
            $format = $this->negotiate($accept);
            if ($format === 'original') {
                return $this->serveOriginal($sourcePath);
            }
        } else {
            // Gate 1: a direct .avif request under free is NOT eligible
            // for avif. Downgrade to WebP when the client accepts it
            // and the host can encode it, else serve original — never
            // avif, never fatal (no 400). The URL stays .avif but the
            // served bytes are WebP/original; the next Apache hit for
            // the same clean URL re-enters here and downgrades again.
            if ($formatLower === 'avif' && !$this->avifAllowed) {
                if ($this->transformer->supportsWebp() && $this->acceptsFormat($accept, 'webp')) {
                    $format = 'webp';
                } else {
                    return $this->serveOriginal($sourcePath);
                }
            } elseif (!$this->acceptsFormat($accept, $formatLower)) {
                // Explicit format: client must accept the exact format,
                // else serve original (the URL is format-specific — a
                // non-accepting client gets the original, not a different
                // format, so the static file matches the next Apache hit).
                return $this->serveOriginal($sourcePath);
            } else {
                $format = $formatLower;
            }
        }

        // 4. Cache hit?
        $sourceHash = LocalBackend::sourceHash($sourceUrl);
        $cacheSubdir = $this->cacheDir . '/' . $sourceHash;
        $cacheFile = $cacheSubdir . '/' . $key . '.' . $format;

        if (is_file($cacheFile) && is_readable($cacheFile)) {
            $bytes = file_get_contents($cacheFile);
            if ($bytes !== false) {
                return $this->imageResponse($bytes, $format);
            }
        }

        // 5. Cache miss: transform + atomic write.
        return $this->generateCacheFile(
            $sourcePath,
            $sourceHash,
            $key,
            $format,
            $width,
            $height,
            $resize,
            $quality,
            $cacheSubdir,
            $cacheFile,
        );
    }

    /**
     * Negotiate the output format from the Accept header + encoder
     * capability. Order: AVIF > WebP > original. Capability-gated so
     * we never negotiate a format the host can't encode.
     *
     * @return string 'avif', 'webp', or 'original'.
     */
    private function negotiate(string $accept): string
    {
        // Gate 1: AVIF is not an eligible output format under free —
        // skip the avif branch so negotiation resolves to WebP (or
        // original), never avif, even if the client Accepts avif.
        if ($this->avifAllowed
            && str_contains($accept, 'image/avif')
            && $this->transformer->supportsAvif()
        ) {
            return 'avif';
        }
        if (str_contains($accept, 'image/webp') && $this->transformer->supportsWebp()) {
            return 'webp';
        }
        return 'original';
    }

    /**
     * Generate the cache file: flock → transform → atomic write.
     */
    private function generateCacheFile(
        string $sourcePath,
        string $sourceHash,
        string $key,
        string $format,
        int $width,
        int $height,
        string $resize,
        int $quality,
        string $cacheSubdir,
        string $cacheFile,
    ): MissEndpointResponse {
        // Ensure the cache subdir exists + hardened.
        $this->ensureCacheDir($cacheSubdir);

        // flock miss-dedupe: lock a sidecar .lock file so concurrent
        // requests for the same dest don't all transcode.
        $lockPath = $cacheFile . '.lock';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- self-contained miss-endpoint runs without wp-load; WP filesystem wrappers unavailable.
        $lockFp = @fopen($lockPath, 'cb');
        if ($lockFp === false) {
            // Can't lock → fail-safe: serve original.
            return $this->serveOriginal($sourcePath);
        }

        try {
            // Bounded non-blocking lock: try LOCK_EX|LOCK_NB up to
            // LOCK_RETRY_ATTEMPTS times with a brief sleep between
            // attempts. This keeps FPM workers from parking indefinitely
            // on a held lock (the original plain LOCK_EX would block
            // forever if the lock holder died or stalled). Between
            // attempts we re-check whether another process already
            // wrote the cache file — if so, serve it without generating.
            $acquired = false;
            for ($attempt = 0; $attempt < self::LOCK_RETRY_ATTEMPTS; $attempt++) {
                if (flock($lockFp, LOCK_EX | LOCK_NB)) {
                    $acquired = true;
                    break;
                }
                // Another process holds the lock — check whether it
                // already produced the file before we retry.
                if (is_file($cacheFile) && is_readable($cacheFile)) {
                    $bytes = file_get_contents($cacheFile);
                    if ($bytes !== false) {
                        return $this->imageResponse($bytes, $format);
                    }
                }
                usleep(self::LOCK_RETRY_MICROSECONDS);
            }

            if (!$acquired) {
                // Final re-check after the retry budget is exhausted.
                if (is_file($cacheFile) && is_readable($cacheFile)) {
                    $bytes = file_get_contents($cacheFile);
                    if ($bytes !== false) {
                        return $this->imageResponse($bytes, $format);
                    }
                }
                // Could not acquire within the budget — fail-safe.
                return $this->serveOriginal($sourcePath);
            }

            // Double-check after acquiring lock (another process may
            // have just written the file).
            if (is_file($cacheFile) && is_readable($cacheFile)) {
                $bytes = file_get_contents($cacheFile);
                if ($bytes !== false) {
                    return $this->imageResponse($bytes, $format);
                }
            }

            // Transform. Use the avif quality override when encoding avif
            // (the payload's q is the webp/default quality — avif looks
            // good at lower q, so a separate override is baked into the
            // endpoint from the admin formatQuality setting).
            $encodeQuality = $quality;
            if ($format === 'avif' && $this->avifQualityOverride !== null && $this->avifQualityOverride > 0) {
                $encodeQuality = $this->avifQualityOverride;
            }

            $encoded = $this->transformer->transform(
                $sourcePath,
                $width,
                $height,
                $resize,
                $encodeQuality,
                $format,
            );

            if ($encoded === null) {
                // #47 fail-safe chain: if a negotiated-avif encode returns
                // null (rare runtime failure despite supportsAvif), retry
                // as webp (if capable), then serve original. Never a
                // broken image. Explicit-format requests skip the chain
                // (the URL is format-specific — no cross-format retry).
                if ($format === 'avif' && $this->transformer->supportsWebp()) {
                    $webpFallback = $this->transformer->transform(
                        $sourcePath,
                        $width,
                        $height,
                        $resize,
                        $quality,
                        'webp',
                    );
                    if ($webpFallback !== null) {
                        $temp = $cacheFile . '.tmp.' . getmypid();
                        if (file_put_contents($temp, $webpFallback) !== false) {
                            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- self-contained miss-endpoint runs without wp-load; WP filesystem wrappers unavailable.
                            if (@rename($temp, $cacheSubdir . '/' . $key . '.webp')) {
                                return $this->imageResponse($webpFallback, 'webp');
                            }
                            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- self-contained miss-endpoint runs without wp-load; WP filesystem wrappers unavailable.
                            @unlink($temp);
                        }
                    }
                }
                // Fail-safe: serve original.
                return $this->serveOriginal($sourcePath);
            }

            // Atomic write: temp file → rename.
            $temp = $cacheFile . '.tmp.' . getmypid();
            if (file_put_contents($temp, $encoded) === false) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- self-contained miss-endpoint runs without wp-load; WP filesystem wrappers unavailable.
                @unlink($temp);
                return $this->serveOriginal($sourcePath);
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- self-contained miss-endpoint runs without wp-load; WP filesystem wrappers unavailable.
            if (!@rename($temp, $cacheFile)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- self-contained miss-endpoint runs without wp-load; WP filesystem wrappers unavailable.
                @unlink($temp);
                return $this->serveOriginal($sourcePath);
            }

            return $this->imageResponse($encoded, $format);
        } finally {
            // Release lock + clean up lock file.
            if (is_resource($lockFp)) {
                flock($lockFp, LOCK_UN);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- self-contained miss-endpoint runs without wp-load; WP filesystem wrappers unavailable.
                fclose($lockFp);
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- self-contained miss-endpoint runs without wp-load; WP filesystem wrappers unavailable.
            @unlink($lockPath);
        }
    }

    /**
     * Ensure the cache directory exists and is hardened:
     * - 0755 permissions
     * - index.html (prevent directory listing)
     * - .htaccess denying PHP execution (defense-in-depth)
     */
    private function ensureCacheDir(string $dir): void
    {
        if (!is_dir($dir)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- self-contained miss-endpoint runs without wp-load; WP filesystem wrappers unavailable.
            @mkdir($dir, 0755, true);
        }
        $indexHtml = $dir . '/index.html';
        if (!is_file($indexHtml)) {
            @file_put_contents($indexHtml, '');
        }
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            // `php_flag engine off` is a mod_php-only directive. Under
            // Apache + php-fpm (mod_proxy_fcgi / SetHandler — the modern
            // default) with AllowOverride All, a bare php_flag is an
            // unknown directive → 500 on every request served from this
            // dir. Guard it with <IfModule> so it only applies when
            // mod_php is loaded; RemoveHandler/RemoveType are valid
            // regardless of the PHP SAPI. The cache dir only ever holds
            // .webp/.avif (format allowlist) + index.html, so PHP
            // execution here is already impossible — this is
            // defense-in-depth and must not itself break the site.
            @file_put_contents($htaccess, <<<'HTACCESS'
<IfModule mod_php.c>
php_flag engine off
</IfModule>
<IfModule mod_php7.c>
php_flag engine off
</IfModule>
<IfModule mod_php8.c>
php_flag engine off
</IfModule>
RemoveHandler .php .phtml .phar
RemoveType .php .phtml .phar
HTACCESS);
        }
    }

    /**
     * Check if the client accepts a specific image format.
     * Generalized from the former acceptsWebp() — works for webp, avif, etc.
     */
    private function acceptsFormat(string $accept, string $format): bool
    {
        return str_contains($accept, 'image/' . $format);
    }

    /**
     * Build an image response with cache headers (format-aware).
     */
    private function imageResponse(string $bytes, string $format): MissEndpointResponse
    {
        return new MissEndpointResponse(
            status: 200,
            contentType: 'image/' . $format,
            headers: [
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'Vary' => 'Accept',
                'Content-Length' => strlen($bytes),
            ],
            body: $bytes,
        );
    }

    /**
     * Fail-safe: serve the original file with its original content-type.
     *
     * FIX #32: the original is MUTABLE (can be re-uploaded at the same
     * URL), so it must NOT be marked immutable and must use a SHORT
     * cache — otherwise a CDN caches a stale image for a year. The
     * signed cache-file path (imageResponse) keeps immutable because its
     * key is content-stable (a different source produces a different
     * signed key → a different cache file).
     */
    private function serveOriginal(string $sourcePath): MissEndpointResponse
    {
        $mime = $this->detectMime($sourcePath);

        return new MissEndpointResponse(
            status: 200,
            contentType: $mime,
            headers: [
                'Cache-Control' => 'public, max-age=3600',
                'Vary' => 'Accept',
            ],
            body: null,
            filePath: $sourcePath,
        );
    }

    /**
     * Detect the MIME type of a file.
     *
     * Prefers extension-based detection for known image types (reliable
     * for the uploads directory where files have correct extensions),
     * falls back to mime_content_type for unknown extensions.
     */
    private function detectMime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $byExt = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'bmp' => 'image/bmp',
            default => null,
        };
        if ($byExt !== null) {
            return $byExt;
        }
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
        return 'application/octet-stream';
    }
}
