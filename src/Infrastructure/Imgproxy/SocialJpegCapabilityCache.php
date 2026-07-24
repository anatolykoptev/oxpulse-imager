<?php
/**
 * Cached imgproxy social-jpeg capability gate (front-end-safe accessor).
 *
 * A conservative cache accessor mirroring ImgproxyHealthCache's persistent-
 * option pattern, but with an INVERTED default: readOk() returns true ONLY
 * when a definitive 'ok' was written AND the checked_at timestamp is within
 * TTL. Unset / 'no' / garbage / stale → false (→ caller degrades to the
 * always-200 webp direct URL — never a URL that might 403).
 *
 * The #81 optimistic anti-pattern does NOT apply here: ImgproxyHealthCache
 * defaults to 'up' so a never-probed endpoint is NOT marked down (no
 * spurious fallthrough that would break delivery). But for the social-jpeg
 * capability gate, a false positive (trusting an unprobed endpoint to serve
 * the .jpg transcoded form) is WORSE than a false negative (degrading to the
 * always-200 webp direct URL) — a 403 on og:image breaks social sharing.
 * So the default is conservative: unprobed → false → webp fallback.
 *
 * The cache is a PERSISTENT WordPress OPTION (not a transient), NON-autoloaded
 * (false as the autoload arg). The front-end read path does a single
 * get_option pair (zero network I/O). The TTL (~3h) bounds staleness so a
 * flipped imgproxy config is re-validated periodically; a stale 'ok' degrades
 * to false (conservative) until a write-time re-probe refreshes it.
 *
 * @package OXPulse\Imager\Infrastructure\Imgproxy
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Imgproxy;

final class SocialJpegCapabilityCache
{
    /** Option key for the cached social-jpeg capability verdict (persistent, not autoloaded). */
    public const OPTION = 'oxpulse_imgproxy_social_jpeg';

    /** Option key for the checked-at timestamp (persistent, not autoloaded). */
    public const OPTION_CHECKED_AT = 'oxpulse_imgproxy_social_jpeg_checked_at';

    /** TTL in seconds (~3h). A stale 'ok' degrades to false until a re-probe. */
    public const TTL = 10800;

    /** @var callable|null Clock injector for tests: returns int (unix timestamp). */
    private $now;

    public function __construct(?callable $now = null)
    {
        $this->now = $now;
    }

    /**
     * Read the cached capability verdict. CONSERVATIVE: returns true IFF
     * the option holds 'ok' AND the checked_at timestamp is within TTL.
     * Unset / 'no' / garbage / stale → false (→ caller degrades to webp).
     *
     * FRONT-END-SAFE: zero network I/O — reads the options only.
     */
    public function readOk(): bool
    {
        $value = get_option(self::OPTION, null);
        if ($value !== 'ok') {
            return false;
        }

        $checkedAt = get_option(self::OPTION_CHECKED_AT, null);
        if (!is_string($checkedAt) || !ctype_digit($checkedAt)) {
            return false;
        }

        $now = $this->now !== null ? (int) ($this->now)() : time();
        $checkedAtInt = (int) $checkedAt;
        // Lower bound: a FUTURE checked_at (backward clock / NTP correction,
        // or an over-PHP_INT_MAX digit string → negative elapsed) must NOT
        // be trusted as fresh. Require now >= checked_at AS WELL as the
        // upper-bound TTL check. Future stamp → false → degrade to webp.
        return $now >= $checkedAtInt && ($now - $checkedAtInt) <= self::TTL;
    }

    /**
     * Write a definitive capability verdict ('ok' or 'no'). Write-time
     * only — called by SocialJpegCapabilityProbe::run(), never from the
     * front-end render path. Persisted as non-autoloaded options so they
     * never pollute the autoload set. Stamps checked_at so TTL can gate
     * staleness. Invalid arg → no-op (does not overwrite a prior value).
     */
    public function write(string $verdict): void
    {
        if ($verdict !== 'ok' && $verdict !== 'no') {
            return;
        }

        $now = $this->now !== null ? (int) ($this->now)() : time();
        update_option(self::OPTION, $verdict, false);
        update_option(self::OPTION_CHECKED_AT, (string) $now, false);
    }

    /**
     * Clear both options so the next readOk() defaults to false and a
     * later re-probe can write a fresh value.
     */
    public function clear(): void
    {
        delete_option(self::OPTION);
        delete_option(self::OPTION_CHECKED_AT);
    }
}
