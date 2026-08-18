<?php
/**
 * Baked proxied-URL restorer.
 *
 * Reverses proxied URLs that leaked into stored post_content (#135).
 * A generated URL's LAST path segment is base64url of the origin
 * source ("local:///wp-content/uploads/...") — optionally followed by
 * a format suffix (".jpg" for social images) — so the origin URL is
 * recoverable deterministically, with no external state.
 *
 * Fail-safe contract: anything that does not decode cleanly to a safe
 * local:/// uploads path is left BYTE-IDENTICAL and reported in
 * `skipped`, never guessed at. Only URLs under this site's own
 * endpoint (same host, or relative) are considered.
 *
 * @package OXPulse\Imager\Application\Repair
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Repair;

final class BakedUrlRestorer
{
    private string $endpointPath;
    private ?string $endpointHost;

    public function __construct(
        string $endpoint,
        private string $homeUrl,
    ) {
        $parts = parse_url($endpoint);
        $this->endpointPath = rtrim((string) ($parts['path'] ?? $endpoint), '/');
        $this->endpointHost = isset($parts['host']) ? strtolower($parts['host']) : null;
    }

    /**
     * @return array{content: string, replaced: int, skipped: string[]}
     */
    public function restore(string $content): array
    {
        $replaced = 0;
        $skipped = [];

        $pattern = '~(?:https?://([a-z0-9.\-]+))?' . preg_quote($this->endpointPath, '~') . '/[^\s"\'<>,]+~i';

        $result = preg_replace_callback(
            $pattern,
            function (array $m) use (&$replaced, &$skipped): string {
                $url = $m[0];
                $host = isset($m[1]) && $m[1] !== '' ? strtolower($m[1]) : null;

                // Absolute URL on a foreign host: someone else's endpoint.
                if ($host !== null && $this->endpointHost !== null && $host !== $this->endpointHost) {
                    return $url;
                }
                if ($host !== null && $this->endpointHost === null) {
                    $homeHost = strtolower((string) parse_url($this->homeUrl, PHP_URL_HOST));
                    if ($host !== $homeHost) {
                        return $url;
                    }
                }

                $origin = $this->decodeOrigin($url);
                if ($origin === null) {
                    $skipped[] = $url;
                    return $url;
                }

                $replaced++;
                return $origin;
            },
            $content
        );

        return [
            'content' => $result ?? $content,
            'replaced' => $replaced,
            'skipped' => $skipped,
        ];
    }

    /**
     * Decode the origin URL out of a proxied URL, or null when the
     * last segment is not a clean base64url local:/// uploads path.
     */
    private function decodeOrigin(string $url): ?string
    {
        $segments = explode('/', $url);
        $last = end($segments);
        if ($last === false || $last === '') {
            return null;
        }

        // Try the raw segment first, then with a trailing format
        // suffix (".jpg", ".png", "@avif") stripped.
        $candidates = [$last];
        $stripped = preg_replace('/[.@][A-Za-z0-9]{2,5}$/', '', $last);
        if (is_string($stripped) && $stripped !== $last) {
            $candidates[] = $stripped;
        }

        foreach ($candidates as $candidate) {
            $decoded = $this->b64urlDecode($candidate);
            if ($decoded === null) {
                continue;
            }
            if (!str_starts_with($decoded, 'local:///')) {
                continue;
            }
            $path = substr($decoded, strlen('local:///'));
            // Safety: an uploads-rooted, traversal-free, printable path.
            if (!str_starts_with($path, 'wp-content/')) {
                return null;
            }
            if (str_contains($path, '..') || preg_match('/[\s"\'<>\\\\]/', $path)) {
                return null;
            }
            return rtrim($this->homeUrl, '/') . '/' . $path;
        }

        return null;
    }

    private function b64urlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('~[^A-Za-z0-9_\-=]~', $value)) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            return null;
        }
        // Reject binary garbage that happened to decode.
        if (preg_match('/[^\x20-\x7E]/', $decoded)) {
            return null;
        }
        return $decoded;
    }
}
