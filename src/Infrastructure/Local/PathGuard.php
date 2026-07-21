<?php
/**
 * Path traversal guard for the local miss-endpoint.
 *
 * Maps a source URL to an absolute filesystem path INSIDE the uploads
 * base directory, rejecting directory traversal, null bytes, absolute
 * paths, symlink escapes, and host/scheme mismatches.
 *
 * Defense-in-depth: even though the cache key is HMAC-signed (an
 * attacker cannot craft a key for an arbitrary source), the endpoint
 * still validates the resolved path against the uploads base. This
 * catches a compromised signing key, a misconfigured uploads base, or
 * any path that escapes the intended directory.
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

final class PathGuard
{
    public function __construct(
        private string $uploadsBasedir,
        private string $uploadsBaseurl
    ) {}

    /**
     * Resolve a source URL to an absolute filesystem path inside the
     * uploads base.
     *
     * @param string $sourceUrl The source image URL (from the signed payload).
     * @return string|null Absolute path inside uploads base, or null when:
     *         - the URL is empty/malformed
     *         - the host or scheme does not match the uploads base URL
     *         - the path contains '..', null bytes, or is not under uploads
     *         - the resolved path escapes uploads base (symlink/traversal)
     *         - the file does not exist (realpath returns false)
     */
    public function resolve(string $sourceUrl): ?string
    {
        if ($sourceUrl === '') {
            return null;
        }

        // Reject null bytes anywhere in the URL.
        if (str_contains($sourceUrl, "\0")) {
            return null;
        }

        $parsed = parse_url($sourceUrl);
        if (!is_array($parsed) || empty($parsed['host']) || empty($parsed['scheme'])) {
            return null;
        }

        $baseParsed = parse_url($this->uploadsBaseurl);
        if (!is_array($baseParsed) || empty($baseParsed['host'])) {
            return null;
        }

        // Scheme + host must match the uploads base URL.
        if ($parsed['scheme'] !== ($baseParsed['scheme'] ?? 'https')) {
            return null;
        }
        if (strtolower($parsed['host']) !== strtolower($baseParsed['host'])) {
            return null;
        }
        if (isset($parsed['port']) && isset($baseParsed['port']) && $parsed['port'] !== $baseParsed['port']) {
            return null;
        }

        $basePath = $baseParsed['path'] ?? '/';
        $sourcePath = $parsed['path'] ?? '/';

        // The source path must start with the uploads base path at a
        // path boundary (prevents /wp-content/uploads-evil/...).
        if ($basePath !== '/' && $basePath !== '') {
            if (!str_starts_with($sourcePath, $basePath)) {
                return null;
            }
            // Ensure boundary: next char after basePath is '/' or end.
            if (strlen($sourcePath) > strlen($basePath)) {
                $nextChar = $sourcePath[strlen($basePath)];
                if ($nextChar !== '/') {
                    return null;
                }
            }
        }

        // Extract the relative path under the uploads base.
        $relative = substr($sourcePath, strlen($basePath));
        $relative = ltrim($relative, '/');

        // Lexical guard: reject '..' segments and null bytes.
        if (str_contains($relative, '..')) {
            return null;
        }
        if (str_contains($relative, "\0")) {
            return null;
        }

        // Construct the candidate path.
        $candidate = $this->uploadsBasedir . '/' . $relative;

        // realpath() resolves '..', symlinks, and './/' segments.
        // Returns false when the file does not exist.
        $resolved = realpath($candidate);
        if ($resolved === false) {
            return null;
        }

        // Containment check: resolved path must be inside uploads base.
        $baseResolved = realpath($this->uploadsBasedir);
        if ($baseResolved === false) {
            return null;
        }

        $baseWithSlash = $baseResolved . DIRECTORY_SEPARATOR;
        if ($resolved === $baseResolved) {
            return $resolved;
        }
        if (!str_starts_with($resolved, $baseWithSlash)) {
            return null;
        }

        return $resolved;
    }
}
