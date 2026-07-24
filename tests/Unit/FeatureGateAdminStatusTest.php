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

    // ─── Free: honest basic line, no detailed diagnostics ───────────

    /**
     * FIX 4: under free + imgproxy configured + imgproxy healthy,
     * the status line must honestly say "imgproxy" (not the dishonest
     * "active" — the backend IS imgproxy). But no detailed diagnostics
     * (AVIF/WebP format) — those are Pro.
     */
    public function test_free_hides_detailed_imgproxy_status(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        update_option(ImgproxyHealthCache::OPTION, 'up');

        $page = new SettingsPage();
        $label = $page->buildDeliveryStatusLine();

        // FIX 4: honest — says "imgproxy" (the actual backend).
        $this->assertStringContainsString('imgproxy', $label, 'FIX 4: free status line must honestly reflect the imgproxy backend');
        // No detailed diagnostics (AVIF/WebP format) — those are Pro.
        $this->assertStringNotContainsString('AVIF', $label, 'Free must not show the detailed AVIF status');
        $this->assertStringNotContainsString('WebP', $label, 'Free must not show the detailed WebP format status');
        $this->assertStringContainsString('Active delivery:', $label, 'Free status line keeps the basic prefix');
    }

    /**
     * FIX 4: under free + LocalBackend active (endpoint empty, encoder
     * present), the status line must honestly say "local (WebP)" (not
     * the dishonest "active"). No detailed diagnostics (clean-URL/?k=)
     * — those are Pro.
     */
    public function test_free_hides_detailed_local_status(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'yes');

        $page = new SettingsPage(null, $this->webpTransformer());
        $label = $page->buildDeliveryStatusLine();

        // FIX 4: honest — says "local (WebP)" (the actual backend).
        $this->assertStringContainsString('local', $label, 'FIX 4: free status line must honestly reflect the LocalBackend');
        // No detailed diagnostics (clean-URL/?k=) — those are Pro.
        $this->assertStringNotContainsString('LocalBackend', $label, 'Free must not show the detailed LocalBackend status');
        $this->assertStringNotContainsString('clean-URL', $label);
        $this->assertStringNotContainsString('?k=', $label);
        $this->assertStringContainsString('Active delivery:', $label);
    }

    /**
     * FIX 4: under free + no signing (nothing configured), the status
     * line must honestly say "passthrough (no optimization)" — NOT the
     * dishonest "active" (delivery is NOT active, URLs are preserved).
     */
    public function test_free_passthrough_honestly_says_passthrough(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        // No signing options → signing=null → select() returns null
        // (passthrough). The old code returned "Active delivery: active"
        // which was a lie — delivery is NOT active.

        $page = new SettingsPage();
        $label = $page->buildDeliveryStatusLine();

        $this->assertStringContainsString('passthrough', $label, 'FIX 4: free + no signing must honestly say passthrough');
        $this->assertStringContainsString('no optimization', $label);
        // The old code returned "Active delivery: active" — a lie.
        // The honest line says "passthrough", not "active" as the
        // backend label. Check the suffix (after "Active delivery:").
        $suffix = trim(substr($label, strlen('Active delivery:')));
        $this->assertStringNotContainsString('active', strtolower($suffix), 'FIX 4: must NOT say "active" as the backend when delivery is passthrough');
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
