<?php
/**
 * Uninstall handler — toggle-gated cleanup (#88).
 *
 * ALWAYS removes ephemeral data: cron events, transients, the on-disk
 * cache directory (WP_CONTENT_DIR/cache/oxpulse/), and the generated
 * endpoint file (oxpulse-img.php) + its cache .htaccess.
 *
 * Removes persistent user CONFIG (the oxpulse_imager_ prefix option
 * family + standalone keys — including key/salt/settings) ONLY when
 * the user opted in via the oxpulse_imager_remove_on_uninstall toggle
 * (default false = preserve for reinstall). Per-site cleanup on
 * multisite applies the same split.
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

// Best-effort teardown: if a src/ class is missing from the release
// ZIP, the autoloader fatals mid-cleanup. An uninstall must NEVER
// fatal — swallow Throwable so partial teardown completes without a
// white screen.
try {
    \OXPulse\Imager\Infrastructure\WordPress\Uninstaller::run();
} catch (\Throwable $e) {
    // Silent — best-effort. No error_log available reliably in all
    // uninstall contexts.
}
