<?php
/**
 * Settings page (React SPA shell) integration tests.
 *
 * Verifies that SettingsPage::register() registers the menu page and
 * enqueue hook, and that render() outputs the mount root div with
 * capability checking.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Integration;

use OXPulse\Imager\Integration\WordPress\Admin\SettingsPage;
use PHPUnit\Framework\TestCase;

class SettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_menu_pages'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_enqueued_scripts'] = [];
        $GLOBALS['__oxpulse_enqueued_styles'] = [];
        $GLOBALS['__oxpulse_localized'] = [];
        $GLOBALS['__oxpulse_inline_scripts'] = [];
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
        $page = new SettingsPage();
        $page->register();
        $this->fireHook('admin_menu');

        $menuPages = $GLOBALS['__oxpulse_menu_pages'] ?? [];
        $this->assertCount(1, $menuPages);
        $this->assertSame('OXPulse Imager', $menuPages[0]['pageTitle']);
        $this->assertSame(OXPULSE_IMAGER_CAPABILITY, $menuPages[0]['capability']);
        $this->assertSame(SettingsPage::PAGE_SLUG, $menuPages[0]['menuSlug']);
    }

    public function test_register_hooks_admin_enqueue_scripts(): void
    {
        $page = new SettingsPage();
        $page->register();

        $actions = $GLOBALS['__oxpulse_actions'] ?? [];
        $actionNames = array_column($actions, 'hook');
        $this->assertContains('admin_menu', $actionNames);
        $this->assertContains('admin_enqueue_scripts', $actionNames);
    }

    public function test_render_requires_capability(): void
    {
        $GLOBALS['__oxpulse_current_user_can'] = [];
        $page = new SettingsPage();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission');

        $page->render();
    }

    public function test_render_outputs_mount_root_when_authorized(): void
    {
        $GLOBALS['__oxpulse_current_user_can'] = [OXPULSE_IMAGER_CAPABILITY => true];
        $page = new SettingsPage();

        ob_start();
        try {
            $page->render();
        } finally {
            $output = ob_get_clean();
        }

        // The SPA mounts into this div — the whole page is just this
        // root + a screen-reader h1.
        $this->assertStringContainsString('id="oxpulse-admin-root"', $output);
        $this->assertStringContainsString('screen-reader-text', $output);
        // No form, no inputs — the SPA handles all UI.
        $this->assertStringNotContainsString('<form', $output);
        $this->assertStringNotContainsString('name="oxpulse_imager', $output);
    }
}
