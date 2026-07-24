<?php
/**
 * Gate 5 — admin status dashboard feature gate tests.
 *
 * Verifies the admin delivery-status readout Pro gate:
 * - Under Pro, buildDeliveryStatusLine() shows the detailed label
 *   (unchanged behavior).
 * - Under free, the detailed status line is replaced with a basic
 *   line — the settings page still renders + works; only the detailed
 *   status is Pro. This is cosmetic — must not break the page.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Image\ImageTransformer;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Integration\WordPress\Admin\SettingsPage;
use PHPUnit\Framework\TestCase;

class FeatureGateAdminStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_is_multisite'] = false;
        $GLOBALS['__oxpulse_fs_stub'] = null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_filters'],
            $GLOBALS['__oxpulse_is_multisite'],
            $GLOBALS['__oxpulse_fs_stub']
        );
    }

    private function signingOptions(): void
    {
        update_option(OptionSettingsRepository::OPTION_KEY, str_repeat('a', 64));
        update_option(OptionSettingsRepository::OPTION_SALT, str_repeat('b', 64));
    }

    private function webpTransformer(): ImageTransformer
    {
        return new class extends ImageTransformer {
            public function supportsWebp(): bool { return true; }
            public function supportsAvif(): bool { return false; }
        };
    }

    // ─── Pro: detailed status (unchanged) ────────────────────────────

    public function test_pro_shows_detailed_imgproxy_status(): void
    {
        add_filter('oxpulse_is_pro', '__return_true');
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        update_option(ImgproxyHealthCache::OPTION, 'up');

        $page = new SettingsPage();
        $label = $page->buildDeliveryStatusLine();

        $this->assertStringContainsString('imgproxy', $label);
        $this->assertStringContainsString('AVIF', $label);
    }

    public function test_pro_shows_detailed_local_status(): void
    {
        add_filter('oxpulse_is_pro', '__return_true');
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'yes');

        $page = new SettingsPage(null, $this->webpTransformer());
        $label = $page->buildDeliveryStatusLine();

        $this->assertStringContainsString('LocalBackend', $label);
    }

    // ─── Free: basic line, no detailed diagnostics ───────────────────

    public function test_free_hides_detailed_imgproxy_status(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        update_option(ImgproxyHealthCache::OPTION, 'up');

        $page = new SettingsPage();
        $label = $page->buildDeliveryStatusLine();

        $this->assertStringNotContainsString('imgproxy', $label, 'Free must not show the detailed imgproxy status');
        $this->assertStringNotContainsString('AVIF', $label, 'Free must not show the detailed AVIF status');
        $this->assertStringContainsString('Active delivery:', $label, 'Free status line keeps the basic prefix');
    }

    public function test_free_hides_detailed_local_status(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'yes');

        $page = new SettingsPage(null, $this->webpTransformer());
        $label = $page->buildDeliveryStatusLine();

        $this->assertStringNotContainsString('LocalBackend', $label, 'Free must not show the detailed LocalBackend status');
        $this->assertStringContainsString('Active delivery:', $label);
    }

    public function test_free_settings_page_still_renders(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        $GLOBALS['__oxpulse_current_user_can'] = ['manage_oxpulse_imager' => true];

        $page = new SettingsPage();
        ob_start();
        $page->render();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('oxpulse-admin-root', $out, 'Settings page must still render under free');
        $this->assertStringContainsString('Active delivery:', $out, 'Basic status line must render under free');
    }
}
