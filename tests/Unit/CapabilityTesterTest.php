<?php
/**
 * CapabilityTester tests.
 *
 * Verifies the live-probe-based rewrite capability detection:
 *  - nginx / non-Apache → false with NO HTTP round-trip.
 *  - Apache + mod_php without mod_rewrite → false without probe.
 *  - Apache + php-fpm (no apache_get_modules) → defers to the live probe.
 *  - Probe 'yes' → available; 'no' / 'unknown' → fallbackNeeded.
 *  - Cached option is read first; cache miss invokes the probe + stores.
 *  - invalidateCache() forces a re-probe on the next call.
 *  - recheck() forces a fresh probe and returns the tri-state result.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Local\CapabilityTester;
use OXPulse\Imager\Infrastructure\Local\LocalRewriteProbe;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use PHPUnit\Framework\TestCase;

class CapabilityTesterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        // Ensure the test SAPI looks like php-fpm (no apache_get_modules).
        // $_SERVER['SERVER_SOFTWARE'] is unset by default; individual
        // tests set it to simulate nginx / Apache / LiteSpeed.
        unset($_SERVER['SERVER_SOFTWARE']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
        unset($_SERVER['SERVER_SOFTWARE']);
    }

    // ─── (a) nginx SERVER_SOFTWARE → false, NO probe call ───────────────

    public function test_nginx_short_circuits_false_without_probe(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';
        $probe = new StubProbe('yes');
        $tester = new CapabilityTester($probe);

        $this->assertFalse($tester->rewriteAvailable());
        $this->assertFalse($probe->wasCalled, 'Probe must NOT be called on non-Apache');
    }

    public function test_nginx_fallback_needed_without_probe(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';
        $probe = new StubProbe('yes');
        $tester = new CapabilityTester($probe);

        $this->assertTrue($tester->fallbackNeeded());
        $this->assertFalse($probe->wasCalled);
    }

    // ─── (b) probe 'yes' → available; 'no' / 'unknown' → fallbackNeeded ──

    public function test_probe_yes_means_available(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $tester = new CapabilityTester(new StubProbe('yes'));

        $this->assertTrue($tester->rewriteAvailable());
        $this->assertFalse($tester->fallbackNeeded());
    }

    public function test_probe_no_means_fallback_needed(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $tester = new CapabilityTester(new StubProbe('no'));

        $this->assertFalse($tester->rewriteAvailable());
        $this->assertTrue($tester->fallbackNeeded());
    }

    public function test_probe_unknown_means_fallback_needed(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $tester = new CapabilityTester(new StubProbe('unknown'));

        $this->assertFalse($tester->rewriteAvailable());
        $this->assertTrue($tester->fallbackNeeded());
    }

    // ─── (c) cache hit reads the option without probing ─────────────────

    public function test_cache_hit_yes_returns_available_without_probe(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'yes');
        $probe = new StubProbe('no'); // would say "no" if called
        $tester = new CapabilityTester($probe);

        $this->assertTrue($tester->rewriteAvailable());
        $this->assertFalse($probe->wasCalled, 'Probe must NOT be called on cache hit');
    }

    public function test_cache_hit_no_returns_unavailable_without_probe(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'no');
        $probe = new StubProbe('yes'); // would say "yes" if called
        $tester = new CapabilityTester($probe);

        $this->assertFalse($tester->rewriteAvailable());
        $this->assertFalse($probe->wasCalled);
    }

    public function test_cache_hit_unknown_returns_unavailable_without_probe(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'unknown');
        $probe = new StubProbe('yes');
        $tester = new CapabilityTester($probe);

        $this->assertFalse($tester->rewriteAvailable());
        $this->assertFalse($probe->wasCalled);
    }

    // ─── (d) cache miss invokes the probe and stores the result ──────────

    public function test_cache_miss_invokes_probe_and_stores_yes(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $probe = new StubProbe('yes');
        $tester = new CapabilityTester($probe);

        $this->assertTrue($tester->rewriteAvailable());
        $this->assertTrue($probe->wasCalled);

        $stored = get_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY);
        $this->assertSame('yes', $stored);
    }

    public function test_cache_miss_invokes_probe_and_stores_no(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $probe = new StubProbe('no');
        $tester = new CapabilityTester($probe);

        $this->assertFalse($tester->rewriteAvailable());
        $this->assertTrue($probe->wasCalled);

        $stored = get_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY);
        $this->assertSame('no', $stored);
    }

    public function test_cache_miss_stamps_checked_at(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $before = time();
        $tester = new CapabilityTester(new StubProbe('yes'));
        $tester->rewriteAvailable();
        $after = time();

        $checkedAt = (int) get_option(
            OptionSettingsRepository::OPTION_REWRITE_CAPABILITY_CHECKED_AT, 0,
        );
        $this->assertGreaterThanOrEqual($before, $checkedAt);
        $this->assertLessThanOrEqual($after, $checkedAt);
    }

    // ─── (e) invalidateCache() forces a re-probe ─────────────────────────

    public function test_invalidate_cache_forces_reprobe(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'yes');

        $probe = new StubProbe('no');
        $tester = new CapabilityTester($probe);

        // Cache hit → no probe.
        $this->assertTrue($tester->rewriteAvailable());
        $this->assertFalse($probe->wasCalled);

        $tester->invalidateCache();
        $this->assertNull(get_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, null));

        // Cache miss → probe fires, returns 'no'.
        $this->assertFalse($tester->rewriteAvailable());
        $this->assertTrue($probe->wasCalled);
    }

    // ─── (f) php-fpm path defers to probe rather than returning false ────

    public function test_fpm_defers_to_probe_when_apache_get_modules_missing(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        // In the test SAPI, apache_get_modules() does not exist — this
        // simulates php-fpm. modRewriteLoaded() returns null (can't tell).
        // The tester must defer to the probe, NOT return false.
        $probe = new StubProbe('yes');
        $tester = new class($probe) extends CapabilityTester {
            protected function modRewriteLoaded(): ?bool { return null; }
        };

        $this->assertTrue($tester->rewriteAvailable());
        $this->assertTrue($probe->wasCalled, 'FPM path must defer to the probe');
    }

    public function test_fpm_probe_no_means_fallback(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $probe = new StubProbe('no');
        $tester = new class($probe) extends CapabilityTester {
            protected function modRewriteLoaded(): ?bool { return null; }
        };

        $this->assertFalse($tester->rewriteAvailable());
        $this->assertTrue($probe->wasCalled);
    }

    // ─── mod_php without mod_rewrite → false without probe ───────────────

    public function test_mod_php_without_mod_rewrite_returns_false_without_probe(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $probe = new StubProbe('yes');
        $tester = new class($probe) extends CapabilityTester {
            protected function modRewriteLoaded(): ?bool { return false; }
        };

        $this->assertFalse($tester->rewriteAvailable());
        $this->assertFalse($probe->wasCalled, 'Probe must NOT be called when mod_rewrite is definitively absent');
    }

    public function test_mod_php_with_mod_rewrite_defers_to_probe_on_cache_miss(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $probe = new StubProbe('yes');
        $tester = new class($probe) extends CapabilityTester {
            protected function modRewriteLoaded(): ?bool { return true; }
        };

        $this->assertTrue($tester->rewriteAvailable());
        $this->assertTrue($probe->wasCalled);
    }

    // ─── recheck() forces a fresh probe and returns the tri-state ────────

    public function test_recheck_forces_fresh_probe_and_returns_result(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'yes');

        $probe = new StubProbe('no');
        $tester = new CapabilityTester($probe);

        // recheck() ignores the cache and re-probes.
        $result = $tester->recheck();
        $this->assertSame('no', $result);
        $this->assertTrue($probe->wasCalled);

        // The new result is stored.
        $this->assertSame('no', get_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY));
    }

    public function test_recheck_returns_yes_and_makes_rewrite_available(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $tester = new CapabilityTester(new StubProbe('yes'));

        $this->assertSame('yes', $tester->recheck());
        $this->assertTrue($tester->rewriteAvailable());
    }

    // ─── LiteSpeed is treated as Apache (probe is authoritative) ─────────

    public function test_litespeed_treated_as_apache_and_probes(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
        $probe = new StubProbe('yes');
        $tester = new CapabilityTester($probe);

        $this->assertTrue($tester->rewriteAvailable());
        $this->assertTrue($probe->wasCalled);
    }
}

/**
 * Stub LocalRewriteProbe that returns a canned tri-state result.
 * Records whether probe() was called.
 */
class StubProbe extends LocalRewriteProbe
{
    public bool $wasCalled = false;

    public function __construct(private string $result)
    {
        // Parent constructor is bypassed — we override probe() entirely.
        // Pass dummy values to satisfy the parent signature.
        parent::__construct('/tmp/stub', 'https://stub.test/.probe', new NullHttpRequester());
    }

    public function probe(): string
    {
        $this->wasCalled = true;
        return $this->result;
    }
}

/**
 * No-op HttpRequester for the stub probe (never actually called).
 */
class NullHttpRequester implements \OXPulse\Imager\Infrastructure\Local\HttpRequester
{
    public function get(string $url): array
    {
        return ['status' => 0, 'body' => '', 'error' => 'null'];
    }
}
