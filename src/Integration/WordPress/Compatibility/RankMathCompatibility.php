<?php
/**
 * RankMath OpenGraph compatibility layer.
 *
 * Restores direct (non-imgproxy) attachment URLs in OpenGraph/Twitter
 * image meta tags. Without this, RankMath silently drops og:image for
 * every article whose featured image goes through imgproxy rewriting —
 * imgproxy URLs are base64-encoded paths with no file extension, and
 * RankMath's wp_check_filetype() validation rejects extensionless URLs.
 *
 * Two resolution paths:
 *  1. Preferred: when the attachment ID is available, call
 *     wp_get_attachment_url() — which does NOT trigger image_downsize,
 *     so it returns the direct .webp/.jpg URL.
 *  2. Fallback: decode the imgproxy URL's base64url source segment,
 *     extract the local:// path, and reconstruct the direct URL.
 *
 * On any decode failure or suspicious path, the URL is cleared (set to
 * '') so RankMath skips the image gracefully instead of emitting a
 * broken og:image that fails social-platform validation.
 *
 * @package OXPulse\Imager\Integration\WordPress\Compatibility
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Compatibility;

final class RankMathCompatibility
{
    /**
     * Register the RankMath OpenGraph + Twitter image filters.
     *
     * The filters are no-ops when RankMath is not active — the filter
     * names simply never fire. Safe to register unconditionally.
     */
    public function register(): void
    {
        // OpenGraph (Facebook, VK, Telegram, LinkedIn).
        add_filter('rank_math/opengraph/facebook/image_array', [$this, 'restoreDirectUrl'], 10, 1);
        // Twitter cards.
        add_filter('rank_math/opengraph/twitter/image_array', [$this, 'restoreDirectUrl'], 10, 1);
    }

    /**
     * Restore the direct attachment URL in a RankMath image array.
     *
     * RankMath passes an array shaped like:
     *   ['url' => '...', 'id' => 123, 'width' => 1200, 'height' => 630, ...]
     *
     * The url may already be a direct URL (when delivery is disabled or
     * the image was not rewritten) — in that case this is a no-op.
     *
     * @param mixed $attachment
     * @return mixed
     */
    public function restoreDirectUrl(mixed $attachment): mixed
    {
        // WordPress filter callbacks receive whatever value the filter
        // carries — `apply_filters` is type-agnostic. RankMath (or
        // another plugin earlier in the chain) can pass a non-array
        // ('', a URL string, or false) to the image_array filter when
        // no image is resolved. Under strict_types=1 the `array` hint
        // throws a TypeError → 500. Pass non-array values through
        // unchanged so the filter chain stays intact.
        if (!is_array($attachment)) {
            return $attachment;
        }

        if (empty($attachment['url'])) {
            return $attachment;
        }

        $url = (string) $attachment['url'];

        // Not an imgproxy URL — nothing to do. Detect by the presence of
        // a long base64url-looking segment after the last /, OR by the
        // local:// marker in the decoded path. The simplest robust check:
        // imgproxy URLs contain a signature segment (hex/base64) followed
        // by /plain/ or /local:// — but the most reliable signal is that
        // the URL does NOT end in a recognizable image extension.
        if ($this->hasImageExtension($url)) {
            return $attachment;
        }

        // Preferred path: attachment ID available → wp_get_attachment_url
        // returns the direct URL (.webp/.jpg) without triggering
        // image_downsize (which would re-rewrite it back to imgproxy).
        if (!empty($attachment['id'])) {
            $direct = wp_get_attachment_url((int) $attachment['id']);
            if (is_string($direct) && $direct !== '') {
                $attachment['url'] = $direct;
                return $attachment;
            }
        }

        // Fallback: decode the imgproxy URL to recover the source path.
        // This handles the case where the attachment ID is missing (e.g.
        // the image was inserted as a raw URL, not via the media library).
        $decoded = $this->decodeImgproxyUrl($url);
        if ($decoded !== null) {
            $attachment['url'] = $decoded;
            return $attachment;
        }

        // Decode failed — clear the URL so RankMath skips the image
        // gracefully. Returning the imgproxy URL would make RankMath call
        // wp_check_filetype() on an extensionless base64 path, fail
        // validation, and silently drop og:image — the exact bug this
        // filter exists to prevent.
        $attachment['url'] = '';
        return $attachment;
    }

    /**
     * Check whether a URL ends with a recognizable image extension.
     * Used to detect already-direct URLs (no rewrite needed).
     */
    private function hasImageExtension(string $url): bool
    {
        return (bool) preg_match('#\.(jpe?g|png|gif|webp|avif|bmp|tiff?)$#i', $url);
    }

    /**
     * Decode an imgproxy URL to recover the direct source URL.
     *
     * imgproxy URL shape: {endpoint}/{signature}/{options}/local://{base64url}
     * or {endpoint}/{signature}/{options}/plain/{source-url}
     *
     * For local:// sources: base64url-decode the segment after local://,
     * verify it's a filesystem path under /wp-content/, and reconstruct
     * the direct URL as home_url(relativePath).
     *
     * For plain/ sources: extract the source URL directly.
     *
     * Returns null on any decode failure or suspicious path.
     */
    private function decodeImgproxyUrl(string $url): ?string
    {
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        // Try plain/ source first (http source mode).
        if (preg_match('#/plain/(.+)$#', $path, $m)) {
            $source = $m[1];
            // Strip the @format suffix if present (e.g. photo.jpg@avif).
            $source = preg_replace('#@(?:avif|webp|jpeg|png|auto)$#', '', $source) ?? $source;
            if ($this->hasImageExtension($source) && $this->looksLikeAllowedUrl($source)) {
                return $source;
            }
            return null;
        }

        // Try local:// source (base64url-encoded filesystem path).
        if (preg_match('#/local://([A-Za-z0-9_-]+)$#', $path, $m)) {
            $encoded = $m[1];
            // Restore base64 padding before decoding.
            $pad = (4 - (strlen($encoded) % 4)) % 4;
            $decoded = base64_decode(
                strtr($encoded . str_repeat('=', $pad), '-_', '+/'),
                true
            );
            if (!is_string($decoded) || $decoded === '') {
                return null;
            }

            // The decoded value is a filesystem path. Reject anything
            // that doesn't look like a path into /wp-content/ — defence
            // in depth against malformed or malicious inputs.
            if (!str_contains($decoded, '/wp-content/')) {
                return null;
            }
            // Reject path traversal segments.
            if (str_contains($decoded, '..')) {
                return null;
            }

            // Extract the part starting at /wp-content/ and build a URL.
            $wpContentPos = strpos($decoded, '/wp-content/');
            if ($wpContentPos === false) {
                return null;
            }
            $relativePath = substr($decoded, $wpContentPos);
            return home_url($relativePath);
        }

        return null;
    }

    /**
     * Heuristic: does the URL look like an allowed source URL?
     * Checks for http(s) scheme + a host. Not a full allowlist check
     * (RankMath doesn't give us the DeliveryConfig here) — just a
     * sanity guard against garbage.
     */
    private function looksLikeAllowedUrl(string $url): bool
    {
        $parsed = wp_parse_url($url);
        return is_array($parsed)
            && isset($parsed['scheme'])
            && in_array(strtolower($parsed['scheme']), ['http', 'https'], true)
            && isset($parsed['host'])
            && $parsed['host'] !== '';
    }
}
