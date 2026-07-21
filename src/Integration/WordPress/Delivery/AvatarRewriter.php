<?php
/**
 * Avatar rewriter.
 *
 * Hooks into get_avatar to rewrite the <img> tag inside avatar HTML.
 * Gravatar URLs (secure.gravatar.com) and custom avatar URLs from
 * the allowed source list are rewritten to signed imgproxy URLs.
 *
 * Avatars are typically small (96x96 by default), so the rewriter
 * passes the requested size as the target width for imgproxy to
 * generate an appropriately sized variant.
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

use OXPulse\Imager\Application\Delivery\UrlRewriter;

final class AvatarRewriter
{
    private UrlRewriter $rewriter;

    public function __construct(UrlRewriter $rewriter)
    {
        $this->rewriter = $rewriter;
    }

    /**
     * Filter callback for get_avatar.
     *
     * @param string $avatar The avatar HTML markup.
     * @param mixed $idOrEmail The Gravatar user identifier (user ID, email, or comment object).
     * @param int $size The requested avatar size in pixels.
     * @param string $default The default avatar URL or type.
     * @param string $alt The alt text for the avatar image.
     * @return string
     */
    public function rewrite(string $avatar, $idOrEmail, int $size, string $default, string $alt): string
    {
        if ($avatar === '') {
            return $avatar;
        }

        // Extract the src attribute from the <img> tag.
        if (!preg_match('/\bsrc=["\']([^"\']+)["\']/', $avatar, $matches)) {
            return $avatar;
        }

        $originalSrc = $matches[1];
        $result = $this->rewriter->rewrite($originalSrc, $size, $size, 'avatar');

        if (!$result->rewritten) {
            return $avatar;
        }

        // Replace the src attribute with the rewritten URL. We use a
        // targeted replacement to preserve all other attributes (class,
        // alt, width, height, loading, srcset, etc.).
        $rewrittenSrc = $result->url;
        $newAvatar = preg_replace(
            '/\bsrc=["\']' . preg_quote($originalSrc, '/') . '["\']/',
            'src="' . $rewrittenSrc . '"',
            $avatar,
            1
        );

        return $newAvatar ?? $avatar;
    }
}
