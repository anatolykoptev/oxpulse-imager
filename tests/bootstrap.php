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

    // The main plugin file defines OXPULSE_IMAGER_* constants and the
    // activation/deactivation stubs. We load it below after declaring the
    // WordPress polyfills, so we do NOT pre-define those constants here.

    $GLOBALS['wp_version'] = '6.8';

    if (!function_exists('__')) {
        function __($text, $domain = 'default') { return $text; }
    }
    if (!function_exists('esc_html')) {
        function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('esc_html_e')) {
        function esc_html_e($text, $domain = 'default') { echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('esc_html__')) {
        function esc_html__($text, $domain = 'default') { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('esc_attr_e')) {
        function esc_attr_e($text, $domain = 'default') { echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('esc_attr__')) {
        function esc_attr__($text, $domain = 'default') { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('esc_attr')) {
        function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('add_action')) {
        function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
            $GLOBALS['__oxpulse_actions'][] = [
                'hook' => $hook,
                'callback' => $callback,
                'priority' => $priority,
                'accepted_args' => $accepted_args,
            ];
        }
    }
    if (!function_exists('add_filter')) {
        function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
            $GLOBALS['__oxpulse_filters'][] = [
                'hook' => $hook,
                'callback' => $callback,
                'priority' => $priority,
                'accepted_args' => $accepted_args,
            ];
        }
    }
    if (!function_exists('add_option')) {
        function add_option($option, $value = '', $deprecated = '', $autoload = true) {
            $GLOBALS['__oxpulse_options'][$option] = $value;
            return true;
        }
    }
    if (!function_exists('get_option')) {
        function get_option($option, $default = false) {
            return array_key_exists($option, $GLOBALS['__oxpulse_options'] ?? [])
                ? $GLOBALS['__oxpulse_options'][$option]
                : $default;
        }
    }
    if (!function_exists('update_option')) {
        function update_option($option, $value, $autoload = null) {
            $GLOBALS['__oxpulse_options'][$option] = $value;
            return true;
        }
    }
    if (!function_exists('delete_option')) {
        function delete_option($option) {
            unset($GLOBALS['__oxpulse_options'][$option]);
            return true;
        }
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
    if (!function_exists('is_admin')) {
        function is_admin() {
            return !empty($GLOBALS['__oxpulse_is_admin']);
        }
    }
    if (!function_exists('current_user_can')) {
        function current_user_can($capability) {
            return !empty($GLOBALS['__oxpulse_current_user_can'][$capability])
                ? $GLOBALS['__oxpulse_current_user_can'][$capability]
                : false;
        }
    }
    if (!function_exists('wp_create_nonce')) {
        function wp_create_nonce($action = -1) {
            return 'stub_nonce_' . md5((string) $action);
        }
    }
    if (!function_exists('check_admin_referer')) {
        function check_admin_referer($action = -1, $queryArg = '_wpnonce') {
            if (!empty($GLOBALS['__oxpulse_referer_fail'])) {
                return false;
            }
            return true;
        }
    }
    if (!function_exists('wp_verify_nonce')) {
        function wp_verify_nonce($nonce, $action = -1) { return true; }
    }
    if (!function_exists('wp_safe_redirect')) {
        function wp_safe_redirect($location, $status = 302) {
            $GLOBALS['__oxpulse_redirects'][] = ['location' => $location, 'status' => $status];
            return true;
        }
    }
    if (!function_exists('admin_url')) {
        function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
    }
    if (!function_exists('wp_die')) {
        function wp_die($message = '', $title = '', $args = []) {
            $GLOBALS['__oxpulse_wp_die'][] = ['message' => $message, 'title' => $title];
            throw new \RuntimeException('wp_die: ' . (string) $message);
        }
    }
    if (!function_exists('add_options_page')) {
        function add_options_page($pageTitle, $menuTitle, $capability, $menuSlug, $callback = '') {
            $GLOBALS['__oxpulse_menu_pages'][] = [
                'pageTitle' => $pageTitle,
                'menuTitle' => $menuTitle,
                'capability' => $capability,
                'menuSlug' => $menuSlug,
                'callback' => $callback,
            ];
            return $menuSlug;
        }
    }
    if (!function_exists('register_setting')) {
        function register_setting($optionGroup, $optionName, $args = []) {
            $GLOBALS['__oxpulse_registered_settings'][$optionGroup][$optionName] = $args;
            return true;
        }
    }
    if (!function_exists('admin_post')) {
        // No-op; actions are stored via add_action.
    }
    if (!function_exists('get_admin_page_title')) {
        function get_admin_page_title() { return 'OXPulse Imager'; }
    }
    if (!function_exists('submit_button')) {
        function submit_button($text = '', $type = 'primary', $name = 'submit', $wrap = true, $otherAttrs = '') {
            echo '<button type="submit" name="' . htmlspecialchars((string) $name, ENT_QUOTES) . '">'
                . htmlspecialchars((string) $text, ENT_QUOTES) . '</button>';
        }
    }
    if (!function_exists('checked')) {
        function checked($helper, $current = true, $echo = true) {
            $result = $helper === $current ? ' checked="checked"' : '';
            if ($echo) { echo $result; }
            return $result;
        }
    }
    if (!function_exists('selected')) {
        function selected($helper, $current = true, $echo = true) {
            $result = $helper === $current ? ' selected="selected"' : '';
            if ($echo) { echo $result; }
            return $result;
        }
    }
    if (!function_exists('esc_textarea')) {
        function esc_textarea($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('esc_url')) {
        function esc_url($url) { return filter_var($url, FILTER_SANITIZE_URL) ?: ''; }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data, $options = 0, $depth = 512) {
            return json_encode($data, $options, $depth);
        }
    }
    if (!function_exists('wp_remote_head')) {
        function wp_remote_head($url, $args = []) {
            return self_stub_http_response($url, $args, 'head');
        }
    }
    if (!function_exists('wp_remote_get')) {
        function wp_remote_get($url, $args = []) {
            return self_stub_http_response($url, $args, 'get');
        }
    }
    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response) {
            if (is_array($response) && isset($response['response']['code'])) {
                return (int) $response['response']['code'];
            }
            return 0;
        }
    }
    if (!function_exists('wp_remote_retrieve_headers')) {
        function wp_remote_retrieve_headers($response) {
            if (is_array($response) && isset($response['headers'])) {
                return $response['headers'];
            }
            return [];
        }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing) { return $thing instanceof \WP_Error; }
    }
    if (!class_exists('WP_Error')) {
        class WP_Error {
            public $errors = [];
            public $data;
            public function __construct($code = '', $message = '', $data = '') {
                if ($code) { $this->errors[$code] = [$message]; }
                $this->data = $data;
            }
            public function get_error_message($code = '') {
                if ($code === '') { $code = array_key_first($this->errors); }
                return $this->errors[$code][0] ?? '';
            }
            public function get_error_data($code = '') {
                if ($code === '') { $code = array_key_first($this->errors); }
                return $this->data;
            }
        }
    }

    // REST API stubs — for testing REST controllers.
    if (!class_exists('WP_REST_Server')) {
        class WP_REST_Server {
            const READABLE   = 'GET';
            const CREATABLE  = 'POST';
            const EDITABLE   = 'POST, PUT, PATCH';
            const DELETABLE  = 'DELETE';
            const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
        }
    }
    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response {
            public $data;
            public $status = 200;
            public function __construct($data = null, $status = 200) {
                $this->data = $data;
                $this->status = $status;
            }
            public function get_data() { return $this->data; }
            public function set_data($data) { $this->data = $data; }
        }
    }
    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request {
            private array $params = [];
            private array $jsonParams = [];
            public function __construct(array $jsonParams = []) {
                $this->jsonParams = $jsonParams;
                $this->params = $jsonParams;
            }
            public function get_json_params() { return $this->jsonParams; }
            public function get_param($key) { return $this->params[$key] ?? null; }
            public function set_param($key, $value) { $this->params[$key] = $value; }
        }
    }
    if (!function_exists('register_rest_route')) {
        function register_rest_route($namespace, $route, $args = []) {
            // Normalize: route may or may not start with '/'. Real WP
            // builds "namespace/route" with a single slash.
            $route = ltrim($route, '/');
            $GLOBALS['__oxpulse_rest_routes'][$namespace . '/' . $route] = $args;
        }
    }
    if (!function_exists('rest_ensure_response')) {
        function rest_ensure_response($data) {
            if ($data instanceof \WP_REST_Response) { return $data; }
            if ($data instanceof \WP_Error) { return $data; }
            return new \WP_REST_Response($data);
        }
    }
    if (!function_exists('rest_url')) {
        function rest_url($path = '') {
            return 'http://example.test/wp-json/' . ltrim($path, '/');
        }
    }

    // Helper for stub HTTP responses. Tests register responses in
    // $GLOBALS['__oxpulse_http_responses'] keyed by URL. If a custom
    // header-based key is registered, that takes precedence.
    if (!function_exists('self_stub_http_response')) {
        function self_stub_http_response($url, $args, $method) {
            $headers = $args['headers'] ?? [];
            $key = $url;
            if (!empty($headers['Accept'])) {
                $key = $url . '#Accept=' . $headers['Accept'];
            }
            if (isset($GLOBALS['__oxpulse_http_responses'][$key])) {
                return $GLOBALS['__oxpulse_http_responses'][$key];
            }
            if (isset($GLOBALS['__oxpulse_http_responses'][$url])) {
                return $GLOBALS['__oxpulse_http_responses'][$url];
            }
            return new \WP_Error('stub', 'No stub response registered for ' . $url);
        }
    }
    if (!function_exists('http_build_query')) {
        // PHP built-in; declared here only as a safety net if a polyfill
        // environment lacks it. In practice this branch never fires.
    }

    // Load the main plugin file in the stub environment so its
    // top-level functions (activation/deactivation guards, constants)
    // are available to unit tests.
    require_once dirname(__DIR__) . '/oxpulse-imager.php';
}
