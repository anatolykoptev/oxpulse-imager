<?php
/**
 * SocialJpegCapabilityCache tests.
 *
 * Verifies the CONSERVATIVE cached imgproxy social-jpeg capability gate:
 * readOk() returns true ONLY when a definitive 'ok' was written AND the
 * checked_at timestamp is within TTL. Unset / 'no' / garbage / stale →
 * false (→ caller degrades to the always-200 webp direct URL — never a
 * URL that might 403). This is the INVERSION of ImgproxyHealthCache's
 * optimistic default: a never-probed endpoint is NOT trusted to serve
 * the .jpg transcoded form.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Imgproxy\SocialJpegCapabilityCache;
use PHPUnit\Framework\TestCase;

class SocialJpegCapabilityCacheTest extends TestCase
{
    /** Fixed clock for deterministic TTL tests. */
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_transients'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
        unset($GLOBALS['__oxpulse_transients']);
    }

    private function cache(): SocialJpegCapabilityCache
    {
        return new SocialJpegCapabilityCache(fn() => $this->now);
    }

    public function test_read_ok_defaults_to_false_when_option_unset(): void
    {
        $this->assertFalse(
            $this->cache()->readOk(),
            'Unset capability option must default to false (conservative — degrade to webp)',
        );
    }

    public function test_read_ok_returns_true_after_write_ok(): void
    {
        $cache = $this->cache();
        $cache->write('ok');

        $this->assertTrue($cache->readOk());
    }

    public function test_read_ok_returns_false_after_write_no(): void
    {
        $cache = $this->cache();
        $cache->write('no');

        $this->assertFalse($cache->readOk());
    }

    public function test_read_ok_returns_false_when_stale(): void
    {
        $cache = $this->cache();
        $cache->write('ok');

        // Advance past TTL.
        $this->now += SocialJpegCapabilityCache::TTL + 1;

        $this->assertFalse($cache->readOk(), 'A stale ok must NOT be trusted (conservative re-probe needed)');
    }

    public function test_read_ok_true_when_exactly_at_ttl_boundary(): void
    {
        $cache = $this->cache();
        $cache->write('ok');

        $this->now += SocialJpegCapabilityCache::TTL;

        $this->assertTrue($cache->readOk(), 'At exactly TTL the value is still fresh (<= TTL)');
    }

    public function test_read_ok_treats_garbage_cached_value_as_false(): void
    {
        update_option(SocialJpegCapabilityCache::OPTION, 'garbage');
        update_option(SocialJpegCapabilityCache::OPTION_CHECKED_AT, (string) $this->now);

        $this->assertFalse($this->cache()->readOk(), 'A corrupted/garbage option must NOT be trusted');
    }

    public function test_read_ok_false_when_checked_at_missing(): void
    {
        // OPTION='ok' but no checked_at timestamp → stale/invalid → false.
        update_option(SocialJpegCapabilityCache::OPTION, 'ok');

        $this->assertFalse($this->cache()->readOk());
    }

    // ─── persistent option store (not transient) ────────────────────

    public function test_write_persists_to_option_store_not_transient(): void
    {
        $cache = $this->cache();
        $cache->write('ok');

        $this->assertArrayHasKey(
            SocialJpegCapabilityCache::OPTION,
            $GLOBALS['__oxpulse_options'] ?? [],
            'write() must persist to the WP option store (persistent)',
        );
        $this->assertSame('ok', $GLOBALS['__oxpulse_options'][SocialJpegCapabilityCache::OPTION]);
    }

    public function test_write_does_not_use_transient_store(): void
    {
        $cache = $this->cache();
        $cache->write('ok');

        $this->assertArrayNotHasKey(
            SocialJpegCapabilityCache::OPTION,
            $GLOBALS['__oxpulse_transients'] ?? [],
            'write() must NOT use the transient store',
        );
    }

    public function test_write_stamps_checked_at(): void
    {
        $cache = $this->cache();
        $cache->write('ok');

        $this->assertArrayHasKey(SocialJpegCapabilityCache::OPTION_CHECKED_AT, $GLOBALS['__oxpulse_options'] ?? []);
        $this->assertSame((string) $this->now, $GLOBALS['__oxpulse_options'][SocialJpegCapabilityCache::OPTION_CHECKED_AT]);
    }

    public function test_write_rejects_invalid_value(): void
    {
        $cache = $this->cache();
        $cache->write('maybe');

        $this->assertFalse($cache->readOk(), 'An invalid value must NOT be persisted');
        $this->assertArrayNotHasKey(
            SocialJpegCapabilityCache::OPTION,
            $GLOBALS['__oxpulse_options'] ?? [],
            'An invalid value must NOT write to the option store',
        );
    }

    public function test_write_uses_non_autoloaded_option(): void
    {
        $cache = $this->cache();
        $cache->write('ok');

        $this->assertFalse(
            $GLOBALS['__oxpulse_autoload'][SocialJpegCapabilityCache::OPTION] ?? true,
            'write() must store the option as NON-autoloaded (false)',
        );
    }

    public function test_clear_removes_both_options(): void
    {
        $cache = $this->cache();
        $cache->write('ok');
        $cache->clear();

        $this->assertArrayNotHasKey(SocialJpegCapabilityCache::OPTION, $GLOBALS['__oxpulse_options'] ?? []);
        $this->assertArrayNotHasKey(SocialJpegCapabilityCache::OPTION_CHECKED_AT, $GLOBALS['__oxpulse_options'] ?? []);
        $this->assertFalse($cache->readOk());
    }

    public function test_write_no_also_stamps_checked_at(): void
    {
        $cache = $this->cache();
        $cache->write('no');

        $this->assertArrayHasKey(SocialJpegCapabilityCache::OPTION_CHECKED_AT, $GLOBALS['__oxpulse_options'] ?? []);
        $this->assertSame('no', $GLOBALS['__oxpulse_options'][SocialJpegCapabilityCache::OPTION]);
    }

    /**
     * A FUTURE checked_at stamp (backward clock / NTP correction, or an
     * over-PHP_INT_MAX digit string → negative elapsed) must NOT be
     * trusted as fresh. The lower bound requires now >= checked_at so a
     * future stamp → false → conservative degrade to webp.
     */
    public function test_read_ok_false_when_checked_at_is_in_the_future(): void
    {
        $cache = $this->cache();
        $cache->write('ok');

        // Roll the clock BACK so checked_at is now in the future.
        $this->now -= 100;

        $this->assertFalse(
            $cache->readOk(),
            'A future checked_at stamp must NOT be trusted (conservative — degrade to webp)',
        );
    }
}
