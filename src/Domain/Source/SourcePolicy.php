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

        // Check proxy loop: source must not point at the imgproxy endpoint.
        $endpointHost = $this->extractHost($config->endpoint);
        if ($endpointHost !== '' && $url->host === $endpointHost) {
            return SourceDecision::denied('proxy_loop_detected');
        }

        // Component-aware prefix match against allowed sources.
        foreach ($config->allowedSources as $allowed) {
            if ($this->matchesPrefix($url, $allowed)) {
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

    private function extractHost(string $url): string
    {
        try {
            $parsed = NormalizedUrl::parse($url);
            return $parsed->host;
        } catch (\InvalidArgumentException $e) {
            return '';
        }
    }
}
