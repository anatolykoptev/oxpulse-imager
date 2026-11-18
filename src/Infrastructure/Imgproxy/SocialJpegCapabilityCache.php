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

    /** Option keys for the cache-buster (`cb:`) verdict — same mechanism, own keys (#132). */
    public const CACHEBUSTER_OPTION = 'oxpulse_imgproxy_cachebuster';
    public const CACHEBUSTER_OPTION_CHECKED_AT = 'oxpulse_imgproxy_cachebuster_checked_at';

    /** TTL in seconds (~3h). A stale 'ok' degrades to false until a re-probe. */
    public const TTL = 10800;

    /** @var callable|null Clock injector for tests: returns int (unix timestamp). */
    private $now;

    /**
     * Per-process memo of the readOk() verdict, keyed by option name. The
     * verdict lives in NON-autoloaded options, so a fresh read costs 1-2
     * SELECTs — on the render path readOk() runs once per rewritten URL
     * (5-10+ per page). Static because several instances (one per
     * registrar/probe/rewriter) read the same option: a write through one
     * must reset all of them. Not blog-aware under switch_to_blog() and
     * not cross-process: another request's write is seen on its next read.
     *
     * @var array<string, bool>
     */
    private static array $readOkMemo = [];

    public function __construct(
        ?callable $now = null,
        private string $option = self::OPTION,
        private string $checkedAtOption = self::OPTION_CHECKED_AT,
        private bool $expires = true
    ) {
        $this->now = $now;

        // Reset the memo on ANY write to the verdict options, from any
        // instance. has_action() is the once-guard (and re-heals after a
        // harness resets the hook table while the static memo persists).
        foreach ([$this->option, $this->checkedAtOption] as $opt) {
            foreach (['add_option_', 'update_option_', 'delete_option_'] as $prefix) {
                $hook = $prefix . $opt;
                if (function_exists('add_action') && has_action($hook, [self::class, 'resetMemo']) === false) {
                    add_action($hook, [self::class, 'resetMemo'], 10, 3);
                }
            }
        }
    }

    /**
     * Drop every memoised verdict. Registered on the add/update/delete
     * option hooks (they pass differing args, ignored); also the seam for
     * isolating the static between tests.
     *
     * @param mixed ...$ignored Hook arguments.
     */
    public static function resetMemo(...$ignored): void
    {
        self::$readOkMemo = [];
    }

    /**
     * The verdict store for "does this imgproxy accept the `cb:` option?".
     * Many installs set IMGPROXY_ALLOWED_PROCESSING_OPTIONS without `cb`;
     * an unprobed / failed / stale verdict reads false → URLs stay unversioned.
     */
    public static function forCacheBuster(?callable $now = null): self
    {
        // No age expiry: the last DEFINITIVE verdict stands until a
        // definitive opposite one arrives. Expiring `ok` into false on a
        // quiet site would flip every imgproxy URL (and refill the nginx
        // cache) without any change at the endpoint.
        return new self($now, self::CACHEBUSTER_OPTION, self::CACHEBUSTER_OPTION_CHECKED_AT, false);
    }

    /** Raw stored verdict: 'ok', 'no', or null when never written. */
    public function readVerdict(): ?string
    {
        $value = get_option($this->option, null);
        return $value === 'ok' || $value === 'no' ? $value : null;
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
        if (isset(self::$readOkMemo[$this->option])) {
            return self::$readOkMemo[$this->option];
        }

        $value = get_option($this->option, null);
        if ($value !== 'ok') {
            return self::$readOkMemo[$this->option] = false;
        }
        if (!$this->expires) {
            return self::$readOkMemo[$this->option] = true;
        }

        $checkedAt = get_option($this->checkedAtOption, null);
        if (!is_string($checkedAt) || !ctype_digit($checkedAt)) {
            return self::$readOkMemo[$this->option] = false;
        }

        $now = $this->now !== null ? (int) ($this->now)() : time();
        $checkedAtInt = (int) $checkedAt;
        // Lower bound: a FUTURE checked_at (backward clock / NTP correction,
        // or an over-PHP_INT_MAX digit string → negative elapsed) must NOT
        // be trusted as fresh. Require now >= checked_at AS WELL as the
        // upper-bound TTL check. Future stamp → false → degrade to webp.
        return self::$readOkMemo[$this->option] = $now >= $checkedAtInt && ($now - $checkedAtInt) <= self::TTL;
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
        update_option($this->option, $verdict, false);
        update_option($this->checkedAtOption, (string) $now, false);
        unset(self::$readOkMemo[$this->option]);
    }

    /**
     * Clear both options so the next readOk() defaults to false and a
     * later re-probe can write a fresh value.
     */
    public function clear(): void
    {
        delete_option($this->option);
        delete_option($this->checkedAtOption);
        unset(self::$readOkMemo[$this->option]);
    }
}
