<?php
/**
 * Settings page registration integration tests.
 *
 * Verifies that SettingsPage::register() registers the menu page,
 * settings, and admin-post actions with the correct capability and
 * slugs. Uses the stub WordPress environment.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Integration;

use OXPulse\Imager\Application\Health\HealthCheckService;
use OXPulse\Imager\Infrastructure\Http\WordPressHealthClient;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\SettingsValidator;
use OXPulse\Imager\Integration\WordPress\Admin\SettingsController;
use OXPulse\Imager\Integration\WordPress\Admin\SettingsPage;
use PHPUnit\Framework\TestCase;

class SettingsPageTest extends TestCase
{
    private OptionSettingsRepository $repository;
    private SettingsValidator $validator;
    private SettingsController $controller;
    private SettingsPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_menu_pages'] = [];
        $GLOBALS['__oxpulse_registered_settings'] = [];
        $GLOBALS['__oxpulse_actions'] = [];

        $this->repository = new OptionSettingsRepository();
        $this->validator = new SettingsValidator();
        $healthCheck = new HealthCheckService(new WordPressHealthClient());
        $this->controller = new SettingsController($this->repository, $this->validator, $healthCheck);
        $this->page = new SettingsPage($this->repository, $this->validator, $this->controller);
    }

    /**
     * Fire all callbacks registered for a given hook via the stub add_action.
     */
    private function fireHook(string $hook): void
    {
        foreach ($GLOBALS['__oxpulse_actions'] ?? [] as $action) {
            if ($action['hook'] === $hook && is_callable($action['callback'])) {
                call_user_func($action['callback']);
            }
        }
    }

    public function test_register_adds_menu_page_with_capability(): void
    {
        $this->page->register();
        $this->fireHook('admin_menu');

        $menuPages = $GLOBALS['__oxpulse_menu_pages'] ?? [];
        $this->assertCount(1, $menuPages);
        $this->assertSame('OXPulse Imager', $menuPages[0]['pageTitle']);
        $this->assertSame(OXPULSE_IMAGER_CAPABILITY, $menuPages[0]['capability']);
        $this->assertSame(SettingsPage::PAGE_SLUG, $menuPages[0]['menuSlug']);
    }

    public function test_register_registers_enabled_and_endpoint_settings(): void
    {
        $this->page->register();
        $this->fireHook('admin_init');

        $settings = $GLOBALS['__oxpulse_registered_settings'][SettingsPage::OPTION_GROUP] ?? [];
        $this->assertArrayHasKey(OptionSettingsRepository::OPTION_ENABLED, $settings);
        $this->assertArrayHasKey(OptionSettingsRepository::OPTION_ENDPOINT, $settings);
    }

    public function test_register_hooks_admin_post_actions(): void
    {
        $this->page->register();

        $actions = $GLOBALS['__oxpulse_actions'] ?? [];
        $actionNames = array_column($actions, 'hook');
        $this->assertContains('admin_post_oxpulse_imager_save_settings', $actionNames);
        $this->assertContains('admin_post_oxpulse_imager_test_connection', $actionNames);
    }

    public function test_render_requires_capability(): void
    {
        $GLOBALS['__oxpulse_current_user_can'] = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission');

        $this->page->render();
    }

    public function test_render_outputs_form_when_authorized(): void
    {
        $GLOBALS['__oxpulse_current_user_can'] = [OXPULSE_IMAGER_CAPABILITY => true];

        ob_start();
        try {
            $this->page->render();
        } finally {
            $output = ob_get_clean();
        }

        $this->assertStringContainsString('oxpulse-imager-settings', $output);
        $this->assertStringContainsString('name="oxpulse_imager[endpoint]"', $output);
        // Secret inputs must be present but never prefilled — the value
        // attribute is always empty so secrets are not leaked back to the DOM.
        $this->assertStringContainsString('name="oxpulse_imager[key]"', $output);
        $this->assertStringContainsString('name="oxpulse_imager[salt]"', $output);
        // The status indicator must appear.
        $this->assertStringContainsString('oxpulse-status', $output);
    }

    public function test_render_shows_configured_status_when_secrets_present(): void
    {
        $GLOBALS['__oxpulse_current_user_can'] = [OXPULSE_IMAGER_CAPABILITY => true];
        $this->repository->saveSecrets(bin2hex(random_bytes(16)), bin2hex(random_bytes(16)));

        ob_start();
        try {
            $this->page->render();
        } finally {
            $output = ob_get_clean();
        }

        $this->assertStringContainsString('oxpulse-status-ok', $output);
        $this->assertStringContainsString('Secrets configured', $output);
    }

    public function test_render_shows_empty_status_when_no_secrets(): void
    {
        $GLOBALS['__oxpulse_current_user_can'] = [OXPULSE_IMAGER_CAPABILITY => true];

        ob_start();
        try {
            $this->page->render();
        } finally {
            $output = ob_get_clean();
        }

        $this->assertStringContainsString('oxpulse-status-empty', $output);
        $this->assertStringContainsString('No secrets configured', $output);
    }
}
