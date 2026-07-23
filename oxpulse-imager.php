<?php
/**
 * Plugin Name:       OXPulse Imager
 * Plugin URI:        https://github.com/anatolykoptev/oxpulse-imager
 * Description:       Optional bring-your-own imgproxy image delivery for WordPress. Generates signed, deterministic imgproxy URLs for approved local origins while preserving the original URL whenever configuration, source policy, signing, or delivery cannot safely proceed. Disabled by default; no SaaS, no FFI, no telemetry.
 * x-release-please-start-version
 * Version:           0.1.4
 * x-release-please-end
 * Requires at least: 6.7
 * Requires PHP:      8.3
 * Author:            Anatoly Koptev
 * Author URI:        https://anatolykoptev.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       oxpulse-imager
 * Domain Path:       /languages
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('OXPULSE_IMAGER_VERSION', '0.1.4'); // x-release-please-version
define('OXPULSE_IMAGER_FILE', __FILE__);
define('OXPULSE_IMAGER_DIR', plugin_dir_path(__FILE__));
define('OXPULSE_IMAGER_URL', plugin_dir_url(__FILE__));
define('OXPULSE_IMAGER_BASENAME', plugin_basename(__FILE__));
define('OXPULSE_IMAGER_OPTION_PREFIX', 'oxpulse_imager_');
define('OXPULSE_IMAGER_CAPABILITY', 'manage_oxpulse_imager');

/**
 * Runtime requirements guard.
 *
 * The plugin targets PHP 8.3+ and WordPress 6.2+. When the runtime does
 * not satisfy those requirements, register an admin-only notice and bail
 * before any service registration or output handling happens. This guard
 * never fatal-errors the site and never changes frontend output.
 *
 * @return bool True if the runtime is supported, false otherwise.
 */
function oxpulse_imager_runtime_supported(): bool {
    if (version_compare(PHP_VERSION, '8.3.0', '<')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html(sprintf(
                /* translators: %s: PHP version requirement. */
                __('OXPulse Imager requires PHP 8.3 or higher. You are running %s.', 'oxpulse-imager'),
                PHP_VERSION
            ));
            echo '</p></div>';
        });
        return false;
    }

    if (version_compare($GLOBALS['wp_version'] ?? '0.0.0', '6.2', '<')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html(sprintf(
                /* translators: %s: WordPress version requirement. */
                __('OXPulse Imager requires WordPress 6.2 or higher. You are running %s.', 'oxpulse-imager'),
                $GLOBALS['wp_version'] ?? 'unknown'
            ));
            echo '</p></div>';
        });
        return false;
    }

    return true;
}

/**
 * Grant the `manage_oxpulse_imager` capability to the administrator role.
 *
 * Idempotent — safe to call on every activation and on every load via
 * the self-heal check in Plugin::load(). Uses get_role/add_cap so it
 * works on multisite (per-site admins) and single site alike.
 */
function oxpulse_imager_grant_capability(): void {
    $role = get_role('administrator');
    if ($role instanceof WP_Role && !$role->has_cap(OXPULSE_IMAGER_CAPABILITY)) {
        $role->add_cap(OXPULSE_IMAGER_CAPABILITY);
    }
}

/**
 * Activation hook.
 *
 * Registers disabled default options and grants the plugin capability to
 * administrators. Never mutates media, attachments, post content, or
 * external systems. The plugin must remain a no-op on the frontend until
 * an administrator explicitly enables delivery.
 */
