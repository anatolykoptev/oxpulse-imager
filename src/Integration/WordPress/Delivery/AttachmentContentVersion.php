<?php
/**
 * Attachment content-version resolver + stamper.
 *
 * The version of an attachment is a short base36 stamp written to the
 * `_oxpulse_imager_version` post meta by the `wp_update_attachment_metadata`
 * filter. That filter is the one hook every file-changing flow passes
 * through: upload, image-editor save, regenerate-thumbnails and ASE's
 * "media replacement" (which copies the new file over the old path, then
 * calls wp_generate_attachment_metadata() + wp_update_attachment_metadata()
 * on the OLD attachment ID — it never touches post_modified).
 *
 * Attachments never stamped (uploaded before this version of the plugin)
 * fall back to a checksum of the stored file metadata (filesize|width|
 * height), so every library image carries a version from the first request
 * and the hot path never stats a file.
 *
 * URL -> attachment resolution starts from core's attachment_url_to_postid()
 * (an EXACT `_wp_attached_file` match) and adds one metadata-verified
 * lookup for `-WxH` size variants and pre-`-scaled` originals; see
 * attachmentId(). Memoised per request and cached in the object cache
 * (hits and misses). URLs that are not attachments get no version.
 *
 * @package OXPulse\Imager\Integration\WordPress\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Delivery;

use OXPulse\Imager\Application\Delivery\ContentVersionResolver;

final class AttachmentContentVersion implements ContentVersionResolver
{
    public const META_KEY = '_oxpulse_imager_version';

    private const CACHE_GROUP = 'oxpulse_imager';

    private const MISS_TTL = 3600;

    /** @var array<string,string> */
    private array $memo = [];

    public function versionFor(string $sourceUrl): string
    {
        if (!function_exists('attachment_url_to_postid')) {
            return '';
        }

        // The version must be a function of the path only: drop any query
        // (ASE appends ?t=<time> to recently replaced media) or fragment.
        $url = (string) preg_replace('/[?#].*$/', '', $sourceUrl);
        if ($url === '') {
            return '';
        }

        if (isset($this->memo[$url])) {
            return $this->memo[$url];
        }

        return $this->memo[$url] = $this->resolve($url);
    }

    /**
     * Stamp an attachment as changed. Wired to wp_update_attachment_metadata.
     *
     * @param mixed $data Attachment metadata (returned untouched).
     * @return mixed
     */
    public static function stamp(mixed $data, int $attachmentId): mixed
    {
        if ($attachmentId > 0) {
            update_post_meta($attachmentId, self::META_KEY, self::newStamp());
        }
        return $data;
    }

    /** Milliseconds since epoch in base36 — 8 chars, distinct per update. */
    public static function newStamp(): string
    {
        return base_convert((string) (int) (microtime(true) * 1000), 10, 36);
    }

    private function resolve(string $url): string
    {
        $id = $this->attachmentId($url);
        if ($id <= 0) {
            return '';
        }

        $stamp = get_post_meta($id, self::META_KEY, true);
        if (is_string($stamp) && preg_match('/^[a-z0-9]{1,16}$/', $stamp) === 1) {
            return $stamp;
        }

        return $this->fromMetadata($id);
    }

    /**
     * URL -> attachment id, object-cached (hits and misses).
     *
     * Core's attachment_url_to_postid() is an EXACT `_wp_attached_file`
     * match: it knows nothing of `-WxH` size variants or `-scaled`
     * originals, and those are most of what a page links to. So on an
     * exact miss ONE more lookup is made, chosen by the URL's shape, and
     * accepted only when the candidate attachment's own metadata lists the
     * requested file (so a legitimately named `photo-100x100.jpg` never
     * resolves to an unrelated `photo.jpg`):
     * - sized `name-WxH.ext`  -> look up `name.ext`; metadata `sizes[].file`
     *   must contain `name-WxH.ext`.
     * - plain `name.ext`      -> look up `name-scaled.ext`; metadata
     *   `original_image` must be `name.ext` (the pre-scaling original).
     * (A sized URL of a scaled image is `name-scaled-WxH.ext`, which strips
     * straight to the exact `name-scaled.ext`.)
     */
    private function attachmentId(string $url): int
    {
        $cacheKey = 'id:' . md5($url);
        $cached = function_exists('wp_cache_get') ? wp_cache_get($cacheKey, self::CACHE_GROUP) : false;
        if (is_numeric($cached)) {
            return (int) $cached;
        }

        $id = (int) attachment_url_to_postid($url);
        if ($id <= 0) {
            $id = $this->resolveVariant($url);
        }

        // Misses are cached too (id 0, short TTL): each one is a
        // meta_value scan over every _wp_attached_file row, repeated per
        // distinct non-attachment URL per uncached request otherwise.
        // A new upload at a missed path stays unversioned (today's URL)
        // for at most the TTL.
        if (function_exists('wp_cache_set')) {
            wp_cache_set($cacheKey, $id, self::CACHE_GROUP, $id > 0 ? 3600 : self::MISS_TTL);
        }
        return $id;
    }

    private function resolveVariant(string $url): int
    {
        $slash = strrpos($url, '/');
        if ($slash === false) {
            return 0;
        }
        $dir = substr($url, 0, $slash + 1);
        $file = substr($url, $slash + 1);

        if (preg_match('/^(.+)-\d+x\d+(\.[A-Za-z0-9]+)$/', $file, $m) === 1) {
            $id = (int) attachment_url_to_postid($dir . $m[1] . $m[2]);
            return $id > 0 && $this->metaListsSize($id, $file) ? $id : 0;
        }

        if (preg_match('/^(.+?)(\.[A-Za-z0-9]+)$/', $file, $m) === 1 && !str_ends_with($m[1], '-scaled')) {
            $id = (int) attachment_url_to_postid($dir . $m[1] . '-scaled' . $m[2]);
            return $id > 0 && $this->metaOriginalIs($id, $file) ? $id : 0;
        }

        return 0;
    }

    private function metaListsSize(int $id, string $file): bool
    {
        $meta = wp_get_attachment_metadata($id);
        foreach (is_array($meta) ? ($meta['sizes'] ?? []) : [] as $size) {
            if (is_array($size) && ($size['file'] ?? null) === $file) {
                return true;
            }
        }
        return false;
    }

    private function metaOriginalIs(int $id, string $file): bool
    {
        $meta = wp_get_attachment_metadata($id);
        return is_array($meta) && isset($meta['original_image']) && basename((string) $meta['original_image']) === $file;
    }

    private function fromMetadata(int $id): string
    {
        $meta = wp_get_attachment_metadata($id);
        if (!is_array($meta)) {
            return '';
        }
        $size = (int) ($meta['filesize'] ?? 0);
        $width = (int) ($meta['width'] ?? 0);
        $height = (int) ($meta['height'] ?? 0);
        if ($size === 0 && $width === 0 && $height === 0) {
            return '';
        }

        return base_convert(sprintf('%u', crc32($size . '|' . $width . '|' . $height)), 10, 36);
    }
}
