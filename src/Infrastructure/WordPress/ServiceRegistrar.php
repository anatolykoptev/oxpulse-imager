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

use OXPulse\Imager\Application\Health\HealthCheckService;
use OXPulse\Imager\Infrastructure\Http\WordPressHealthClient;
use OXPulse\Imager\Integration\WordPress\Admin\SettingsController;
use OXPulse\Imager\Integration\WordPress\Admin\SettingsPage;
use OXPulse\Imager\Plugin;

final class ServiceRegistrar
{
    public static function register(Plugin $plugin): void
    {
        self::registerTextDomain($plugin);
        self::registerHealthGate($plugin);
        self::registerAdminSettings($plugin);
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

    /**
     * Register the admin settings page, controller, and health check
     * service. Only wired when is_admin() is true so the frontend never
     * loads admin dependencies.
     */
    private static function registerAdminSettings(Plugin $plugin): void
    {
        if (!is_admin()) {
            return;
        }

        $repository = new OptionSettingsRepository();
        $validator = new SettingsValidator();
        $healthClient = new WordPressHealthClient();
        $healthCheck = new HealthCheckService($healthClient);
        $controller = new SettingsController($repository, $validator, $healthCheck);
        $page = new SettingsPage($repository, $validator, $controller);

        $page->register();
    }

    private static function deliveryEnabled(): bool
    {
        return (bool) get_option(OXPULSE_IMAGER_OPTION_PREFIX . 'enabled', false);
    }
}
