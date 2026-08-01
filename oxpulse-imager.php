<?php
/**
 * Plugin Name:       OXPulse Imager
 * Plugin URI:        https://github.com/anatolykoptev/oxpulse-imager
 * Description:       Optional bring-your-own imgproxy image delivery for WordPress. Generates signed, deterministic imgproxy URLs for approved local origins while preserving the original URL whenever configuration, source policy, signing, or delivery cannot safely proceed. Disabled by default; no SaaS, no FFI, no telemetry.
 * x-release-please-start-version
 * Version:           0.1.6
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

define('OXPULSE_IMAGER_VERSION', '0.1.6'); // x-release-please-version
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
    // FIX 2: capture pre-activation state BEFORE any add_option so the
    // born_version sentinel is set ONLY on a TRUE fresh install. A
    // pre-Freemius install that is upgraded then deactivated +
    // reactivated (with no admin page load between) still has its
    // prior-install markers in the DB → NOT fresh → born_version must
    // NOT be set, otherwise the grandfather detector sees the sentinel
    // and refuses to grandfather → the existing free user loses working
    // features once Phase-B gating lands. null default distinguishes
    // "not set" from "set to false" (onboarded is stored as false).
    $isFreshInstall = get_option(OXPULSE_IMAGER_OPTION_PREFIX . 'schema_version', null) === null
        && get_option(OXPULSE_IMAGER_OPTION_PREFIX . 'onboarded', null) === null;

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

    // #91: hot render-path options (read on every page load via
    // OptionSettingsRepository::loadDeliveryConfig / the diagnostic
    // logger) are stored autoload=yes so they are served by
    // wp_load_alloptions()'s single bootstrap query instead of N
    // separate SELECTs on sites without a persistent object cache.
    // Write-only / admin-only / uninstall-only options stay
    // autoload=no to keep the autoload set lean.
    $autoloadKeys = [
        OXPULSE_IMAGER_OPTION_PREFIX . 'enabled',
        OXPULSE_IMAGER_OPTION_PREFIX . 'endpoint',
        OXPULSE_IMAGER_OPTION_PREFIX . 'allowed_sources',
        OXPULSE_IMAGER_OPTION_PREFIX . 'diagnostic_level',
    ];

    foreach ($defaults as $key => $value) {
        if (get_option($key, null) === null) {
            add_option($key, $value, '', in_array($key, $autoloadKeys, true));
        }
    }

    // Freemius integration: sentinel marking "born on the Freemius
    // version". Set on fresh activation ONLY (add_option skips when the
    // option already exists). The grandfather detector uses its ABSENCE
    // to identify pre-Freemius installs upgrading to this version. A
    // fresh install on this version must NOT be grandfathered; an
    // upgrade from a pre-Freemius version (which never set this
    // sentinel) must be. FIX 2: gated on $isFreshInstall so a
    // deactivated+reactivated pre-Freemius install does not get the
    // sentinel set on reactivation (which would block grandfathering).
    if ($isFreshInstall && get_option('oxpulse_born_version', null) === null) {
        add_option('oxpulse_born_version', OXPULSE_IMAGER_VERSION, '', 'no');
    }

    // FIX 2: seed the AVIF-baked-Pro drift sentinel at activation so
    // maybeRebakeAvifOnLicenseChange has a baseline to compare against
    // on the first admin load. The activation hook's
    // installLocalDelivery() call (below) bakes OXPULSE_AVIF_ALLOWED
    // from isPro() at activation time, so the sentinel must reflect
    // that same isPro() state. add_option skips when the option
    // already exists (preserves the value across deactivate→reactivate
    // — a re-activation must NOT reset the drift baseline, otherwise
    // a Pro→free change made while inactive would be silently "healed"
    // back to the stale value on re-activation).
    if (get_option('oxpulse_avif_baked_pro', null) === null) {
        $proAtActivation = class_exists(\OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar::class)
            ? \OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar::isPro()
            : false;
        add_option('oxpulse_avif_baked_pro', $proAtActivation, '', 'no');
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
        // Social-jpeg capability probe: same trigger as imgproxy health.
        // Self-guarding via isLocalBackendActive() — fires only when
        // imgproxy is active (endpoint set). The bounded 5s GET is
        // acceptable in the activation context — never on the front-end.
        \OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar::recheckSocialJpegCapability();
    }

    // #81: schedule the recurring imgproxy health re-probe cron so a
    // recovered imgproxy is re-promoted (down→up) and a newly-dead one
    // is detected (up→down) without waiting for a settings-save. The
    // persistent option (ImgproxyHealthCache) guarantees safety; the
    // cron only bounds recovery/re-detection latency. WP-cron is
    // traffic-triggered, so this is best-effort timing.
    if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')) {
        if (!wp_next_scheduled('oxpulse_imgproxy_health_recheck')) {
            wp_schedule_event(time(), 'hourly', 'oxpulse_imgproxy_health_recheck');
        }
        // #93: schedule the recurring LocalBackend cache LRU eviction
        // cron so the on-disk cache is bounded by cache_max_mb. No-op
        // when ImgproxyBackend is active (runCacheCleanup self-guards).
        // The init self-heal in ServiceRegistrar covers the upgrade path.
        if (!wp_next_scheduled('oxpulse_cache_cleanup')) {
            wp_schedule_event(time(), 'twicedaily', 'oxpulse_cache_cleanup');
        }
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

    // #81: clear the recurring imgproxy health re-probe cron so it
    // does not fire while the plugin is inactive. The persistent
    // health option is PRESERVED (not deleted) — a re-activation
    // picks up where it left off.
    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('oxpulse_imgproxy_health_recheck');
        // #93: clear the recurring LocalBackend cache LRU eviction cron.
        wp_clear_scheduled_hook('oxpulse_cache_cleanup');
    }
}

register_activation_hook(__FILE__, 'oxpulse_imager_activate');
register_deactivation_hook(__FILE__, 'oxpulse_imager_deactivate');

if (!oxpulse_imager_runtime_supported()) {
    return;
}

/**
 * Freemius WordPress SDK initialization.
 *
 * Loads the bundled SDK (freemius/start.php) and initializes the
 * Freemius instance with the plugin's public credentials. The SDK
 * must load BEFORE src/Plugin.php / Plugin::load() so Freemius hooks
 * (opt-in screens, license sync, upgrade prompts) are registered
 * before any plugin service wiring.
 *
 * Credentials are PUBLIC (id + public_key) — there is no secret_key
 * in the plugin-side SDK init. The secret_key lives only on the
 * Freemius dashboard/server and is never shipped in the plugin.
 *
 * The `oxpulse_fs()` function is the single accessor for the Freemius
 * instance. All callers MUST guard with function_exists('oxpulse_fs')
 * in case the SDK failed to load. The FreemiusLicenseGate uses this
 * accessor to check can_use_premium_code().
 */
