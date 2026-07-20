<?php
/**
 * Content <img> tag rewriter.
 *
 * Hooks into wp_content_img_tag (WP 5.5+) to rewrite the src and
 * srcset attributes of <img> tags in post content. Only rewrites
 * images whose source URL is in the configured allowlist.
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

use OXPulse\Imager\Application\Delivery\UrlRewriter;

final class ContentImgTagRewriter
{
    private UrlRewriter $rewriter;

    public function __construct(UrlRewriter $rewriter)
    {
        $this->rewriter = $rewriter;
    }

    /**
     * Filter callback for wp_content_img_tag.
     *
     * @param string $filteredImage The full <img> tag HTML.
     * @param string $context The context (e.g. 'the_content', 'the_excerpt').
     * @param int $attachmentId The attachment ID (0 for external images).
     * @return string
     */
    public function rewrite(string $filteredImage, string $context, int $attachmentId): string
    {
        if ($filteredImage === '') {
            return $filteredImage;
        }

        // Rewrite src attribute.
        $filteredImage = $this->rewriteSrcAttribute($filteredImage);

        // Rewrite srcset attribute.
        $filteredImage = $this->rewriteSrcsetAttribute($filteredImage);

        return $filteredImage;
    }

    private function rewriteSrcAttribute(string $imgTag): string
    {
        if (!preg_match('/\bsrc=["\']([^"\']+)["\']/', $imgTag, $matches)) {
            return $imgTag;
        }

        $originalSrc = $matches[1];
        $result = $this->rewriter->rewrite($originalSrc, 0, 0, 'content');

        if (!$result->rewritten) {
            return $imgTag;
        }

        // Extract width/height hints from the img tag if present, and
        // re-rewrite with dimensions for better imgproxy output.
        $width = $this->extractAttribute($imgTag, 'width');
        $height = $this->extractAttribute($imgTag, 'height');
        if ($width > 0 || $height > 0) {
            $sized = $this->rewriter->rewrite($originalSrc, $width, $height, 'content');
            if ($sized->rewritten) {
                return str_replace($originalSrc, $sized->url, $imgTag);
            }
        }

        return str_replace($originalSrc, $result->url, $imgTag);
    }

    private function rewriteSrcsetAttribute(string $imgTag): string
    {
        if (!preg_match('/\bsrcset=["\']([^"\']+)["\']/', $imgTag, $matches)) {
            return $imgTag;
        }

        $originalSrcset = $matches[1];
        $candidates = explode(', ', $originalSrcset);
        $rewritten = [];

        foreach ($candidates as $candidate) {
            $rewritten[] = $this->rewriteSrcsetCandidate($candidate);
        }

        $newSrcset = implode(', ', $rewritten);
        return str_replace($originalSrcset, $newSrcset, $imgTag);
    }

    private function rewriteSrcsetCandidate(string $candidate): string
    {
        // A srcset candidate is "URL descriptor" where descriptor is
        // like "800w" or "2x". Split on the first space.
        $parts = explode(' ', $candidate, 2);
        $url = trim($parts[0]);
        $descriptor = isset($parts[1]) ? ' ' . $parts[1] : '';

        // Extract width from "Nw" descriptor for imgproxy resize.
        $width = 0;
        if (preg_match('/(\d+)w/', $descriptor, $wMatch)) {
            $width = (int) $wMatch[1];
        }

        $result = $this->rewriter->rewrite($url, $width, 0, 'srcset');

        return $result->url . $descriptor;
    }

    private function extractAttribute(string $imgTag, string $attr): int
    {
        if (preg_match('/\b' . preg_quote($attr, '/') . '=["\'](\d+)["\']/', $imgTag, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }
}
