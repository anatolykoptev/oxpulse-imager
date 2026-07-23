<?php
/**
 * ImgproxyHealthCache tests.
 *
 * Verifies the cached imgproxy health probe result behaves as the
 * OPTIMISTIC front-end-safe cache: default 'up' when unset (zero
 * network I/O), and a stored 'down' is read back verbatim.
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
        $GLOBALS['__oxpulse_transients'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_transients']);
    }

    public function test_read_defaults_to_up_when_transient_unset(): void
    {
        $cache = new ImgproxyHealthCache();

        $this->assertSame('up', $cache->read(), 'Unset health cache must default to "up" (optimistic)');
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
        set_transient(ImgproxyHealthCache::TRANSIENT, 'garbage');
        $cache = new ImgproxyHealthCache();

        // A corrupted/garbage cache must NOT be treated as 'down' —
        // optimistic default wins (a stale 'down' would break delivery
        // for no reason; re-probe writes a clean value).
        $this->assertSame('up', $cache->read());
    }
}
