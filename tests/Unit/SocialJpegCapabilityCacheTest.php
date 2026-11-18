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

use OXPulse\Imager\Application\Delivery\ContentVersionResolver;
use OXPulse\Imager\Application\Delivery\DeliveryBackend;
use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Domain\Transform\TransformRequest;
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
        SocialJpegCapabilityCache::resetMemo();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
        unset($GLOBALS['__oxpulse_transients']);
        SocialJpegCapabilityCache::resetMemo();
    }

    private function cache(): SocialJpegCapabilityCache
    {
        return new SocialJpegCapabilityCache(fn() => $this->now);
    }

    /**
     * Several instances read one option per process; a probe write()
     * through B must reset the verdict A already memoised as false.
     */
    public function test_write_through_another_instance_invalidates_memo(): void
    {
        $a = $this->cache();
        $b = $this->cache();
        $this->assertFalse($a->readOk(), 'A memoises false');

        $b->write('ok');

        $this->assertTrue($a->readOk(), "B's write must reset A's memo via the option hooks");
    }

    /**
     * An option change that bypasses write() (another code path, WP-CLI,
     * a settings reset) must still drop the memo: the update/add/delete
     * option hooks are the only thing that can.
     */
    public function test_option_change_outside_write_invalidates_memo_via_hooks(): void
    {
        $a = $this->cache();
        $this->assertFalse($a->readOk(), 'A memoises false');

        update_option(SocialJpegCapabilityCache::OPTION, 'ok', false);
        update_option(SocialJpegCapabilityCache::OPTION_CHECKED_AT, (string) $this->now, false);

        $this->assertTrue($a->readOk(), 'the option hooks must reset the memo');

        delete_option(SocialJpegCapabilityCache::OPTION);
        $this->assertFalse($a->readOk(), 'delete_option hook must reset the memo');
    }

    public function test_clear_through_another_instance_invalidates_memo(): void
    {
        $a = $this->cache();
        $b = $this->cache();
        $b->write('ok');
        $this->assertTrue($a->readOk(), 'A memoises true');

        $b->clear();

        $this->assertFalse($a->readOk(), "B's clear must reset A's memo");
    }

    /**
     * write()/clear() reset their own memo directly too — not only via
     * the option hooks (they may not be registered, e.g. a cleared hook
     * table), so drop the hook table to prove the direct reset binds.
     */
    public function test_write_and_clear_reset_own_memo_without_hooks(): void
    {
        $cache = $this->cache();
        $this->assertFalse($cache->readOk());
        $GLOBALS['__oxpulse_actions'] = [];

        $cache->write('ok');
        $this->assertTrue($cache->readOk(), 'write() must reset the memo itself');

        $GLOBALS['__oxpulse_actions'] = [];
        $cache->clear();
        $this->assertFalse($cache->readOk(), 'clear() must reset the memo itself');
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

    /**
     * A1: the readOk() verdict is memoised per instance. One rewriter
     * instance rewriting 20 URLs must not re-read the non-autoloaded
     * cachebuster options — at most 2 reads of that option pair total.
     */
    public function test_read_ok_memoized_across_rewriter_calls(): void
    {
        update_option(SocialJpegCapabilityCache::CACHEBUSTER_OPTION, 'ok');

        $capability = SocialJpegCapabilityCache::forCacheBuster();
        $versions = new class implements ContentVersionResolver {
            public function versionFor(string $sourceUrl): string
            {
                return 'deadbeef';
            }
        };
        $backend = new class implements DeliveryBackend {
            public function available(): bool
            {
                return true;
            }

            public function generate(TransformRequest $request, ?string $filename = null): string
            {
                return 'https://imgproxy.example.com/sig/' . rawurlencode($request->sourceUrl);
            }

            public function socialSafeUrl(TransformRequest $request, ?string $filename = null): ?string
            {
                return null;
            }
        };
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: ['https://example.com/wp-content/uploads/'],
        );
        $rewriter = new UrlRewriter(
            new SourcePolicy(),
            $delivery,
            SigningConfig::fromHex('736563726574', '68656C6C6F'),
            null,
            $backend,
            $versions,
            $capability,
        );

        $GLOBALS['__oxpulse_get_option_calls_per_key'] = [];
        $src = 'https://example.com/wp-content/uploads/photo.jpg';
        for ($i = 0; $i < 20; $i++) {
            $rewriter->rewrite($src, 300, 0, 'content');
        }

        $cachebusterReads =
            ($GLOBALS['__oxpulse_get_option_calls_per_key'][SocialJpegCapabilityCache::CACHEBUSTER_OPTION] ?? 0)
            + ($GLOBALS['__oxpulse_get_option_calls_per_key'][SocialJpegCapabilityCache::CACHEBUSTER_OPTION_CHECKED_AT] ?? 0);
        $this->assertLessThanOrEqual(
            2,
            $cachebusterReads,
            'readOk() must be memoised per instance — 20 rewrites must not re-read the cachebuster options',
        );
    }
}
