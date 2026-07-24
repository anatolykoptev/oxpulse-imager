<?php
/**
 * CapabilityTester tests.
 *
 * #43 Phase 1 review: verifies the front-end-safe (zero blocking I/O)
 * rewrite-capability detection:
 *
 *  - REGRESSION LOCK: rewriteAvailable() / fallbackNeeded() NEVER invoke
 *    the probe, for EVERY cache state (cached 'yes'/'no'/'unknown'/unset).
 *    The front-end read path must not block page render.
 *  - Static heuristic default (cache unset/'unknown', no probe):
 *      isApache + mod_rewrite loaded → true;
 *      nginx → false;
 *      FPM (apache_get_modules undefined, can't tell) → false (conservative).
 *  - recheck() DOES probe + stores ONLY definitive 'yes'/'no'; a probe
 *    'unknown' does NOT overwrite a prior 'yes'/'no' and does NOT persist
 *    a fallback-forcing value.
 *  - invalidateCache() routes through the repository (deletes the option).
 *  - Repository routing: no direct get_option/update_option/delete_option
 *    in the class (grep-assert).
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
use PHPUnit\Framework\Attributes\DataProvider;

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

    // ─── (a) REGRESSION LOCK: the read path NEVER probes, any cache state ──

    /**
     * The front-end read path (rewriteAvailable) must NEVER invoke the
     * probe, regardless of cache state. This is the BLOCKER regression
     * lock — rewriteAvailable() is called on every front-end request
     * when LocalBackend is active and must not block page render.
     */
    #[DataProvider('cacheStatesAndExpected')]
    public function test_rewriteAvailable_never_probes_for_any_cache_state(?string $cacheState): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        if ($cacheState !== null) {
            update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, $cacheState);
        }
        $probe = new StubProbe('yes'); // would say "yes" if called
        $tester = new class($probe) extends CapabilityTester {
            protected function modRewriteLoaded(): ?bool { return true; }
        };

        $tester->rewriteAvailable();

        $this->assertFalse(
            $probe->wasCalled,
            'rewriteAvailable() must NEVER invoke the probe (cache state: ' . var_export($cacheState, true) . ')',
        );
    }

    /**
     * fallbackNeeded() (the actual call site in ServiceRegistrar) must
     * NEVER invoke the probe either.
     */
    #[DataProvider('cacheStatesAndExpected')]
    public function test_fallbackNeeded_never_probes_for_any_cache_state(?string $cacheState): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        if ($cacheState !== null) {
            update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, $cacheState);
        }
        $probe = new StubProbe('yes');
        $tester = new class($probe) extends CapabilityTester {
            protected function modRewriteLoaded(): ?bool { return true; }
        };

        $tester->fallbackNeeded();

        $this->assertFalse(
            $probe->wasCalled,
            'fallbackNeeded() must NEVER invoke the probe (cache state: ' . var_export($cacheState, true) . ')',
        );
    }

    public static function cacheStatesAndExpected(): array
    {
        return [
            'cached yes'    => ['yes'],
            'cached no'     => ['no'],
            'cached unknown'=> ['unknown'],
            'unset'         => [null],
        ];
    }

    // ─── (b) cached definitive value is honored on the read path ───────

    public function test_cache_yes_returns_available_without_probe(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'yes');
        $probe = new StubProbe('no'); // would say "no" if called
        $tester = new CapabilityTester($probe);

        $this->assertTrue($tester->rewriteAvailable());
        $this->assertFalse($probe->wasCalled, 'Probe must NOT be called on cache hit');
    }

    public function test_cache_no_returns_unavailable_without_probe(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'no');
        $probe = new StubProbe('yes'); // would say "yes" if called
        $tester = new CapabilityTester($probe);

        $this->assertFalse($tester->rewriteAvailable());
        $this->assertFalse($probe->wasCalled);
    }

    public function test_cache_unknown_falls_back_to_heuristic_without_probe(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'unknown');
        $probe = new StubProbe('yes');
        // mod_rewrite loaded → heuristic true (unknown must NOT force fallback).
        $tester = new class($probe) extends CapabilityTester {
            protected function modRewriteLoaded(): ?bool { return true; }
        };

        $this->assertTrue(
            $tester->rewriteAvailable(),
            'cached "unknown" + mod_rewrite loaded → heuristic true (not forced fallback)',
        );
        $this->assertFalse($probe->wasCalled);
    }

    // ─── (c) static heuristic default (cache unset, no probe) ───────────

    public function test_heuristic_apache_with_mod_rewrite_returns_true(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $probe = new StubProbe('no');
        $tester = new class($probe) extends CapabilityTester {
            protected function modRewriteLoaded(): ?bool { return true; }
        };

        $this->assertTrue($tester->rewriteAvailable());
        $this->assertFalse($probe->wasCalled, 'Heuristic path must not probe');
    }

    public function test_heuristic_nginx_returns_false(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';
        $probe = new StubProbe('yes');
        $tester = new CapabilityTester($probe);

        $this->assertFalse($tester->rewriteAvailable());
        $this->assertFalse($probe->wasCalled);
    }

    public function test_heuristic_fpm_null_modrewrite_returns_false_conservative(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        // In the test SAPI, apache_get_modules() does not exist — this
        // simulates php-fpm. modRewriteLoaded() returns null (can't tell).
        // The heuristic must be CONSERVATIVE → false (do not trust clean
        // URLs without a definitive probe result).
        $probe = new StubProbe('yes');
        $tester = new class($probe) extends CapabilityTester {
            protected function modRewriteLoaded(): ?bool { return null; }
        };

        $this->assertFalse(
            $tester->rewriteAvailable(),
            'FPM (mod_rewrite unknown) + cache miss → conservative false',
        );
        $this->assertFalse($probe->wasCalled);
    }

    public function test_heuristic_mod_php_without_mod_rewrite_returns_false(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $probe = new StubProbe('yes');
        $tester = new class($probe) extends CapabilityTester {
            protected function modRewriteLoaded(): ?bool { return false; }
        };

        $this->assertFalse($tester->rewriteAvailable());
        $this->assertFalse($probe->wasCalled);
    }

    public function test_heuristic_litespeed_with_mod_rewrite_returns_true(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
        $probe = new StubProbe('no');
        $tester = new class($probe) extends CapabilityTester {
            protected function modRewriteLoaded(): ?bool { return true; }
        };

        $this->assertTrue($tester->rewriteAvailable());
        $this->assertFalse($probe->wasCalled);
    }

    // ─── (d) recheck() DOES probe + stores only definitive yes/no ───────

    public function test_recheck_probes_and_stores_yes(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $probe = new StubProbe('yes');
        $tester = new CapabilityTester($probe);

        $this->assertSame('yes', $tester->recheck());
        $this->assertTrue($probe->wasCalled);
        $this->assertSame(
            'yes',
            get_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY),
        );
    }

    public function test_recheck_probes_and_stores_no(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $probe = new StubProbe('no');
        $tester = new CapabilityTester($probe);

        $this->assertSame('no', $tester->recheck());
        $this->assertTrue($probe->wasCalled);
        $this->assertSame(
            'no',
            get_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY),
        );
    }

    public function test_recheck_stamps_checked_at(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $before = time();
        $tester = new CapabilityTester(new StubProbe('yes'));
        $tester->recheck();
        $after = time();

        $checkedAt = (int) get_option(
            OptionSettingsRepository::OPTION_REWRITE_CAPABILITY_CHECKED_AT, 0,
        );
        $this->assertGreaterThanOrEqual($before, $checkedAt);
        $this->assertLessThanOrEqual($after, $checkedAt);
    }

    /**
     * #43 MAJOR: a probe 'unknown' must NOT overwrite a prior definitive
     * 'yes'. The cached value stays 'yes' so the front-end read path
     * keeps clean URLs (a transient loopback failure must not force
     * permanent fallback).
     */
    public function test_recheck_unknown_does_not_overwrite_prior_yes(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'yes');
        $probe = new StubProbe('unknown');
        $tester = new CapabilityTester($probe);

        $this->assertSame('unknown', $tester->recheck());
        $this->assertTrue($probe->wasCalled);

        // The prior definitive 'yes' is preserved — 'unknown' does not stick.
        $this->assertSame(
            'yes',
            get_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY),
            'probe "unknown" must NOT overwrite a prior definitive "yes"',
        );
    }

    public function test_recheck_unknown_does_not_overwrite_prior_no(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'no');
        $probe = new StubProbe('unknown');
        $tester = new CapabilityTester($probe);

        $this->assertSame('unknown', $tester->recheck());

        $this->assertSame(
            'no',
            get_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY),
            'probe "unknown" must NOT overwrite a prior definitive "no"',
        );
    }

    /**
     * #43 MAJOR: a probe 'unknown' with NO prior value must NOT persist
     * a fallback-forcing 'unknown'. The option stays unset so the
     * front-end read path falls back to the static heuristic (which may
     * return true on a mod_php+mod_rewrite host whose loopback is blocked).
     */
    public function test_recheck_unknown_does_not_persist_when_no_prior_value(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $probe = new StubProbe('unknown');
        $tester = new CapabilityTester($probe);

        $this->assertSame('unknown', $tester->recheck());
        $this->assertTrue($probe->wasCalled);

        $this->assertNull(
            get_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, null),
            'probe "unknown" must NOT persist a fallback-forcing value',
        );
    }

    /**
     * A later successful re-probe can upgrade to a definitive 'yes'
     * after a prior 'unknown' probe left the option unset.
     */
    public function test_recheck_yes_after_prior_unknown_upgrades(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        $tester = new CapabilityTester(new StubProbe('unknown'));
        $tester->recheck();
        $this->assertNull(get_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, null));

        $tester2 = new CapabilityTester(new StubProbe('yes'));
        $this->assertSame('yes', $tester2->recheck());
        $this->assertSame('yes', get_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY));
    }

    // ─── (e) invalidateCache() routes through the repository ────────────

    public function test_invalidate_cache_routes_through_repository(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58';
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'yes');
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY_CHECKED_AT, 123);

        $tester = new CapabilityTester(new StubProbe('no'));
        $tester->invalidateCache();

        $this->assertNull(get_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, null));
        $this->assertSame(0, (int) get_option(
            OptionSettingsRepository::OPTION_REWRITE_CAPABILITY_CHECKED_AT, 0,
        ));
    }

    // ─── (f) repository routing: no direct WP option fns in the class ───

    /**
     * MINOR 1: CapabilityTester must route ALL capability-option reads
     * and writes through OptionSettingsRepository — no direct
     * get_option / update_option / delete_option calls in the class.
     */
    public function test_class_does_not_call_wp_option_functions_directly(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Infrastructure/Local/CapabilityTester.php');

        $this->assertStringNotContainsString('get_option(', $source);
        $this->assertStringNotContainsString('update_option(', $source);
        $this->assertStringNotContainsString('delete_option(', $source);
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

    public function head(string $url): array
    {
        return ['status' => 0, 'body' => '', 'error' => 'null'];
    }

    public function getImage(string $url): array
    {
        return ['status' => 0, 'content_type' => '', 'error' => 'null'];
    }
}