function oxpulse_imager_activate(): void {
    $defaults = [
        OXPULSE_IMAGER_OPTION_PREFIX . 'enabled' => false,
        OXPULSE_IMAGER_OPTION_PREFIX . 'endpoint' => '',
        OXPULSE_IMAGER_OPTION_PREFIX . 'allowed_sources' => [],
        OXPULSE_IMAGER_OPTION_PREFIX . 'remove_on_uninstall' => false,
        OXPULSE_IMAGER_OPTION_PREFIX . 'diagnostic_level' => 'off',
        OXPULSE_IMAGER_OPTION_PREFIX . 'schema_version' => 1,
        // Phase 5.5: onboarding wizard flag. False on activation →
        // the SPA shows the wizard. Set to true by POST /onboarding/complete
        // or by the "Skip" link. Re-activation does NOT reset this —
        // only a fresh install (no option in DB) gets the wizard.
        OXPULSE_IMAGER_OPTION_PREFIX . 'onboarded' => false,
    ];

    foreach ($defaults as $key => $value) {
        if (get_option($key, null) === null) {
            add_option($key, $value, '', false);
        }
    }

    oxpulse_imager_grant_capability();

    // Phase 6 Dispatch 3: generate the LocalBackend miss-endpoint +
    // cache .htaccess when LocalBackend is active. No-op at fresh
    // activation (delivery disabled, no signing secrets yet) — the
    // real generation fires on settings-save once the operator
    // configures secrets without an imgproxy endpoint.
    if (class_exists(\OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar::class)) {
        \OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar::installLocalDelivery();
        // #43 Phase 1 review (BLOCKER wire): probe rewrite capability
        // at activation when LocalBackend is active (endpoint empty).
        // recheckRewriteCapability() is a no-op when an imgproxy
        // endpoint is configured. The 3s HTTP round-trip is acceptable
        // in the activation context — never on the front-end read path.
        \OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar::recheckRewriteCapability();
        // Delivery backend registry: probe imgproxy health at activation
        // when ImgproxyBackend is active (endpoint set). The COMPLEMENT
        // of recheckRewriteCapability() — exactly one fires for the
        // current endpoint state. recheckImgproxyHealth() is a no-op
        // when LocalBackend is active. The bounded 2s HEAD is acceptable
        // in the activation context — never on the front-end read path.
        \OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar::recheckImgproxyHealth();
    }
}

/**
 * Deactivation hook.
 *
 * Clears only ephemeral transients if any. Never deletes settings, media,
 * attachments, post content, or external state.
 */
function oxpulse_imager_deactivate(): void {
    delete_transient(OXPULSE_IMAGER_OPTION_PREFIX . 'health_check');

    // Phase 6 Dispatch 3: remove the generated LocalBackend miss-endpoint
    // + cache .htaccess so they don't go stale while the plugin is
    // inactive. No-op when ImgproxyBackend was active (nothing was
    // generated). Settings are preserved (not deleted).
    if (class_exists(\OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar::class)) {
        \OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar::uninstallLocalDelivery();
    }
}

register_activation_hook(__FILE__, 'oxpulse_imager_activate');
register_deactivation_hook(__FILE__, 'oxpulse_imager_deactivate');

if (!oxpulse_imager_runtime_supported()) {
    return;
}

require_once OXPULSE_IMAGER_DIR . 'src/Plugin.php';

// Self-heal: grant the capability on every load if missing. This handles
// installs that activated an earlier version of the plugin (before the
// activation hook granted the capability) without requiring a
// deactivate/reactivate cycle. Idempotent — add_cap is a no-op when the
// capability already exists.
oxpulse_imager_grant_capability();

\OXPulse\Imager\Plugin::load(OXPULSE_IMAGER_FILE);

/**
 * Public helper: generate a signed imgproxy URL for an image.
 *
 * Drop-in replacement for the mu-plugin's Imgproxy_AVIF::thumb_url()
 * static method. Sibling mu-plugins (e.g. piter-api on piter.now) call
 * this for card thumbnails:
 *
 *     $url = oxpulse_thumb_url($imageUrl, 330, 220);
 *
 * Fail-safe: returns the original URL when delivery is disabled, the
 * plugin is not yet initialized (called before plugins_loaded), no
 * signing config, source not allowed, or any other denial reason. This
 * matches the mu-plugin's behavior when IMGPROXY_KEY was not defined.
 *
 * @param string $url Source image URL.
 * @param int $width Target width in pixels (0 = auto).
 * @param int $height Target height in pixels (0 = auto).
 * @return string Signed imgproxy URL, or original URL on any failure.
 */
if (!function_exists('oxpulse_thumb_url')) {
    function oxpulse_thumb_url(string $url, int $width, int $height): string {
        $rewriter = \OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar::getRewriter();
        if ($rewriter === null) {
            return $url;
        }
        $result = $rewriter->rewrite($url, $width, $height, 'thumb_url');
        return $result->url;
    }
}
