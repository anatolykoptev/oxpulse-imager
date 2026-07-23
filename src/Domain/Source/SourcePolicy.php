<?php
/**
 * Source authorization policy.
 *
 * Validates whether a source URL is approved for imgproxy transformation.
 * Uses component-aware prefix comparison against a configured allowlist
 * to prevent SSRF and policy bypass attacks.
 *
 * @package OXPulse\Imager\Domain\Source
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Source;

use OXPulse\Imager\Domain\Config\DeliveryConfig;

final class SourcePolicy
{
    /**
     * Authorize a source URL against the delivery configuration.
     *
     * @param string $sourceUrl Raw source URL.
     * @param DeliveryConfig $config Delivery configuration with allowed sources.
     * @return SourceDecision
     */
    public function authorize(string $sourceUrl, DeliveryConfig $config): SourceDecision
    {
        if (!$config->enabled) {
            return SourceDecision::denied('delivery_disabled');
        }

        if ($config->allowedSources === []) {
            return SourceDecision::denied('no_allowed_sources_configured');
        }

        try {
            $url = NormalizedUrl::parse($sourceUrl);
        } catch (\InvalidArgumentException $e) {
            return SourceDecision::denied('malformed_url');
        }

        // Check proxy loop: the source must not be an already-imgproxy-
        // transformed URL under the endpoint (host + path prefix). A
        // host-only comparison is WRONG for same-host reverse-proxy
        // setups (endpoint https://site/imgproxy): it would flag every
        // site image (https://site/wp-content/uploads/...) as a loop.
        // The endpoint is absolute at authorize time (resolved via
        // resolveEndpoint + home_url). Match at a path-segment boundary
        // so /imgproxy does not match /imgproxy-evil or /imgproxydata.
        if ($this->isProxyLoop($url, $config->endpoint)) {
            return SourceDecision::denied('proxy_loop_detected');
        }

        // Component-aware prefix match against allowed sources.
        foreach ($config->allowedSources as $allowed) {
            if ($this->matchesPrefix($url, $allowed)) {
                // For 'local' source mode, resolve the URL path to a filesystem
                // path and verify it's inside localBasePath. This is the security
                // boundary: a path traversal or symlink escape stops here.
                if ($config->sourceMode === 'local') {
                    $fsPath = $this->resolveLocalPath($url, $config);
                    if ($fsPath === null) {
                        return SourceDecision::denied('local_path_outside_base');
                    }
                    return SourceDecision::authorizedLocal($url, $fsPath);
                }

                return SourceDecision::authorized($url);
            }
        }

        return SourceDecision::denied('source_not_in_allowlist');
    }

    /**
     * Component-aware prefix comparison.
     *
     * Ensures the source URL matches a configured allowlist prefix at
     * a path boundary, preventing host-suffix and user-info bypasses.
     */
    private function matchesPrefix(NormalizedUrl $url, string $allowedPrefix): bool
    {
        try {
            $allowed = NormalizedUrl::parse($allowedPrefix);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        // Scheme must match.
        if ($url->scheme !== $allowed->scheme) {
            return false;
        }

        // Host must match exactly (case-insensitive, already lowered).
        if ($url->host !== $allowed->host) {
            return false;
        }

        // Port must match (null means default for scheme).
        if ($url->port !== $allowed->port) {
            return false;
        }

        // Path prefix match at a path boundary.
        $allowedPath = $allowed->path;
        $sourcePath = $url->path;

        if ($allowedPath === '/' || $allowedPath === '') {
            return true;
        }

        // Source path must start with allowed path.
        if (!str_starts_with($sourcePath, $allowedPath)) {
            return false;
        }

        // If the allowed path does not end with a slash, ensure the next
        // character in the source path is a path boundary character.
        // This prevents /wp-content from authorizing /wp-content-foo.
        // When the allowed path ends with /, any continuation is valid
        // (it is a subdirectory, not a sibling).
        if (!str_ends_with($allowedPath, '/') && strlen($sourcePath) > strlen($allowedPath)) {
            $nextChar = $sourcePath[strlen($allowedPath)];
            if ($nextChar !== '/' && $nextChar !== '?' && $nextChar !== '#') {
                return false;
            }
        }

        return true;
    }

    /**
     * Proxy-loop detection: is the source URL an already-imgproxy-
     * transformed URL under the endpoint?
     *
     * Denies iff the source has the SAME host (and port) as the endpoint
     * AND its path begins with the endpoint's path prefix at a path-
     * segment boundary. A bare host match is insufficient — same-host
     * reverse-proxy setups (endpoint /imgproxy on the site domain) would
     * false-positive on every site image. Reuses the same segment-boundary
     * rule as matchesPrefix(): /imgproxy matches /imgproxy and /imgproxy/...
     * but NOT /imgproxydata or /imgproxy-evil.
     *
     * When the endpoint has no host (a relative path like '/imgproxy'
     * that was not yet resolved, or a malformed endpoint), the loop
     * check is skipped — same as the prior host-empty behavior.
     */
    private function isProxyLoop(NormalizedUrl $url, string $endpoint): bool
    {
        try {
            $endpointUrl = NormalizedUrl::parse($endpoint);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        if ($endpointUrl->host === '') {
            return false;
        }

        if ($url->host !== $endpointUrl->host) {
            return false;
        }

        if ($url->port !== $endpointUrl->port) {
            return false;
        }

        $endpointPath = $endpointUrl->path;
        if ($endpointPath === '' || $endpointPath === '/') {
            // No path prefix → any path on this host is a loop.
            return true;
        }

        $sourcePath = $url->path;

        if (!str_starts_with($sourcePath, $endpointPath)) {
            return false;
        }

        // Segment-boundary check: if the endpoint path does not end with
        // a slash, the next char in the source path must be a boundary
        // (slash, query, fragment-end) so /imgproxy does not match
        // /imgproxydata. Exact match (same length) is a loop.
        if (!str_ends_with($endpointPath, '/') && strlen($sourcePath) > strlen($endpointPath)) {
            $nextChar = $sourcePath[strlen($endpointPath)];
            if ($nextChar !== '/' && $nextChar !== '?' && $nextChar !== '#') {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a URL's path to a filesystem path INSIDE localBasePath and
     * return the path RELATIVE to localBasePath (the form imgproxy's
     * local:// transport expects).
     *
     * imgproxy is configured with IMGPROXY_LOCAL_FILESYSTEM_ROOT=<root>
     * and expects local://<base64url(path-relative-to-root>. The plugin's
     * localBasePath maps 1:1 to that root (the operator configures both
     * to the same directory). Returning an absolute path here would make
     * imgproxy look for <root>/<absolute-path> — a double-prefixed path
     * that 404s.
     *
     * Security: the candidate path is resolved via realpath() to collapse
     * '..' segments and resolve symlinks, then verified to be inside
     * localBasePath. Only the relative portion (the part of the resolved
     * path beyond localBasePath) is returned — the absolute path never
     * leaves this method.
     *
     * rawurldecode is applied to the URL path before joining — HTML src
     * attributes are percent-encoded (e.g. Cyrillic filenames → %D0%9A),
     * but the on-disk filename is the raw UTF-8 bytes. Without decoding,
     * imgproxy would look for a file named "%D0%9A..." and 404.
     *
     * Returns null when:
     * - localBasePath is empty or not a directory
     * - the resolved path escapes localBasePath (path traversal / symlink)
     * - the file does not exist (realpath returns false)
     *
     * @return string|null Path relative to localBasePath (e.g.
     *         "wp-content/uploads/2024/01/photo.jpg"), or null on denial.
     */
    private function resolveLocalPath(NormalizedUrl $url, DeliveryConfig $config): ?string
    {
        if ($config->localBasePath === '' || !is_dir($config->localBasePath)) {
            return null;
        }

        // Decode percent-encoding to recover the raw on-disk filename.
        // rawurldecode (not urldecode) keeps '+' literal — '+' is a valid
        // filename character and must not be decoded to a space.
        $decodedPath = rawurldecode($url->path);

        // Strip the leading slash from the URL path before joining —
        // localBasePath is absolute, the URL path is root-relative.
        $relativePath = ltrim($decodedPath, '/');

        // Construct the candidate filesystem path.
        $candidate = $config->localBasePath . '/' . $relativePath;

        // realpath() resolves '..', symlinks, and './/' segments. Returns
        // false when the file does not exist. We require the file to exist
        // — pre-warming and on-demand delivery both need a real file.
        $resolved = realpath($candidate);
        if ($resolved === false) {
            return null;
        }

        // Security boundary: the resolved path must be inside localBasePath.
        // Compare against realpath(localBasePath) to handle the case where
        // localBasePath itself is a symlink.
        $baseResolved = realpath($config->localBasePath);
        if ($baseResolved === false) {
            return null;
        }

        // Ensure the resolved path is the base itself or a descendant.
        // str_starts_with alone is insufficient: /var/www/wp-content-evil
        // starts with /var/www/wp-content. Add a trailing slash to the base
        // and require the resolved path to start with it (or be exactly the base).
        $baseWithSlash = $baseResolved . '/';
        if ($resolved === $baseResolved) {
            // The base directory itself — unlikely for an image, but allowed.
            // Relative path is empty.
            return '';
        }
        if (!str_starts_with($resolved, $baseWithSlash)) {
            // Path escaped localBasePath (traversal or symlink).
            return null;
        }

        // Return the path RELATIVE to localBasePath — this is what imgproxy's
        // local:// transport expects (joined onto IMGPROXY_LOCAL_FILESYSTEM_ROOT).
        return substr($resolved, strlen($baseWithSlash));
    }
}
