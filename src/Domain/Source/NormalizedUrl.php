<?php
/**
 * Normalized URL value object.
 *
 * Represents a parsed and canonicalized URL with safe components only.
 * Fragments are stripped (never forwarded to imgproxy); user-info
 * credentials and non-HTTP(S) schemes are rejected during construction.
 *
 * @package OXPulse\Imager\Domain\Source
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Domain\Source;

final readonly class NormalizedUrl
{
    public string $scheme;
    public string $host;
    public ?int $port;
    public string $path;
    public string $query;

    private function __construct(string $scheme, string $host, ?int $port, string $path, string $query)
    {
        $this->scheme = $scheme;
        $this->host = $host;
        $this->port = $port;
        $this->path = $path;
        $this->query = $query;
    }

    /**
     * Parse and normalize a URL.
     *
     * @param string $url Raw URL string.
     * @return self
     * @throws \InvalidArgumentException If the URL is malformed, contains
     *         user-info credentials, non-HTTP(S) schemes, or
     *         control characters. Fragments are silently stripped.
     */
    public static function parse(string $url): self
    {
        $url = trim($url);

        if ($url === '') {
            throw new \InvalidArgumentException('URL is empty.');
        }

        // Reject control characters (0x00-0x1F, 0x7F).
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            throw new \InvalidArgumentException('URL contains control characters.');
        }

        $parsed = wp_parse_url($url);

        if ($parsed === false) {
            throw new \InvalidArgumentException('URL is malformed.');
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException('URL scheme must be http or https.');
        }

        // Reject user-info credentials (security: prevents @ bypass).
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw new \InvalidArgumentException('URL must not contain credentials.');
        }

        // Strip fragments — they are client-side only and must never be
        // forwarded to imgproxy (imgproxy would treat #... as part of the
        // source path). Silently drop rather than reject: HTML src attrs
        // can carry fragments and the mu-plugin this replaces strips them.
        // No field is stored — fragment is intentionally discarded.

        $host = strtolower($parsed['host'] ?? '');
        if ($host === '') {
            throw new \InvalidArgumentException('URL host is empty.');
        }

        $port = isset($parsed['port']) ? (int) $parsed['port'] : null;

        $path = $parsed['path'] ?? '';
        if ($path === '') {
            $path = '/';
        }

        $query = $parsed['query'] ?? '';

        return new self($scheme, $host, $port, $path, $query);
    }

    /**
     * Returns the canonical origin prefix: scheme://host[:port]/
     *
     * Used for allowlist prefix comparison with a path boundary.
     */
    public function originPrefix(): string
    {
        $origin = $this->scheme . '://' . $this->host;
        if ($this->port !== null) {
            $origin .= ':' . $this->port;
        }
        $origin .= '/';
        return $origin;
    }

    /**
     * Returns the full canonical URL string.
     */
    public function __toString(): string
    {
        $url = $this->scheme . '://' . $this->host;
        if ($this->port !== null) {
            $url .= ':' . $this->port;
        }
        $url .= $this->path;
        if ($this->query !== '') {
            $url .= '?' . $this->query;
        }
        return $url;
    }
}