if (!function_exists('oxpulse_fs')) {
    function oxpulse_fs()
    {
        global $oxpulse_fs;

        if (!isset($oxpulse_fs)) {
            // FIX 1: WSOD guard. If a deploy/ZIP ships without the
            // bundled freemius/ directory, require_once would fatal the
            // site on every request. Degrade to the free tier instead:
            // mark a tried-and-missing sentinel (false) and return null.
            // Subsequent calls return null without re-running the init.
            $sdk = dirname(__FILE__) . '/freemius/start.php';
            if (!file_exists($sdk)) {
                $oxpulse_fs = false;
                return null;
            }

            require_once $sdk;

            $oxpulse_fs = fs_dynamic_init([
                'id'                  => '35418',
                'slug'                => 'oxpulse-imager',
                'type'                => 'plugin',
                'public_key'          => 'pk_8e7a13516790ff0fb71ed6a16a3b2',
                'is_premium'          => false,
                'is_premium_only'     => false,
                'has_paid_plans'      => true,
                'has_premium_version' => true,
                'is_org_compliant'    => true,
                'has_addons'          => false,
                'premium_suffix'      => 'Pro',
                'trial'               => [
                    'days'               => 14,
                    'is_require_payment' => false,
                ],
                'menu'                => [
                    'slug'   => 'oxpulse-imager',
                    'parent' => [
                        'slug' => 'options-general.php',
                    ],
                    'support' => false,
                ],
            ]);
        }

        // Memo: return null (not false) when the SDK is missing so
        // callers can null-guard uniformly (FreemiusLicenseGate::isPro
        // already does: $fs !== null && $fs->can_use_premium_code()).
        return $oxpulse_fs === false ? null : $oxpulse_fs;
    }

    // FIX 1: only signal "loaded" when the SDK actually initialized.
    // A missing SDK degrades to free tier silently — no loaded signal.
    if (oxpulse_fs() !== null) {
        do_action('oxpulse_fs_loaded');
    }
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

/**
 * Public helper: resolve the shared LicenseGate.
 *
 * #89: the single seam through which the plugin asks "is this a paying
 * (Pro) customer?". Returns the FreemiusLicenseGate (backed by the
 * Freemius SDK + grandfather flag) wired in ServiceRegistrar. Sibling
 * code and tests use this instead of touching ServiceRegistrar directly,
 * mirroring the oxpulse_thumb_url() helper shape.
 *
 * @return \OXPulse\Imager\Domain\License\LicenseGate
 */
if (!function_exists('oxpulse_license_gate')) {
    function oxpulse_license_gate(): \OXPulse\Imager\Domain\License\LicenseGate {
        return \OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar::licenseGate();
    }
}
