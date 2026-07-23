<?php
/**
 * ImgproxyHealthCache tests.
 *
 * Verifies the cached imgproxy health probe result behaves as the
 * OPTIMISTIC front-end-safe cache: default 'up' when unset (zero
 * network I/O), and a stored 'down' is read back verbatim.
 *
 * #81: the cache is now a PERSISTENT WordPress OPTION (not a
 * transient) so a definitive 'down' NEVER self-expires to the
 * optimistic 'up' while imgproxy is genuinely dead. The option is
 * NOT autoloaded (pass false as the autoload arg) — the front-end
 * read path still does a single get_option (zero network I/O).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use PHPUnit\Framework\TestCase;

class ImgproxyHealthCacheTest extends TestCase
{
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

    public function test_read_defaults_to_up_when_option_unset(): void
    {
        $cache = new ImgproxyHealthCache();

        $this->assertSame('up', $cache->read(), 'Unset health option must default to "up" (optimistic)');
    }

    public function test_read_returns_down_after_write_down(): void
    {
        $cache = new ImgproxyHealthCache();
        $cache->write('down');

        $this->assertSame('down', $cache->read());
    }

    public function test_read_returns_up_after_write_up(): void
    {
        $cache = new ImgproxyHealthCache();
        $cache->write('down');
        $cache->write('up');

        $this->assertSame('up', $cache->read());
    }

    public function test_clear_resets_to_default_up(): void
    {
        $cache = new ImgproxyHealthCache();
        $cache->write('down');
        $cache->clear();

        $this->assertSame('up', $cache->read());
    }

    public function test_read_treats_garbage_cached_value_as_up(): void
    {
        // A corrupted/garbage option must NOT be treated as 'down' —
        // optimistic default wins (a stale 'down' would break delivery
        // for no reason; re-probe writes a clean value).
        update_option(ImgproxyHealthCache::OPTION, 'garbage');
        $cache = new ImgproxyHealthCache();

        $this->assertSame('up', $cache->read());
    }

    // ─── #81: persistent option store (no TTL) ──────────────────────

    public function test_write_persists_to_option_store_not_transient(): void
    {
        // The safety guarantee (#81): a definitive 'down' is stored in
        // a PERSISTENT option that NEVER self-expires. If the value
        // landed in the transient store instead, it would lapse back
        // to optimistic 'up' after EXPIRATION — the exact bug we fixed.
        $cache = new ImgproxyHealthCache();
        $cache->write('down');

        $this->assertArrayHasKey(
            ImgproxyHealthCache::OPTION,
            $GLOBALS['__oxpulse_options'] ?? [],
            'write() must persist to the WP option store (persistent, no TTL)',
        );
        $this->assertSame('down', $GLOBALS['__oxpulse_options'][ImgproxyHealthCache::OPTION]);
    }

    public function test_write_does_not_use_transient_store(): void
    {
        // Falsification: if someone reverts to set_transient, the
        // value lands in __oxpulse_transients and this test REDS.
        $cache = new ImgproxyHealthCache();
        $cache->write('down');

        $this->assertArrayNotHasKey(
            ImgproxyHealthCache::OPTION,
            $GLOBALS['__oxpulse_transients'] ?? [],
            'write() must NOT use the transient store (transients self-expire → safety gap)',
        );
    }

    public function test_write_rejects_invalid_state(): void
    {
        $cache = new ImgproxyHealthCache();
        $cache->write('maybe');

        $this->assertSame('up', $cache->read(), 'An invalid state must NOT be persisted');
        $this->assertArrayNotHasKey(
            ImgproxyHealthCache::OPTION,
            $GLOBALS['__oxpulse_options'] ?? [],
            'An invalid state must NOT write to the option store',
        );
    }

    public function test_clear_removes_option(): void
    {
        $cache = new ImgproxyHealthCache();
        $cache->write('down');
        $cache->clear();

        $this->assertArrayNotHasKey(
            ImgproxyHealthCache::OPTION,
            $GLOBALS['__oxpulse_options'] ?? [],
            'clear() must delete the option so the next read defaults to up',
        );
    }
}
