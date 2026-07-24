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
    if (!function_exists('apply_filters')) {
        function apply_filters($tag, $value, ...$args) {
            $filters = $GLOBALS['__oxpulse_filters'] ?? [];
            $callbacks = [];
            foreach ($filters as $entry) {
                if ($entry['hook'] === $tag) {
                    $callbacks[] = $entry;
                }
            }
            usort($callbacks, static fn($a, $b) => $a['priority'] <=> $b['priority']);
            foreach ($callbacks as $entry) {
                $value = call_user_func($entry['callback'], $value, ...$args);
            }
            return $value;
        }
    }
    if (!function_exists('do_action')) {
        function do_action($tag, ...$args) {
            $GLOBALS['__oxpulse_did_action'][$tag] = ($GLOBALS['__oxpulse_did_action'][$tag] ?? 0) + 1;
            $actions = $GLOBALS['__oxpulse_actions'] ?? [];
            $callbacks = [];
            foreach ($actions as $entry) {
                if ($entry['hook'] === $tag) {
                    $callbacks[] = $entry;
                }
            }
            usort($callbacks, static fn($a, $b) => $a['priority'] <=> $b['priority']);
            foreach ($callbacks as $entry) {
                call_user_func($entry['callback'], ...$args);
            }
        }
    }
    if (!function_exists('did_action')) {
        function did_action($tag) {
            return $GLOBALS['__oxpulse_did_action'][$tag] ?? 0;
        }
    }
    if (!function_exists('has_action')) {
        function has_action($hook, $callback = false) {
            $actions = $GLOBALS['__oxpulse_actions'] ?? [];
            foreach ($actions as $entry) {
                if ($entry['hook'] === $hook) {
                    if ($callback === false) {
                        return $entry['priority'];
                    }
                    if ($entry['callback'] === $callback) {
                        return $entry['priority'];
                    }
                }
            }
            return false;
        }
    }
    if (!function_exists('__return_false')) {
        function __return_false() { return false; }
    }
    if (!function_exists('__return_true')) {
        function __return_true() { return true; }
    }
    if (!function_exists('add_option')) {
        function add_option($option, $value = '', $deprecated = '', $autoload = true) {
            $GLOBALS['__oxpulse_options'][$option] = $value;
            // #91: record the autoload arg so the activation-hook
            // test can verify hot defaults are stored autoload=yes.
            $GLOBALS['__oxpulse_autoload'][$option] = (bool) $autoload;
            return true;
        }
    }
    if (!function_exists('get_option')) {
        function get_option($option, $default = false) {
            // #91: count get_option invocations so the memoization
            // tests can assert the in-request cache bounds repeated
            // reads. Tests reset this counter via
            // $GLOBALS['__oxpulse_get_option_calls'] = 0.
            $GLOBALS['__oxpulse_get_option_calls'] = ($GLOBALS['__oxpulse_get_option_calls'] ?? 0) + 1;
            return array_key_exists($option, $GLOBALS['__oxpulse_options'] ?? [])
                ? $GLOBALS['__oxpulse_options'][$option]
                : $default;
        }
    }
    if (!function_exists('update_option')) {
        function update_option($option, $value, $autoload = null) {
            $GLOBALS['__oxpulse_options'][$option] = $value;
            // When autoload is explicitly set (not null), mirror real
            // WP: update the autoload flag alongside the value.
            if ($autoload !== null) {
                $GLOBALS['__oxpulse_autoload'][$option] = (bool) $autoload;
            }
            return true;
        }
    }
    if (!function_exists('delete_option')) {
        function delete_option($option) {
            unset($GLOBALS['__oxpulse_options'][$option]);
            return true;
        }
    }
    // #91: wp_set_options_autoload stub (WP 6.4+). Records the
    // autoload flag flip per option so the migration test can verify
    // the hot options were promoted to autoload=yes. Does not touch
    // the stored values — mirrors the real WP behavior.
    if (!function_exists('wp_set_options_autoload')) {
        function wp_set_options_autoload($options, $autoload) {
            foreach ((array) $options as $option) {
                $GLOBALS['__oxpulse_autoload'][$option] = (bool) $autoload;
            }
            return true;
        }
    }
    if (!function_exists('delete_transient')) {
        function delete_transient($transient) {
            unset($GLOBALS['__oxpulse_transients'][$transient]);
            return true;
        }
    }
    if (!function_exists('wp_delete_file')) {
        function wp_delete_file($file) {
            @unlink($file);
            return true;
        }
    }
    if (!function_exists('get_transient')) {
        function get_transient($transient) {
            return $GLOBALS['__oxpulse_transients'][$transient] ?? false;
        }
    }
    if (!function_exists('set_transient')) {
        function set_transient($transient, $value, $expiration = 0) {
            $GLOBALS['__oxpulse_transients'][$transient] = $value;
            return true;
        }
    }
    if (!function_exists('wp_schedule_single_event')) {
        function wp_schedule_single_event($timestamp, $hook, $args = []) {
            $GLOBALS['__oxpulse_scheduled_events'][] = [
                'timestamp' => $timestamp,
                'hook'      => $hook,
                'args'      => $args,
            ];
            return true;
        }
    }
    // #81: recurring WP-cron API stubs for the imgproxy health
    // recheck cron (and any future recurring event). Mirror the
    // real WP API: wp_schedule_event stores a recurring entry,
    // wp_next_scheduled returns the next timestamp or false,
    // wp_clear_scheduled_hook removes all entries for a hook.
    if (!function_exists('wp_schedule_event')) {
        function wp_schedule_event($timestamp, $recurrence, $hook, $args = [], $wp_error = false) {
            $GLOBALS['__oxpulse_scheduled_events'][] = [
                'timestamp'  => $timestamp,
                'recurrence' => $recurrence,
                'hook'       => $hook,
                'args'       => $args,
            ];
            return true;
        }
    }
    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook, $args = []) {
            $events = $GLOBALS['__oxpulse_scheduled_events'] ?? [];
            foreach ($events as $event) {
                if ($event['hook'] === $hook) {
                    return $event['timestamp'];
                }
            }
            return false;
        }
    }
    if (!function_exists('wp_clear_scheduled_hook')) {
        function wp_clear_scheduled_hook($hook, $args = []) {
            $events = $GLOBALS['__oxpulse_scheduled_events'] ?? [];
            $remaining = [];
            $count = 0;
            foreach ($events as $event) {
                if ($event['hook'] === $hook) {
                    $count++;
                    continue;
                }
                $remaining[] = $event;
            }
            $GLOBALS['__oxpulse_scheduled_events'] = $remaining;
            return $count;
        }
    }
    if (!function_exists('wp_cache_flush_group')) {
        function wp_cache_flush_group($group) { return true; }
    }
    if (!function_exists('wp_get_attachment_url')) {
        function wp_get_attachment_url($id) {
            $map = $GLOBALS['__oxpulse_attachment_urls'] ?? [];
            return $map[$id] ?? false;
        }
    }
    if (!function_exists('get_post_meta')) {
        function get_post_meta($post_id, $key = '', $single = false) {
            $map = $GLOBALS['__oxpulse_post_meta'][$post_id] ?? [];
            if ($key === '') {
                return $map;
            }
            $value = $map[$key] ?? '';
            return $single ? $value : [$value];
        }
    }
    if (!function_exists('wp_get_upload_dir')) {
        function wp_get_upload_dir() {
            return $GLOBALS['__oxpulse_upload_dir'] ?? [
                'baseurl'    => 'https://example.test/wp-content/uploads',
                'basedir'    => '/tmp/wp-content/uploads',
                'baseurlrel' => '/wp-content/uploads',
                'error'      => false,
            ];
        }
    }
    if (!function_exists('wp_get_attachment_metadata')) {
        function wp_get_attachment_metadata($id) {
            $map = $GLOBALS['__oxpulse_attachment_meta'] ?? [];
            return $map[$id] ?? false;
        }
    }
    if (!function_exists('wp_get_registered_image_subsizes')) {
        function wp_get_registered_image_subsizes() {
            return $GLOBALS['__oxpulse_registered_subsizes'] ?? [
                'thumbnail' => ['width' => 150, 'height' => 150],
                'medium' => ['width' => 300, 'height' => 300],
                'medium_large' => ['width' => 768, 'height' => 0],
                'large' => ['width' => 1024, 'height' => 1024],
            ];
        }
    }
    if (!function_exists('get_posts')) {
        function get_posts($args = []) {
            return $GLOBALS['__oxpulse_posts'] ?? [];
        }
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
    // Capability mocks for oxpulse_imager_grant_capability().
    if (!class_exists('WP_Role')) {
        class WP_Role {
            public $capabilities = [];
            public function __construct(array $caps = []) { $this->capabilities = $caps; }
            public function has_cap($cap) { return isset($this->capabilities[$cap]) && $this->capabilities[$cap]; }
            public function add_cap($cap, $grant = true) { $this->capabilities[$cap] = (bool) $grant; }
        }
    }
    if (!function_exists('get_role')) {
        function get_role($role) {
            $roles = $GLOBALS['__oxpulse_roles'] ?? [];
            return $roles[$role] ?? null;
        }
    }
    if (!function_exists('load_plugin_textdomain')) {
        function load_plugin_textdomain($domain, $deprecated = false, $plugin_rel_path = false) { return true; }
    }
    if (!function_exists('is_admin')) {
        function is_admin() {
            return !empty($GLOBALS['__oxpulse_is_admin']);
        }
    }
    // #87: multisite stub. Tests flip $GLOBALS['__oxpulse_is_multisite']
    // to exercise the LocalBackend multisite gate (single-site = false,
    // the production default for a non-multisite install).
    if (!function_exists('is_multisite')) {
        function is_multisite() {
            return !empty($GLOBALS['__oxpulse_is_multisite']);
        }
    }
    // #43 Phase 3: guard-battery stubs. Each reads a $GLOBALS toggle
    // so tests can flip them on/off to exercise every skip branch of
    // BufferRewriter::register()/rewrite(). Defaults match production
    // (all false → buffer allowed to start).
    if (!function_exists('wp_doing_ajax')) {
        function wp_doing_ajax() {
            return !empty($GLOBALS['__oxpulse_doing_ajax']);
        }
    }
    if (!function_exists('wp_doing_cron')) {
        function wp_doing_cron() {
            return !empty($GLOBALS['__oxpulse_doing_cron']);
        }
    }
    if (!function_exists('is_feed')) {
        function is_feed() {
            return !empty($GLOBALS['__oxpulse_is_feed']);
        }
    }
    if (!function_exists('is_embed')) {
        function is_embed() {
            return !empty($GLOBALS['__oxpulse_is_embed']);
        }
    }
    if (!function_exists('is_preview')) {
        function is_preview() {
            return !empty($GLOBALS['__oxpulse_is_preview']);
        }
    }
    if (!function_exists('is_customize_preview')) {
        function is_customize_preview() {
            return !empty($GLOBALS['__oxpulse_is_customize_preview']);
        }
    }
    if (!function_exists('is_amp_endpoint')) {
        function is_amp_endpoint() {
            return !empty($GLOBALS['__oxpulse_is_amp_endpoint']);
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
    if (!function_exists('wp_parse_url')) {
        function wp_parse_url($url, $component = -1) {
            return parse_url($url, $component);
        }
    }
    if (!function_exists('home_url')) {
        function home_url($path = '') {
            return 'https://example.test/' . ltrim($path, '/');
        }
    }
    if (!function_exists('wp_upload_dir')) {
        function wp_upload_dir() {
            return [
                'baseurl'    => 'https://example.com/wp-content/uploads',
                'basedir'    => '/tmp/wp-content/uploads',
                'url'        => 'https://example.com/wp-content/uploads',
                'path'       => '/tmp/wp-content/uploads',
                'subdir'     => '',
                'error'      => false,
            ];
        }
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
    if (!function_exists('wp_remote_retrieve_header')) {
        function wp_remote_retrieve_header($response, $header) {
            if (is_array($response) && isset($response['headers'])) {
                // Headers may be keyed lower-case or case-insensitive;
                // mirror real WP which lowercases header names.
                $headers = $response['headers'];
                if (is_array($headers)) {
                    $lower = strtolower($header);
                    foreach ($headers as $key => $value) {
                        if (strtolower((string) $key) === $lower) {
                            return is_array($value) ? implode(', ', $value) : (string) $value;
                        }
                    }
                }
            }
            return '';
        }
    }
    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body($response) {
            if (is_array($response) && isset($response['body'])) {
                return $response['body'];
            }
            return '';
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

    // #43 Phase 5: admin-notice + capability REST shims.
    if (!function_exists('wp_kses')) {
        function wp_kses($string, $allowedHtml, $allowedProtocols = []) {
            // Minimal stub: strip tags except those in the allow map,
            // and escape attributes. Sufficient for unit-testing notice
            // output structure without a full WP kses stack.
            return self_stub_kses($string, $allowedHtml);
        }
    }
    if (!function_exists('wp_kses_post')) {
        function wp_kses_post($string) {
            return self_stub_kses($string, [
                'a'       => ['href' => true, 'class' => true, 'target' => true, 'rel' => true],
                'p'       => ['class' => true],
                'pre'     => ['class' => true],
                'code'    => ['class' => true],
                'button'  => ['type' => true, 'class' => true, 'data-*' => true],
                'span'    => ['class' => true],
                'div'     => ['class' => true],
                'strong'  => [],
                'em'      => [],
                'br'      => [],
                'svg'     => ['class' => true, 'aria-hidden' => true],
                'path'    => ['d' => true],
            ]);
        }
    }
    if (!function_exists('self_stub_kses')) {
        function self_stub_kses($string, array $allowedHtml) {
            // Strip every tag not in the allow map; keep inner text.
            // Not a security boundary in the test harness — only used
            // to assert the notice passes through wp_kses_post().
            $pattern = '#<(/?)([a-zA-Z0-9]+)([^>]*)>#';
            return (string) preg_replace_callback($pattern, static function ($m) use ($allowedHtml) {
                $tag = strtolower($m[2]);
                if (!isset($allowedHtml[$tag])) {
                    return '';
                }
                return $m[0];
            }, (string) $string);
        }
    }
    if (!function_exists('esc_url_raw')) {
        function esc_url_raw($url) { return filter_var($url, FILTER_SANITIZE_URL) ?: ''; }
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($value) {
            return is_string($value) ? trim(strip_tags($value)) : $value;
        }
    }
    if (!function_exists('wp_unslash')) {
        function wp_unslash($value) {
            return is_string($value) ? stripslashes($value) : $value;
        }
    }
    if (!function_exists('is_plugin_active')) {
        function is_plugin_active($plugin) {
            return !empty($GLOBALS['__oxpulse_active_plugins'][$plugin]);
        }
    }
    if (!function_exists('wp_send_json_success')) {
        function wp_send_json_success($data = null) {
            $GLOBALS['__oxpulse_json_sent'][] = ['status' => 'success', 'data' => $data];
            throw new \RuntimeException('json_sent:success');
        }
    }
    if (!function_exists('wp_send_json_error')) {
        function wp_send_json_error($data = null) {
            $GLOBALS['__oxpulse_json_sent'][] = ['status' => 'error', 'data' => $data];
            throw new \RuntimeException('json_sent:error');
        }
    }
    if (!function_exists('check_ajax_referer')) {
        function check_ajax_referer($action = -1, $queryArg = 'nonce', $die = true) {
            return empty($GLOBALS['__oxpulse_referer_fail']);
        }
    }
    if (!function_exists('wp_print_inline_script_tag')) {
        function wp_print_inline_script_tag($javascript, $attrs = []) {
            echo '<script>' . $javascript . '</script>';
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

    // #88: $wpdb stub for uninstall prefix-scan tests. Mirrors the
    // options store in $GLOBALS['__oxpulse_options'] so DELETE ... LIKE
    // queries actually remove matching keys. Supports esc_like(),
    // prepare(), and query() — the subset Uninstaller uses.
    if (!isset($GLOBALS['wpdb'])) {
        $GLOBALS['wpdb'] = new class {
            public $options = 'wp_options';

            public function esc_like($text) {
                return addcslashes((string) $text, '_%\\');
            }

            public function prepare($query, ...$args) {
                foreach ($args as $arg) {
                    $replacement = is_string($arg) ? "'" . $arg . "'" : (string) $arg;
                    $query = preg_replace('/%s/', $replacement, $query, 1);
                }
                return $query;
            }

            public function query($sql) {
                if (preg_match("/option_name LIKE '([^']+)'/i", (string) $sql, $m)) {
                    $like = $m[1];
                    $regex = '';
                    $i = 0;
                    $len = strlen($like);
                    while ($i < $len) {
                        $ch = $like[$i];
                        if ($ch === '\\' && $i + 1 < $len) {
                            $regex .= preg_quote($like[$i + 1], '/');
                            $i += 2;
                        } elseif ($ch === '%') {
                            $regex .= '.*';
                            $i++;
                        } elseif ($ch === '_') {
                            $regex .= '.';
                            $i++;
                        } else {
                            $regex .= preg_quote($ch, '/');
                            $i++;
                        }
                    }
                    $pattern = '/^' . $regex . '$/';
                    foreach (array_keys($GLOBALS['__oxpulse_options'] ?? []) as $key) {
                        if (preg_match($pattern, (string) $key)) {
                            unset($GLOBALS['__oxpulse_options'][$key]);
                        }
                    }
                }
                return true;
            }
        };
    }

    // #88: multisite stubs for uninstall cleanup tests.
    if (!function_exists('is_multisite')) {
        function is_multisite() {
            return !empty($GLOBALS['__oxpulse_is_multisite']);
        }
    }
    if (!function_exists('get_sites')) {
        function get_sites($args = []) {
            return $GLOBALS['__oxpulse_sites'] ?? [];
        }
    }
    if (!function_exists('switch_to_blog')) {
        function switch_to_blog($blogId) {
            $current = $GLOBALS['__oxpulse_current_blog'] ?? 1;
            $GLOBALS['__oxpulse_blog_options'][$current] = $GLOBALS['__oxpulse_options'] ?? [];
            $GLOBALS['__oxpulse_options'] = $GLOBALS['__oxpulse_blog_options'][$blogId] ?? [];
            $GLOBALS['__oxpulse_current_blog'] = (int) $blogId;
            $GLOBALS['__oxpulse_blog_stack'][] = $current;
            return true;
        }
    }
    if (!function_exists('restore_current_blog')) {
        function restore_current_blog() {
            $current = $GLOBALS['__oxpulse_current_blog'] ?? 1;
            $GLOBALS['__oxpulse_blog_options'][$current] = $GLOBALS['__oxpulse_options'] ?? [];
            $prev = array_pop($GLOBALS['__oxpulse_blog_stack']) ?? 1;
            $GLOBALS['__oxpulse_options'] = $GLOBALS['__oxpulse_blog_options'][$prev] ?? [];
            $GLOBALS['__oxpulse_current_blog'] = $prev;
            return true;
        }
    }
    if (!function_exists('delete_site_option')) {
        function delete_site_option($option) {
            unset($GLOBALS['__oxpulse_network_options'][$option]);
            return true;
        }
    }

    // Freemius SDK stub — the real SDK (freemius/start.php) is NOT
    // loaded in unit tests. The main plugin file's oxpulse_fs() init
    // block is gated on `!function_exists('oxpulse_fs')`, so defining
    // the stub here BEFORE the plugin loads prevents the SDK require
    // and the fs_dynamic_init() HTTP-bound call from running. Tests
    // that exercise FreemiusLicenseGate set $GLOBALS['__oxpulse_fs_stub']
    // to an object with can_use_premium_code(); null = SDK not loaded.
    if (!function_exists('oxpulse_fs')) {
        function oxpulse_fs() {
            return $GLOBALS['__oxpulse_fs_stub'] ?? null;
        }
    }

    // Load the main plugin file in the stub environment so its
    // top-level functions (activation/deactivation guards, constants)
    // are available to unit tests.
    require_once dirname(__DIR__) . '/oxpulse-imager.php';
}
