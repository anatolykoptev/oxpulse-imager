<?php
/**
 * Srcset rewriter.
 *
 * Hooks into wp_calculate_image_srcset to rewrite each source URL
 * in the srcset array. Uses the width descriptor from each source
 * as the imgproxy target width.
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

use OXPulse\Imager\Application\Delivery\UrlRewriter;

final class SrcsetRewriter
{
    private UrlRewriter $rewriter;

    public function __construct(UrlRewriter $rewriter)
    {
        $this->rewriter = $rewriter;
    }

    /**
     * Filter callback for wp_calculate_image_srcset.
     *
     * @param array $sources {
     *     @type array {
     *         @type string $url        The URL of the source.
     *         @type string $descriptor The descriptor (e.g. '800w' or '2x').
     *         @type int    $value      The value of the descriptor.
     *     }
     * }
     * @param array $sizeArray Array of width and height values.
     * @param string $src The src URL.
     * @param array $imageMeta The image metadata.
     * @param int $attachmentId The attachment ID.
     * @return array
     */
    public function rewrite(array $sources, array $sizeArray, string $src, array $imageMeta, int $attachmentId): array
    {
        if (empty($sources)) {
            return $sources;
        }

        foreach ($sources as $i => $source) {
            if (!isset($source['url'], $source['value'])) {
                continue;
            }

            $url = (string) $source['url'];
            $width = 0;

            // Use the descriptor value as target width when the
            // descriptor is 'w' (width-based srcset).
            if (isset($source['descriptor']) && $source['descriptor'] === 'w') {
                $width = (int) $source['value'];
            }

            $result = $this->rewriter->rewrite($url, $width, 0, 'srcset');

            $sources[$i]['url'] = $result->url;
        }

        return $sources;
    }
}
