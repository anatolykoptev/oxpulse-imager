<?php
/**
 * Output buffer rewriter.
 *
 * Catches <img> tags hardcoded by the theme in PHP templates — bypassing
 * the wp_content_img_tag filter (which only fires for images emitted
 * through wp_get_attachment_image / the_content). On piter.now the Foxiz
 * theme hardcodes <img> tags directly in hero/card/block templates; these
 * never pass through any of the 5 currently-hooked filters.
 *
 * Registered on template_redirect via ob_start, runs once on the full
 * HTML response. Skips admin/AJAX/REST/CRON contexts. The regex is
 * intentionally unbounded (no {1,N} quantifier) — a bounded quantifier
 * caused catastrophic backtracking on malformed <img tags in production
 * (O(n²) on 4KB input, pinned a PHP-FPM worker at 100% CPU for seconds).
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;

final class BufferRewriter
{
    /** Skip buffers larger than 2MB — protects memory on huge pages. */
    private const MAX_BUFFER = 2 * 1024 * 1024;

    /** Supported source extensions (case-insensitive). */
    private const SOURCE_EXTENSIONS = 'jpe?g|png|gif|webp|avif|bmp|tiff?';

    private UrlRewriter $rewriter;
    private DeliveryConfig $delivery;

    public function __construct(UrlRewriter $rewriter, DeliveryConfig $delivery)
    {
        $this->rewriter = $rewriter;
        $this->delivery = $delivery;
    }

    /**
     * Register the output buffer on template_redirect. The buffer is
     * flushed automatically by PHP at request end; the rewrite callback
     * runs once on the full HTML response.
     */
    public function register(): void
    {
        add_action('template_redirect', function (): void {
            if (!ob_start([$this, 'rewrite'])) {
                trigger_error(
                    'OXPulse: ob_start failed — buffer rewriting disabled',
                    E_USER_WARNING
                );
            }
        });
    }

    /**
     * Rewrite <img> tags in the HTML buffer.
     *
     * Called by PHP's output buffer handler. Must return the (possibly
     * rewritten) HTML string. Failures must preserve the input — never
     * return a partial or empty buffer.
     *
     * @param string $html Full HTML response (or chunk).
     * @return string Rewritten HTML, or original on any skip path.
     */
    public function rewrite(string $html): string
    {
        // Fast paths — skip without touching the regex engine.
        if ($html === '' || strlen($html) > self::MAX_BUFFER) {
            return $html;
        }
        if (!str_contains($html, '<img')) {
            return $html;
        }

        return $this->rewriteImgTags($html);
    }

    /**
     * Apply the buffer-rewrite regex to all <img> tags matching the
     * source-extension pattern. Delegates per-match to UrlRewriter,
     * which enforces source policy + signing + fail-safe preservation.
     */
    private function rewriteImgTags(string $html): string
    {
        // Match <img ... src|data-src="...wp-content/...ext" ...>
        //
        // CRITICAL: the URL character class [^"\x27]+ is UNBOUNDED — no
        // {1,N} quantifier. A bounded quantifier like [^"\x27]{1,2000}
        // causes catastrophic backtracking on malformed <img tags that
        // contain an unterminated quote followed by many non-quote chars:
        // the regex engine tries every split of the long run before
        // giving up, O(n²) on a 4KB tag. The unbounded greedy quantifier
        // is linear and safe — it grabs everything until the next quote.
        //
        // \x27 = single quote (avoid ' which can be confused in PHP).
        $pattern = '#(<img[^>]+(?:\bsrc|\bdata-src)=["\x27])([^"\x27]+/wp-content/[^"\x27]+\.(?:'
            . self::SOURCE_EXTENSIONS
            . '))(["\x27][^>]*>)#i';

        return preg_replace_callback($pattern, function (array $m): string {
            $prefix = $m[1];
            $url = $m[2];
            $suffix = $m[3];

            // Extract width/height hints from the surrounding tag (in the
            // prefix + suffix) for better imgproxy resize targeting.
            $tagFragment = $prefix . $url . $suffix;
            $width = $this->extractAttribute($tagFragment, 'width');
            $height = $this->extractAttribute($tagFragment, 'height');

            $result = $this->rewriter->rewrite($url, $width, $height, 'buffer');

            if (!$result->rewritten) {
                // Source not allowed / delivery disabled / signing missing —
                // preserve the original URL.
                return $m[0];
            }

            return $prefix . $result->url . $suffix;
        }, $html);
    }

    /**
     * Extract an integer attribute (width/height) from an <img> tag fragment.
     * Returns 0 when the attribute is absent or non-numeric.
     */
    private function extractAttribute(string $tagFragment, string $attr): int
    {
        if (preg_match('/\b' . preg_quote($attr, '/') . '=["\x27](\d+)["\x27]/', $tagFragment, $m)) {
            return (int) $m[1];
        }
        return 0;
    }
}
