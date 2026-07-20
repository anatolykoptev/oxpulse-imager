<?php
/**
 * Settings page registration.
 *
 * Registers the OXPulse Imager settings page under Settings > OXPulse Imager.
 * Uses the WordPress Settings API for safe, capability-checked configuration.
 *
 * @package OXPulse\Imager\Integration\WordPress\Admin
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Admin;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\SettingsValidator;

final class SettingsPage
{
    public const PAGE_SLUG = 'oxpulse-imager';
    public const NONCE_ACTION = 'oxpulse_imager_settings';
    public const OPTION_GROUP = 'oxpulse_imager_settings_group';

    private OptionSettingsRepository $repository;
    private SettingsValidator $validator;
    private SettingsController $controller;

    public function __construct(
        OptionSettingsRepository $repository,
        SettingsValidator $validator,
        SettingsController $controller
    ) {
        $this->repository = $repository;
        $this->validator = $validator;
        $this->controller = $controller;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_oxpulse_imager_save_settings', [$this->controller, 'handleSave']);
        add_action('admin_post_oxpulse_imager_test_connection', [$this->controller, 'handleTestConnection']);
    }

    public function addMenuPage(): void
    {
        add_options_page(
            __('OXPulse Imager', 'oxpulse-imager'),
            __('OXPulse Imager', 'oxpulse-imager'),
            OXPULSE_IMAGER_CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function registerSettings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            OptionSettingsRepository::OPTION_ENABLED,
            ['type' => 'boolean', 'default' => false]
        );
        register_setting(
            self::OPTION_GROUP,
            OptionSettingsRepository::OPTION_ENDPOINT,
            ['type' => 'string', 'default' => '']
        );
    }

    public function render(): void
    {
        if (!current_user_can(OXPULSE_IMAGER_CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'oxpulse-imager'), 403);
        }

        $delivery = $this->repository->loadDeliveryConfig();
        $secretStatus = $this->repository->secretStatus();
        $sources = $delivery->allowedSources;

        require dirname(__DIR__, 4) . '/views/admin/settings-page.php';
    }
}
