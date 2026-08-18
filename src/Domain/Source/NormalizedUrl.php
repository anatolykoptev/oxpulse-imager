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

        // Reject percent-encoded path-structural characters or a literal
        // backslash in the PATH (#79). normalizePath() resolves only
        // LITERAL '.'/'..'/'/' segments; a router additionally decodes
        // %2e (.), %2f (/), %5c (\) DURING URI normalization, so an
        // encoded dot-segment like /x/%2e%2e/imgproxy or an encoded slash
        // survives our lexical normalization, fails the str_starts_with
        // prefix compare, yet resolves at the origin to the endpoint (a
        // self-loop) or above the allowlisted subtree (a scope escape).
        // A legitimate WordPress uploads filename never contains these
        // encoded forms, so reject rather than guess at the decoded path.
        // Scoped to the path — an encoded slash in the query string
        // (e.g. a CDN ?url=https%3A%2F%2F... param) is legitimate, never
        // enters the prefix compare, and must not reject the whole URL.
        if (preg_match('~%2[ef]|%5c~i', $path) || str_contains($path, '\\')) {
            throw new \InvalidArgumentException('URL path contains an encoded or literal path separator.');
        }

        $path = self::normalizePath($path);

        $query = $parsed['query'] ?? '';

        return new self($scheme, $host, $port, $path, $query);
    }

    /**
     * Canonicalize a URL path (#79).
     *
     * Collapses repeated slashes, resolves `.` and `..` segments
     * (over-popping stops at root, never producing a relative or
     * negative path), and always returns an absolute path. Applied
     * once here so every consumer — the allowlist prefix compare and
     * the proxy-loop guard alike — sees the same canonical path and a
     * `//`, `/./`, or `/../` form cannot evade a str_starts_with +
     * segment-boundary check.
     */
    public static function normalizePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        $isAbsolute = $path[0] === '/';
        // A path ending in '/', '/.' or '/..' resolves to a DIRECTORY at
        // the router, so the canonical form keeps a trailing slash —
        // matching how nginx/Apache normalize, so the two never diverge.
        $lastSegment = substr($path, (int) strrpos($path, '/') + 1);
        $trailingSlash = (strlen($path) > 1 && substr($path, -1) === '/')
            || $lastSegment === '.'
            || $lastSegment === '..';

        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        $normalized = implode('/', $out);
        if ($isAbsolute) {
            $normalized = '/' . $normalized;
        }
        if ($trailingSlash && substr($normalized, -1) !== '/') {
            $normalized .= '/';
        }

        return $normalized === '' ? '/' : $normalized;
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
