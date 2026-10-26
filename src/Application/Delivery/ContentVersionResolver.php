<?php
/**
 * Content-version resolver seam.
 *
 * Maps a source image URL to a short token that changes whenever the file
 * behind that URL is replaced in place. UrlRewriter feeds the token into
 * the signed imgproxy path (`cb:` option) so path-keyed caches (nginx
 * `proxy_cache_key $uri`) miss after a replacement.
 *
 * @package OXPulse\Imager\Application\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Delivery;

interface ContentVersionResolver
{
    /**
     * @param string $sourceUrl Original (unrewritten) image URL.
     * @return string Token matching `[a-z0-9]{1,16}`, or '' when the URL has
     *         no known version (e.g. it is not a media-library attachment).
     */
    public function versionFor(string $sourceUrl): string;
}
