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
 *   3. Checks Accept: image/webp — non-supporting clients get the original.
 *   4. On cache hit, serves the existing file directly.
 *   5. On miss: flock miss-dedupe → transform (WebpTransformer) →
 *      atomic write (temp → rename) → cache-dir hardening (index.html +
 *      .htaccess no-exec) → return response.
 *   6. Fail-safe: transform null / no Accept / any error → serve the
 *      original file bytes with the original content-type.
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

use OXPulse\Imager\Infrastructure\Image\WebpTransformer;

final class MissEndpointHandler
{
    public function __construct(
        private LocalBackend $backend,
        private WebpTransformer $transformer,
        private PathGuard $pathGuard,
        private string $cacheDir,
        private string $uploadsBasedir,
    ) {}

    /**
     * Handle a cache-miss request.
     *
     * @param string $key The cache key (base64url(payload).base64url(hmac)).
     * @param string $format The requested output format extension (webp).
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

        // 3. Accept gate: if the client doesn't accept webp, serve original.
        if (!$this->acceptsWebp($accept)) {
            return $this->serveOriginal($sourcePath);
        }

        // 4. Cache hit?
        $sourceHash = LocalBackend::sourceHash($sourceUrl);
        $cacheSubdir = $this->cacheDir . '/' . $sourceHash;
        $cacheFile = $cacheSubdir . '/' . $key . '.' . $format;

        if (is_file($cacheFile) && is_readable($cacheFile)) {
            $bytes = file_get_contents($cacheFile);
            if ($bytes !== false) {
                return $this->webpResponse($bytes);
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
        $lockFp = @fopen($lockPath, 'cb');
        if ($lockFp === false) {
            // Can't lock → fail-safe: serve original.
            return $this->serveOriginal($sourcePath);
        }

        try {
            // Non-blocking lock: if another process is already generating,
            // we wait briefly, then check if the file appeared; otherwise
            // we generate independently.
            if (flock($lockFp, LOCK_EX)) {
                // Double-check after acquiring lock (another process may
                // have just written the file).
                if (is_file($cacheFile) && is_readable($cacheFile)) {
                    $bytes = file_get_contents($cacheFile);
                    if ($bytes !== false) {
                        return $this->webpResponse($bytes);
                    }
                }

                // Transform.
                $webp = $this->transformer->transform(
                    $sourcePath,
                    $width,
                    $height,
                    $resize,
                    $quality,
                );

                if ($webp === null) {
                    // Fail-safe: serve original.
                    return $this->serveOriginal($sourcePath);
                }

                // Atomic write: temp file → rename.
                $temp = $cacheFile . '.tmp.' . getmypid();
                if (file_put_contents($temp, $webp) === false) {
                    @unlink($temp);
                    return $this->serveOriginal($sourcePath);
                }
                if (!@rename($temp, $cacheFile)) {
                    @unlink($temp);
                    return $this->serveOriginal($sourcePath);
                }

                return $this->webpResponse($webp);
            }

            // Could not acquire lock — fail-safe: serve original.
            return $this->serveOriginal($sourcePath);
        } finally {
            // Release lock + clean up lock file.
            if (is_resource($lockFp)) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
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
            @mkdir($dir, 0755, true);
        }
        $indexHtml = $dir . '/index.html';
        if (!is_file($indexHtml)) {
            @file_put_contents($indexHtml, '');
        }
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "php_flag engine off\nRemoveHandler .php .phtml .phar\n");
        }
    }

    private function acceptsWebp(string $accept): bool
    {
        return str_contains($accept, 'image/webp');
    }

    /**
     * Build a WebP response with cache headers.
     */
    private function webpResponse(string $bytes): MissEndpointResponse
    {
        return new MissEndpointResponse(
            status: 200,
            contentType: 'image/webp',
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
     */
    private function serveOriginal(string $sourcePath): MissEndpointResponse
    {
        $mime = $this->detectMime($sourcePath);

        return new MissEndpointResponse(
            status: 200,
            contentType: $mime,
            headers: [
                'Cache-Control' => 'public, max-age=31536000, immutable',
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
