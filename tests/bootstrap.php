<?php
/**
 * PHPUnit test bootstrap.
 *
 * Loads the WordPress test bootstrap when available. For unit tests
 * that do not require WordPress, a minimal stub environment is provided
 * so pure domain logic can be tested without a full WP installation.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

$_tests_dir = getenv('WP_TESTS_DIR');

if ($_tests_dir && file_exists($_tests_dir . '/includes/functions.php')) {
    require_once $_tests_dir . '/includes/functions.php';

    tests_add_filter('muplugins_loaded', static function (): void {
        require dirname(__DIR__) . '/oxpulse-imager.php';
    });

    require $_tests_dir . '/includes/bootstrap.php';
} else {
    // Minimal stub environment for unit tests without WordPress.
    define('ABSPATH', '/tmp/wp-stub');
    define('OXPULSE_IMAGER_VERSION', '0.1.0');
    define('OXPULSE_IMAGER_FILE', '/tmp/oxpulse-imager/oxpulse-imager.php');
    define('OXPULSE_IMAGER_DIR', '/tmp/oxpulse-imager/');
    define('OXPULSE_IMAGER_URL', 'https://example.test/wp-content/plugins/oxpulse-imager/');
    define('OXPULSE_IMAGER_BASENAME', 'oxpulse-imager/oxpulse-imager.php');
    define('OXPULSE_IMAGER_OPTION_PREFIX', 'oxpulse_imager_');
    define('OXPULSE_IMAGER_CAPABILITY', 'manage_oxpulse_imager');

    $GLOBALS['wp_version'] = '6.8';

    if (!function_exists('__')) {
        function __($text, $domain = 'default') { return $text; }
    }
    if (!function_exists('esc_html')) {
        function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('add_action')) {
        function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {}
    }
    if (!function_exists('add_option')) {
        function add_option($option, $value = '', $deprecated = '', $autoload = true) { return true; }
    }
    if (!function_exists('get_option')) {
        function get_option($option, $default = false) { return $default; }
    }
    if (!function_exists('delete_option')) {
        function delete_option($option) { return true; }
    }
    if (!function_exists('delete_transient')) {
        function delete_transient($transient) { return true; }
    }
    if (!function_exists('plugin_dir_path')) {
        function plugin_dir_path($file) { return dirname($file) . '/'; }
    }
    if (!function_exists('plugin_dir_url')) {
        function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/' . basename(dirname($file)) . '/'; }
    }
    if (!function_exists('plugin_basename')) {
        function plugin_basename($file) { return 'oxpulse-imager/oxpulse-imager.php'; }
    }
    if (!function_exists('register_activation_hook')) {
        function register_activation_hook($file, $callback) {}
    }
    if (!function_exists('register_deactivation_hook')) {
        function register_deactivation_hook($file, $callback) {}
    }
    if (!function_exists('load_plugin_textdomain')) {
        function load_plugin_textdomain($domain, $deprecated = false, $plugin_rel_path = false) { return true; }
    }
}
