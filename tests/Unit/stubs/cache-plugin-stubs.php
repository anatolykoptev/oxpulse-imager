<?php
/**
 * Global stub functions for CachePurger present-case tests.
 *
 * Defined in the global namespace so function_exists() finds them.
 * Each flips a $GLOBALS flag so tests can assert the purge fired.
 *
 * Loaded once via require_once from CachePurgerTest.php.
 */

declare(strict_types=1);

namespace {
    if (!function_exists('rocket_clean_domain')) {
        function rocket_clean_domain(): void
        {
            $GLOBALS['__oxpulse_wp_rocket_called'] = true;
            // Model the real plugin: rocket_clean_domain() fires this
            // action itself at the end of the purge.
            do_action('after_rocket_clean_domain');
        }
    }

    if (!function_exists('w3tc_flush_all')) {
        function w3tc_flush_all(): void
        {
            if (!empty($GLOBALS['__oxpulse_w3tc_throw'])) {
                throw new \RuntimeException('w3tc_flush_all explosion');
            }
            $GLOBALS['__oxpulse_w3tc_called'] = true;
        }
    }

    if (!function_exists('wp_cache_clear_cache')) {
        function wp_cache_clear_cache(): void
        {
            $GLOBALS['__oxpulse_wp_super_cache_called'] = true;
        }
    }
}

// LiteSpeed\Purge class stub — defined so class_exists('\LiteSpeed\Purge')
// returns true for the present-case test.
namespace LiteSpeed {
    if (!class_exists('LiteSpeed\Purge')) {
        class Purge {}
    }
}
