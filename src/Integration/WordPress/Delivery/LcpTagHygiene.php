<?php
/**
 * LCP tag-hygiene pass (#133, #48, #49, #50).
 *
 * Themes such as Foxiz emit fetchpriority="high" on EVERY featured image
 * (50 tags / 98 attributes on the piter.now homepage), and a late output
 * buffer adds loading="lazy" after WordPress core already ran, so core's
 * wp_img_tag_add_auto_sizes() never sees the lazy flag. When everything is
 * high priority nothing is, and the real LCP image gets no preference.
 *
 * This opt-in pass (default OFF — lcpHygieneEnabled + the
 * oxpulse_lcp_hygiene_enabled filter) runs once on the full HTML response
 * in its OWN output buffer and:
 *
 *  1. Picks ONE LCP candidate by a deterministic heuristic that mirrors
 *     core's wp_maybe_add_fetchpriority_high_attr(): the first <img> in the
 *     document that has a src, width and height, covers at least
 *     wp_min_priority_img_pixels (50 000) and is not a logo. Filterable via
 *     oxpulse_lcp_candidate / oxpulse_lcp_min_pixels.
 *  2. Keeps exactly one fetchpriority="high" — on the candidate — and strips
 *     "high" from every other image (including duplicate attributes inside
 *     one tag). fetchpriority="low"/"auto" is left alone.
 *  3. Never leaves the candidate lazy: loading="lazy" becomes
 *     loading="eager". It never ADDS lazy to anything (#49: core already
 *     lazy-loads via wp_get_loading_optimization_attributes() and skips the
 *     first images / fetchpriority=high ones; the choice of lazy for theme
 *     tags stays with the site).
 *  4. Adds "auto, " to sizes of lazy images that have srcset, width and a
 *     sizes attribute lacking a leading auto — the same semantics as core's
 *     wp_img_tag_add_auto_sizes() (and its wp_img_tag_add_auto_sizes
 *     opt-out filter) — plus the srcset requirement from #133.
 *  5. Emits <link rel="preload" as="image" imagesrcset imagesizes
 *     fetchpriority="high"> before </head> for the candidate (#50), unless it
 *     sits in a <picture>, a preload for the same image already exists, or
 *     the document has no </head> before the candidate.
 *
 * Buffer ordering: output buffers nest, the first started is the OUTER one
 * and its callback runs LAST. BufferRewriter starts at template_redirect 5;
 * this pass starts at 4 so it sees the final (proxied, data-oxpulse) tags.
 * Running first would make BufferRewriter::rewriteImagePreloads() re-proxy
 * the preload href with different resize options than the <img>, causing a
 * double download.
 *
 * Fail-safe: any failure (regex error, Throwable) returns the original HTML.
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

use OXPulse\Imager\Domain\Config\DeliveryConfig;

final class LcpTagHygiene
{
    /** Skip buffers larger than 2MB — same cap as BufferRewriter. */
    private const MAX_BUFFER = 2 * 1024 * 1024;

    /** Core's wp_min_priority_img_pixels default. */
    private const DEFAULT_MIN_PIXELS = 50000;

    /** template_redirect priority: outer to BufferRewriter (5), see class doc. */
    public const BUFFER_PRIORITY = 4;

    /** Any tag; quoted attribute values may contain '>'. Possessive = linear. */
    private const TAG_PATTERN = '#<(/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>#';

    /** Elements without a closing tag. */
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link',
        'meta', 'param', 'source', 'track', 'wbr',
    ];

    /** Containers whose images are not visible on first paint, by element. */
    private const HIDDEN_ELEMENTS = ['nav', 'dialog'];

    /**
     * Class tokens of containers that are hidden until user interaction.
     * Foxiz keeps a full copy of the card grid inside .mobile-collapse (the
     * off-canvas menu), BEFORE the real content — the first "big" image of
     * the document on piter.now is a hidden menu thumbnail. Filterable via
     * oxpulse_lcp_excluded_classes.
     */
    private const HIDDEN_CLASSES = [
        'mobile-collapse', 'mobile-menu-wrap', 'mega-menu', 'mega-dropdown', 'sub-menu',
        'dropdown', 'dropdown-menu', 'offcanvas', 'off-canvas', 'modal', 'popup', 'popover',
    ];

    /** One attribute, anchored with \G so values are never re-scanned as names. */
    private const ATTR_PATTERN = '#\G(?:\s|/)*+([^\s"\'<>/=]+)(?:\s*+=\s*+(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?#';

    private DeliveryConfig $delivery;

    /** @var \Closure(): bool */
    private \Closure $shouldBuffer;

    /**
     * @param \Closure(): bool $shouldBuffer The shared page-context guard
     *        battery (BufferRewriter::shouldBuffer) — one source of truth.
     */
    public function __construct(DeliveryConfig $delivery, \Closure $shouldBuffer)
    {
        $this->delivery = $delivery;
        $this->shouldBuffer = $shouldBuffer;
    }

    public function register(): void
    {
        $priority = (int) apply_filters('oxpulse_lcp_hygiene_buffer_priority', self::BUFFER_PRIORITY);
        add_action('template_redirect', function (): void {
            if (!$this->isEnabled() || !($this->shouldBuffer)()) {
                return;
            }
            if (!ob_start([$this, 'rewrite'])) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- defensive dev-only warning.
                trigger_error('OXPulse: ob_start failed — LCP tag hygiene disabled', E_USER_WARNING);
            }
        }, $priority);
    }

    /**
     * Output-buffer callback. Returns the original HTML on every skip path.
     */
    public function rewrite(string $html): string
    {
        if ($html === '' || strlen($html) > self::MAX_BUFFER || !str_contains($html, '<img')) {
            return $html;
        }
        // Re-checked at rewrite time so a late filter still takes effect.
        if (!$this->isEnabled()) {
            return $html;
        }
        if (str_contains($html, '<!-- no-oxpulse -->') || !$this->isHtmlResponse($html)) {
            return $html;
        }
        try {
            return $this->apply($html);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * The transformation itself (no gating). Public for tests.
     */
    public function apply(string $html): string
    {
        // Analyse a copy with comments/script/style/noscript/template blanked
        // out (same length, so offsets map 1:1 onto $html): images and tags
        // inside them are neither candidates nor edited.
        $clean = preg_replace_callback(
            '#<!--.*?-->|<(script|style|noscript|template)\b[^>]*>.*?</\1\s*>#is',
            static fn(array $m): string => str_repeat(' ', strlen($m[0])),
            $html
        );
        if (!is_string($clean)) {
            return $html;
        }

        $images = $this->collectImages($clean);
        if ($images === null || $images === []) {
            return $html;
        }

        $candidate = $this->chooseCandidate($images);
        $autoSizes = (bool) apply_filters('wp_img_tag_add_auto_sizes', true);

        for ($i = count($images) - 1; $i >= 0; $i--) {
            $img = $images[$i];
            $new = $this->rewriteTag($img['tag'], $img['attrs'], $i === $candidate, $autoSizes);
            if ($new !== $img['tag']) {
                $html = substr_replace($html, $new, $img['offset'], strlen($img['tag']));
            }
        }

        if ($candidate !== null) {
            $html = $this->insertPreload($html, $images[$candidate]);
        }

        return $html;
    }

    /**
     * One linear pass over every tag, tracking ancestry on a stack so each
     * <img> knows whether it sits in a hidden container or a <picture>.
     * Mis-tracked ancestry (malformed HTML) only degrades candidate choice;
     * it can never corrupt markup.
     *
     * @return list<array{offset:int,tag:string,attrs:array,hidden:bool,picture:bool}>|null
     */
    private function collectImages(string $clean): ?array
    {
        if (preg_match_all(self::TAG_PATTERN, $clean, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return null;
        }
        $hiddenClasses = array_map('strtolower', (array) apply_filters('oxpulse_lcp_excluded_classes', self::HIDDEN_CLASSES));

        /** @var list<array{0:string,1:bool,2:bool}> $stack name, hidden, picture */
        $stack = [];
        $hiddenDepth = 0;
        $pictureDepth = 0;
        $images = [];

        foreach ($tags as $t) {
            $closing = $t[1][0] === '/';
            $name = strtolower($t[2][0]);
            if ($closing) {
                for ($k = count($stack) - 1; $k >= 0; $k--) {
                    if ($stack[$k][0] !== $name) {
                        continue;
                    }
                    while (count($stack) > $k) {
                        $top = array_pop($stack);
                        $hiddenDepth -= $top[1] ? 1 : 0;
                        $pictureDepth -= $top[2] ? 1 : 0;
                    }
                    break;
                }
                continue;
            }

            $attrs = $t[3][0] === '' ? [] : $this->parseAttributes($t[0][0], 1 + strlen($t[2][0]));
            $hidden = $this->isHiddenContainer($name, $attrs, $hiddenClasses);

            if ($name === 'img') {
                $images[] = [
                    'offset' => $t[0][1],
                    'tag' => $t[0][0],
                    'attrs' => $attrs,
                    'hidden' => $hidden || $hiddenDepth > 0,
                    'picture' => $pictureDepth > 0,
                ];
                continue;
            }
            if (in_array($name, self::VOID_ELEMENTS, true) || str_ends_with($t[0][0], '/>')) {
                continue;
            }
            $stack[] = [$name, $hidden, $name === 'picture'];
            $hiddenDepth += $hidden ? 1 : 0;
            $pictureDepth += $name === 'picture' ? 1 : 0;
        }

        return $images;
    }

    /**
     * @param list<array{name:string,value:?string,quote:string,start:int,end:int}> $attrs
     * @param list<string> $hiddenClasses
     */
    private function isHiddenContainer(string $name, array $attrs, array $hiddenClasses): bool
    {
        if (in_array($name, self::HIDDEN_ELEMENTS, true) || $this->attr($attrs, 'hidden') !== null) {
            return true;
        }
        $style = (string) ($this->attr($attrs, 'style')['value'] ?? '');
        if ($style !== '' && preg_match('#display\s*:\s*none|visibility\s*:\s*hidden#i', $style)) {
            return true;
        }
        $class = (string) ($this->attr($attrs, 'class')['value'] ?? '');
        if ($class !== '') {
            foreach (preg_split('/\s+/', strtolower($class)) ?: [] as $token) {
                if (in_array($token, $hiddenClasses, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function isEnabled(): bool
    {
        return (bool) apply_filters('oxpulse_lcp_hygiene_enabled', $this->delivery->lcpHygieneEnabled);
    }

    private function isHtmlResponse(string $buffer): bool
    {
        $headers = function_exists('headers_list') ? headers_list() : [];
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                return stripos($header, 'text/html') !== false;
            }
        }
        return stripos($buffer, '<html') !== false;
    }

    /**
     * @return list<array{name:string,value:?string,quote:string,start:int,end:int}>
     */
    private function parseAttributes(string $tag, int $from = 4): array
    {
        $attrs = [];
        if (preg_match_all(self::ATTR_PATTERN, $tag, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $from) === false) {
            return $attrs;
        }
        foreach ($m as $set) {
            $quote = '';
            $value = null;
            if (isset($set[2]) && $set[2][1] >= 0) {
                $value = $set[2][0];
                $quote = '"';
            } elseif (isset($set[3]) && $set[3][1] >= 0) {
                $value = $set[3][0];
                $quote = "'";
            } elseif (isset($set[4]) && $set[4][1] >= 0) {
                $value = $set[4][0];
            }
            $attrs[] = [
                'name' => strtolower($set[1][0]),
                'value' => $value,
                'quote' => $quote,
                'start' => $set[0][1],
                'end' => $set[0][1] + strlen($set[0][0]),
            ];
        }
        return $attrs;
    }

    /** @param list<array{name:string,value:?string,quote:string,start:int,end:int}> $attrs */
    private function attr(array $attrs, string $name): ?array
    {
        foreach ($attrs as $a) {
            if ($a['name'] === $name) {
                return $a;
            }
        }
        return null;
    }

    private function token(?array $attr): string
    {
        return $attr === null ? '' : strtolower(trim((string) $attr['value'], " \t\f\r\n"));
    }

    /**
     * Default heuristic + filter. Returns an index into $images or null.
     *
     * @param list<array{offset:int,tag:string,attrs:array,hidden:bool,picture:bool}> $images
     */
    private function chooseCandidate(array $images): ?int
    {
        $minPixels = (int) apply_filters(
            'oxpulse_lcp_min_pixels',
            (int) apply_filters('wp_min_priority_img_pixels', self::DEFAULT_MIN_PIXELS)
        );

        $descriptors = [];
        $default = null;
        foreach ($images as $i => $img) {
            $a = $img['attrs'];
            $src = (string) ($this->attr($a, 'src')['value'] ?? '');
            $width = (int) ($this->attr($a, 'width')['value'] ?? 0);
            $height = (int) ($this->attr($a, 'height')['value'] ?? 0);
            $class = (string) ($this->attr($a, 'class')['value'] ?? '');
            $descriptors[$i] = [
                'src' => $src,
                'srcset' => (string) ($this->attr($a, 'srcset')['value'] ?? ''),
                'width' => $width,
                'height' => $height,
                'class' => $class,
                'hidden' => $img['hidden'],
                'in_picture' => $img['picture'],
                'tag' => $img['tag'],
            ];
            if (
                $default === null
                && !$img['hidden']
                && $src !== '' && !str_starts_with(strtolower($src), 'data:')
                && $width > 0 && $height > 0 && $width * $height >= $minPixels
                && stripos($class, 'logo') === false
                && $this->token($this->attr($a, 'fetchpriority')) !== 'low'
            ) {
                $default = $i;
            }
        }

        $chosen = apply_filters('oxpulse_lcp_candidate', $default, $descriptors);
        if ($chosen === null) {
            return null;
        }
        return is_int($chosen) && isset($images[$chosen]) ? $chosen : $default;
    }

    /**
     * @param list<array{name:string,value:?string,quote:string,start:int,end:int}> $attrs
     */
    private function rewriteTag(string $tag, array $attrs, bool $isCandidate, bool $autoSizes): string
    {
        /** @var list<array{0:int,1:int,2:string}> $edits start, end, replacement */
        $edits = [];
        $insert = '';

        $priorities = array_values(array_filter($attrs, static fn(array $a): bool => $a['name'] === 'fetchpriority'));
        $loading = $this->attr($attrs, 'loading');
        $sizes = $this->attr($attrs, 'sizes');

        if ($isCandidate) {
            $alreadyOne = count($priorities) === 1 && $this->token($priorities[0]) === 'high';
            if (!$alreadyOne) {
                foreach ($priorities as $a) {
                    $edits[] = [$a['start'], $a['end'], ''];
                }
                $insert .= ' fetchpriority="high"';
            }
            if ($this->token($loading) === 'lazy') {
                foreach ($attrs as $a) {
                    if ($a['name'] === 'loading') {
                        $edits[] = [$a['start'], $a['end'], ''];
                    }
                }
                $insert .= ' loading="eager"';
            }
            // sizes="auto, …" is only honoured for lazy images; drop it.
            if ($sizes !== null && $this->sizesHasAuto((string) $sizes['value'])) {
                $rest = $this->sizesWithoutAuto((string) $sizes['value']);
                $edits[] = [
                    $sizes['start'],
                    $sizes['end'],
                    $rest === '' ? '' : ' sizes=' . $sizes['quote'] . $rest . $sizes['quote'],
                ];
            }
        } else {
            foreach ($priorities as $a) {
                if ($this->token($a) === 'high') {
                    $edits[] = [$a['start'], $a['end'], ''];
                }
            }
            if (
                $autoSizes
                && $this->token($loading) === 'lazy'
                && $sizes !== null && $sizes['value'] !== null
                && !$this->sizesHasAuto($sizes['value'])
                && trim((string) ($this->attr($attrs, 'width')['value'] ?? '')) !== ''
                && trim((string) ($this->attr($attrs, 'srcset')['value'] ?? '')) !== ''
            ) {
                $q = $sizes['quote'] !== '' ? $sizes['quote'] : '"';
                $edits[] = [$sizes['start'], $sizes['end'], ' sizes=' . $q . 'auto, ' . $sizes['value'] . $q];
            }
        }

        if ($edits === [] && $insert === '') {
            return $tag;
        }
        usort($edits, static fn(array $x, array $y): int => $y[0] <=> $x[0]);
        foreach ($edits as [$start, $end, $replacement]) {
            $tag = substr_replace($tag, $replacement, $start, $end - $start);
        }
        // Insertion point (right after "<img") is before every attribute offset.
        return substr_replace($tag, $insert, 4, 0);
    }

    /** Core's wp_sizes_attribute_includes_valid_auto(): auto is the first entry. */
    private function sizesHasAuto(string $sizes): bool
    {
        [$first] = explode(',', $sizes, 2);
        return strtolower(trim($first, " \t\f\r\n")) === 'auto';
    }

    private function sizesWithoutAuto(string $sizes): string
    {
        $parts = explode(',', $sizes, 2);
        return trim($parts[1] ?? '', " \t\f\r\n");
    }

    /**
     * @param array{offset:int,tag:string,attrs:array,hidden:bool,picture:bool} $img
     */
    private function insertPreload(string $html, array $img): string
    {
        $attrs = $img['attrs'];
        $src = (string) ($this->attr($attrs, 'src')['value'] ?? '');
        $srcset = (string) ($this->attr($attrs, 'srcset')['value'] ?? '');
        $sizes = (string) ($this->attr($attrs, 'sizes')['value'] ?? '');

        if ($src === '' || str_starts_with(strtolower($src), 'data:')) {
            return $html;
        }
        // Inside <picture> the browser may pick a <source> — a preload of the
        // <img> fallback would double-download.
        if ($img['picture']) {
            return $html;
        }
        $headEnd = stripos($html, '</head>');
        if ($headEnd === false) {
            return $html;
        }
        // Edits above only touch the body (after </head>), so $img['offset']
        // is still a valid position for the comparison.
        if ($headEnd > $img['offset']) {
            return $html;
        }
        if ($this->hasImagePreload(substr($html, 0, $headEnd), $src)) {
            return $html;
        }

        $imageSizes = $this->sizesHasAuto($sizes) ? $this->sizesWithoutAuto($sizes) : $sizes;
        $link = '<link rel="preload" as="image" href="' . $this->quote($src) . '"';
        if ($srcset !== '') {
            $link .= ' imagesrcset="' . $this->quote($srcset) . '"';
            if ($imageSizes !== '') {
                $link .= ' imagesizes="' . $this->quote($imageSizes) . '"';
            }
        }
        $link .= ' fetchpriority="high">' . "\n";

        $descriptor = ['src' => $src, 'srcset' => $srcset, 'sizes' => $imageSizes, 'link' => $link];
        if (!apply_filters('oxpulse_lcp_preload_enabled', true, $descriptor)) {
            return $html;
        }

        return substr_replace($html, $link, $headEnd, 0);
    }

    private function hasImagePreload(string $head, string $src): bool
    {
        if (preg_match_all('#<link\b[^>]*>#i', $head, $m) === false) {
            return true; // cannot tell — do not risk a duplicate preload
        }
        foreach ($m[0] as $link) {
            if (
                preg_match('#\brel\s*=\s*["\']?preload#i', $link)
                && preg_match('#\bas\s*=\s*["\']?image#i', $link)
                && str_contains($link, $src)
            ) {
                return true;
            }
        }
        return false;
    }

    /** Raw attribute text is already entity-escaped; only protect the quote. */
    private function quote(string $raw): string
    {
        return str_replace('"', '&quot;', $raw);
    }
}
