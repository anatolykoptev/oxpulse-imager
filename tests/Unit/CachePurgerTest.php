<?php
/**
 * CachePurger unit tests.
 *
 * #43 Phase 4 (plan B.3 / D.4 #5): verifies that CachePurger::purge()
 * fires each supported cache-plugin's purge hook when the plugin is
 * present, is a no-op when absent, never fatals on a throwing plugin,
 * and always fires the generic escape-hatch action.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Integration\WordPress\Performance\CachePurger;
use PHPUnit\Framework\TestCase;

class CachePurgerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_did_action'] = [];
        $GLOBALS['__oxpulse_wp_rocket_called'] = false;
        $GLOBALS['__oxpulse_w3tc_called'] = false;
        $GLOBALS['__oxpulse_w3tc_throw'] = false;
        $GLOBALS['__oxpulse_wp_super_cache_called'] = false;
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__oxpulse_actions'],
            $GLOBALS['__oxpulse_filters'],
            $GLOBALS['__oxpulse_did_action'],
            $GLOBALS['__oxpulse_wp_rocket_called'],
            $GLOBALS['__oxpulse_w3tc_called'],
            $GLOBALS['__oxpulse_w3tc_throw'],
            $GLOBALS['__oxpulse_wp_super_cache_called'],
        );
    }

    // ─── generic escape hatch ────────────────────────────────────────────

    public function test_purge_always_fires_generic_escape_hatch(): void
    {
        (new CachePurger())->purge();

        $this->assertGreaterThan(
            0,
            did_action('oxpulse_purge_page_cache'),
            'purge() must always fire the generic oxpulse_purge_page_cache action',
        );
    }

    // ─── WP Rocket ───────────────────────────────────────────────────────

    public function test_purge_fires_wp_rocket_when_present(): void
    {
        (new CachePurger())->purge();

        $this->assertTrue(
            $GLOBALS['__oxpulse_wp_rocket_called'],
            'WP Rocket purge (rocket_clean_domain) must fire when the function exists',
        );
        $this->assertGreaterThan(0, did_action('after_rocket_clean_domain'));
    }

    public function test_purge_skips_wp_rocket_when_absent(): void
    {
        $purger = new class extends CachePurger {
            protected function wpRocketAvailable(): bool { return false; }
        };
        $purger->purge();

        $this->assertFalse(
            $GLOBALS['__oxpulse_wp_rocket_called'],
            'WP Rocket purge must NOT fire when the function is absent',
        );
        $this->assertSame(0, did_action('after_rocket_clean_domain'));
    }

    // ─── W3 Total Cache ──────────────────────────────────────────────────

    public function test_purge_fires_w3tc_function_when_present(): void
    {
        (new CachePurger())->purge();

        $this->assertTrue(
            $GLOBALS['__oxpulse_w3tc_called'],
            'w3tc_flush_all() must fire when the function exists',
        );
        $this->assertSame(
            0,
            did_action('w3tc_flush_all'),
            'w3tc_flush_all action must NOT fire when the function is present',
        );
    }

    public function test_purge_fires_w3tc_action_when_function_absent(): void
    {
        $purger = new class extends CachePurger {
            protected function w3tcAvailable(): bool { return false; }
        };
        $purger->purge();

        $this->assertFalse(
            $GLOBALS['__oxpulse_w3tc_called'],
            'w3tc_flush_all() must NOT fire when the function is absent',
        );
        $this->assertGreaterThan(
            0,
            did_action('w3tc_flush_all'),
            'w3tc_flush_all action must fire as fallback when the function is absent',
        );
    }

    // ─── LiteSpeed Cache ─────────────────────────────────────────────────

    public function test_purge_fires_litespeed_when_present(): void
    {
        (new CachePurger())->purge();

        $this->assertGreaterThan(
            0,
            did_action('litespeed_purge_all'),
            'LiteSpeed purge action must fire when the class exists',
        );
    }

    public function test_purge_skips_litespeed_when_absent(): void
    {
        $purger = new class extends CachePurger {
            protected function litespeedAvailable(): bool { return false; }
        };
        $purger->purge();

        $this->assertSame(
            0,
            did_action('litespeed_purge_all'),
            'LiteSpeed purge must NOT fire when the class is absent',
        );
    }

    // ─── WP Super Cache ──────────────────────────────────────────────────

    public function test_purge_fires_wp_super_cache_when_present(): void
    {
        (new CachePurger())->purge();

        $this->assertTrue(
            $GLOBALS['__oxpulse_wp_super_cache_called'],
            'wp_cache_clear_cache() must fire when the function exists',
        );
    }

    public function test_purge_skips_wp_super_cache_when_absent(): void
    {
        $purger = new class extends CachePurger {
            protected function wpSuperCacheAvailable(): bool { return false; }
        };
        $purger->purge();

        $this->assertFalse(
            $GLOBALS['__oxpulse_wp_super_cache_called'],
            'WP Super Cache purge must NOT fire when the function is absent',
        );
    }

    // ─── Cache Enabler ───────────────────────────────────────────────────

    public function test_purge_fires_cache_enabler_when_hook_present(): void
    {
        add_action('cache_enabler_clear_complete_cache', static function (): void {});
        (new CachePurger())->purge();

        $this->assertGreaterThan(
            0,
            did_action('cache_enabler_clear_complete_cache'),
            'Cache Enabler purge must fire when the hook is registered',
        );
    }

    public function test_purge_skips_cache_enabler_when_hook_absent(): void
    {
        (new CachePurger())->purge();

        $this->assertSame(
            0,
            did_action('cache_enabler_clear_complete_cache'),
            'Cache Enabler purge must NOT fire when the hook is absent',
        );
    }

    // ─── fault isolation ─────────────────────────────────────────────────

    public function test_throwing_plugin_does_not_break_purge(): void
    {
        $GLOBALS['__oxpulse_w3tc_throw'] = true;

        (new CachePurger())->purge();

        $this->assertFalse(
            $GLOBALS['__oxpulse_w3tc_called'],
            'w3tc_flush_all threw before setting its flag',
        );
        $this->assertTrue(
            $GLOBALS['__oxpulse_wp_rocket_called'],
            'WP Rocket purge must still fire after W3TC threw',
        );
        $this->assertTrue(
            $GLOBALS['__oxpulse_wp_super_cache_called'],
            'WP Super Cache purge must still fire after W3TC threw',
        );
        $this->assertGreaterThan(
            0,
            did_action('oxpulse_purge_page_cache'),
            'Generic escape hatch must fire even after a plugin threw',
        );
    }
}

// ─── Global stub functions for present-case tests ────────────────────────
// Defined in a separate file (bracketed namespace for LiteSpeed\Purge
// can't coexist with this file's unbracketed namespace). Loaded once.

require_once __DIR__ . '/stubs/cache-plugin-stubs.php';
