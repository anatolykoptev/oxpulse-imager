<?php
/**
 * Uninstall handler — complete cleanup (#88).
 *
 * Removes EVERYTHING the plugin created: all options (the
 * oxpulse_imager_ prefix family + standalone keys), all cron events,
 * all transients (static + dynamic UUID-suffixed), the on-disk cache
 * directory (WP_CONTENT_DIR/cache/oxpulse/), the generated endpoint
 * file (oxpulse-img.php) + its cache .htaccess, and per-site cleanup
 * on multisite.
 *
 * Guarded by WP_UNINSTALL_PLUGIN (defined by WordPress only when the
 * user clicks "Delete" in the plugins admin). Direct access exits.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Bootstrap class loading. In development / composer installs the
// vendor autoloader is available; in the wordpress.org release ZIP
// vendor/ is export-ignored, so we register a self-contained PSR-4
// autoloader pointing at src/ (same pattern as the generated
// miss-endpoint file).
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'OXPulse\\Imager\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = __DIR__ . '/src/' . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

\OXPulse\Imager\Infrastructure\WordPress\Uninstaller::run();
