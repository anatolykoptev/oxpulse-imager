<?php
/**
 * Service registrar.
 *
 * Registers WordPress hooks only in appropriate contexts. Keeps side
 * effects at hook-registration time and makes dependencies injectable
 * for tests. The plugin remains a frontend no-op while delivery is
 * disabled.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\WordPress;

use OXPulse\Imager\Plugin;

final class ServiceRegistrar
{
    public static function register(Plugin $plugin): void
    {
        self::registerTextDomain($plugin);
        self::registerHealthGate($plugin);
        // Settings, source policy, signing, delivery, and WordPress hook
        // adapters are added in later phases. Phase 0 only ships the
        // inert bootstrap, lifecycle hooks, and admin compatibility notices.
    }

    private static function registerTextDomain(Plugin $plugin): void
    {
        add_action('init', static function () use ($plugin): void {
            load_plugin_textdomain(
                'oxpulse-imager',
                false,
                dirname($plugin->basename()) . '/languages'
            );
        });
    }

    /**
     * Frontend health gate. While delivery is disabled (default state),
     * no URL-rewriting hooks are registered. This is the primary safety
     * invariant of Phase 0 and remains the rollback path later.
     */
    private static function registerHealthGate(Plugin $plugin): void
    {
        add_action('plugins_loaded', static function (): void {
            if (self::deliveryEnabled()) {
                // Future phases will register image_downsize/srcset/
                // wp_content_img_tag adapters only here, after strict
                // source-policy and signing availability checks pass.
                return;
            }
        });
    }

    private static function deliveryEnabled(): bool
    {
        return (bool) get_option(OXPULSE_IMAGER_OPTION_PREFIX . 'enabled', false);
    }
}
