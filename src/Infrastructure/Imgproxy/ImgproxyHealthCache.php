<?php
/**
 * Cached imgproxy health probe result (front-end-safe accessor).
 *
 * A small cache accessor mirroring the CapabilityTester cached-option
 * pattern: the front-end render path reads the cached option ONLY
 * (zero network I/O), and the write-time `recheck()` on
 * ImgproxyBackendProvider writes a definitive 'up'/'down' here.
 *
 * #81: the cache is a PERSISTENT WordPress OPTION (not a transient).
 * The previous transient store (EXPIRATION = 3600) self-expired to
 * the optimistic 'up' after ~1h, so a dead imgproxy was silently
 * re-selected once the transient lapsed — broken URLs returned with
 * nothing to re-detect the outage. The persistent option NEVER
 * self-expires: a definitive 'down' stays 'down' until a later probe
 * (cron or settings-save) writes 'up'. This closes the safety gap
 * independent of WP-cron reliability. The option is NOT autoloaded
 * (false as the autoload arg) — the front-end read path still does
 * a single get_option (zero network I/O).
 *
 * The cache is OPTIMISTIC: an unset option defaults to 'up' so a
 * never-probed imgproxy endpoint is NOT marked down (no spurious
 * fallthrough that would break delivery). Only a definitive cached
 * 'down' (written by a probe that actually got a non-2xx/3xx or a
 * transport error) marks the backend Down.
 *
 * @package OXPulse\Imager\Infrastructure\Imgproxy
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Imgproxy;

final class ImgproxyHealthCache
{
    /** Option key for the cached imgproxy health probe result (persistent, not autoloaded). */
    public const OPTION = 'oxpulse_imgproxy_health';

    /**
     * Read the cached health state. OPTIMISTIC: returns 'up' when the
     * option is unset OR holds a garbage value (a corrupted cache
     * must NOT break delivery). Returns 'down' only when a definitive
     * 'down' was written by a probe.
     *
     * FRONT-END-SAFE: zero network I/O — reads the option only.
     */
    public function read(): string
    {
        $value = get_option(self::OPTION, null);
        if ($value === 'down') {
            return 'down';
        }
        return 'up';
    }

    /**
     * Write a definitive health state ('up' or 'down'). Write-time only
     * — called by ImgproxyBackendProvider::recheck(), never from the
     * front-end render path. Persisted as a non-autoloaded option so
     * it NEVER self-expires (#81).
     */
    public function write(string $state): void
    {
        if ($state !== 'up' && $state !== 'down') {
            return;
        }
        update_option(self::OPTION, $state, false);
    }

    /**
     * Clear the cached state so the next read defaults to 'up' and a
     * later recheck can write a fresh value.
     */
    public function clear(): void
    {
        delete_option(self::OPTION);
    }
}
