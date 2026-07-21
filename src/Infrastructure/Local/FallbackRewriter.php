<?php
/**
 * Output-buffer fallback rewriter for local delivery (Phase 6).
 *
 * When Apache mod_rewrite / AllowOverride is unavailable (nginx,
 * shared hosting with rewrite off), the .htaccess miss-routing
 * cannot be trusted. This rewriter scans the HTML output for cache
 * URLs emitted by LocalBackend:
 *
 *     https://site/wp-content/cache/oxpulse/<sourceHash>/<key>.<fmt>
 *
 * and rewrites them to the self-contained endpoint with a query param:
 *
 *     https://site/wp-content/oxpulse-img.php?k=<key>
 *
 * so serving works without any server-side rewrite rules. The key
 * already encodes the format in its signed payload, so the format
 * extension is dropped — the endpoint recovers it from the key.
 *
 * Wired as the delivery mode when the CapabilityTester reports
 * fallbackNeeded() or when LocalBackend is active + Apache rewrite
 * is unavailable.
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

final class FallbackRewriter
{
    public function __construct(
        private string $homeUrl,
        private string $endpointPath,
    ) {}

    /**
     * Rewrite cache URLs in an HTML string to endpoint ?k= URLs.
     *
     * @param string $html The HTML output to rewrite.
     * @return string The rewritten HTML.
     */
    public function rewrite(string $html): string
    {
        $cacheUrlPrefix = rtrim($this->homeUrl, '/') . '/wp-content/cache/oxpulse/';

        // Match cache URLs: <homeUrl>/wp-content/cache/oxpulse/<sourceHash>/<key>.<fmt>
        // sourceHash = [0-9a-f]{16}, key = base64url(payload).base64url(sig)
        //   (the key contains an internal '.' separating payload and sig —
        //   the base64url alphabet [A-Za-z0-9_-] does NOT include '.', so
        //   the capture must span exactly one internal dot), fmt = extension.
        // Capture group 2 = the full key (payload + '.' + sig, without the
        //   format extension). Truncating at the first '.' (the old regex)
        //   dropped the signature half → endpoint verify() 400'd every image.
        $pattern = '#'
            . preg_quote($cacheUrlPrefix, '#')
            . '([0-9a-f]{16})'
            . '/'
            . '([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)'
            . '\.(webp|avif|jpg|jpeg|png|gif)'
            . '#';

        $result = preg_replace_callback($pattern, function (array $m): string {
            $key = $m[2];
            return rtrim($this->homeUrl, '/') . $this->endpointPath . '?k=' . $key;
        }, $html);

        return $result ?? $html;
    }
}
