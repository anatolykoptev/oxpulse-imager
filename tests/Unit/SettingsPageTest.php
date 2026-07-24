<?php
/**
 * SettingsPage unit tests.
 *
 * #90: verifies the one-line active delivery-path readout shows the
 * correct label for imgproxy / LocalBackend clean-URL / LocalBackend ?k=
 * fallback / passthrough states.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Image\ImageTransformer;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use OXPulse\Imager\Infrastructure\Local\CapabilityTester;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Integration\WordPress\Admin\SettingsPage;
use PHPUnit\Framework\TestCase;

class SettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_is_multisite'] = false;

        // These tests exercise the detailed delivery-status readout
        // (a Pro feature since the Gate 5 admin-status gate replaces it
        // with a basic line under free). Opt into Pro so the detailed
        // labels are produced by buildDeliveryStatusLine().
        add_filter('oxpulse_is_pro', '__return_true');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options'], $GLOBALS['__oxpulse_filters'], $GLOBALS['__oxpulse_is_multisite']);
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

    private function noEncoderTransformer(): ImageTransformer
    {
        return new class extends ImageTransformer {
            public function supportsWebp(): bool { return false; }
            public function supportsAvif(): bool { return false; }
        };
    }

    /**
     * #90: imgproxy up + outputFormat 'auto' → AVIF-via-Accept label.
     */
    public function test_status_line_imgproxy_avif_via_accept(): void
    {
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        update_option(ImgproxyHealthCache::OPTION, 'up');

        $page = new SettingsPage();
        $label = $page->buildDeliveryStatusLine();

        $this->assertStringContainsString('Active delivery:', $label);
        $this->assertStringContainsString('imgproxy', $label);
        $this->assertStringContainsString('AVIF via Accept', $label);
    }

    /**
     * #90: imgproxy up + outputFormat 'webp' → explicit WebP label.
     */
    public function test_status_line_imgproxy_webp_when_output_format_webp(): void
    {
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        update_option(OptionSettingsRepository::OPTION_OUTPUT_FORMAT, 'webp');
        update_option(ImgproxyHealthCache::OPTION, 'up');

        $page = new SettingsPage();
        $label = $page->buildDeliveryStatusLine();

        $this->assertStringContainsString('Active delivery:', $label);
        $this->assertStringContainsString('imgproxy', $label);
        $this->assertStringContainsString('(WebP)', $label);
        $this->assertStringNotContainsString('AVIF', $label);
    }

    /**
     * #90: LocalBackend active, rewrite available, encoder present.
     */
    public function test_status_line_local_clean_url(): void
    {
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'yes');

        $page = new SettingsPage(null, $this->webpTransformer());
        $label = $page->buildDeliveryStatusLine();

        $this->assertStringContainsString('Active delivery:', $label);
        $this->assertStringContainsString('LocalBackend clean-URL', $label);
        $this->assertStringContainsString('.webp/.avif', $label);
    }

    /**
     * #90: LocalBackend active, encoder present, but rewrite NOT available.
     */
    public function test_status_line_local_fallback(): void
    {
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'no');

        $page = new SettingsPage(null, $this->webpTransformer());
        $label = $page->buildDeliveryStatusLine();

        $this->assertStringContainsString('Active delivery:', $label);
        $this->assertStringContainsString('LocalBackend ?k= fallback', $label);
    }

    /**
     * #90: no endpoint, no signing, no imgproxy → passthrough.
     */
    public function test_status_line_passthrough_no_optimization(): void
    {
        $page = new SettingsPage(null, $this->webpTransformer());
        $label = $page->buildDeliveryStatusLine();

        $this->assertStringContainsString('Active delivery:', $label);
        $this->assertStringContainsString('Passthrough (no optimization)', $label);
    }

    /**
     * #90: imgproxy configured but down, signing+encoder present, rewrite no.
     */
    public function test_status_line_imgproxy_down_uses_local_fallback(): void
    {
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        update_option(ImgproxyHealthCache::OPTION, 'down');
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'no');

        $page = new SettingsPage(null, $this->webpTransformer());
        $label = $page->buildDeliveryStatusLine();

        $this->assertStringContainsString('Active delivery:', $label);
        $this->assertStringContainsString('LocalBackend ?k= fallback', $label);
    }

    /**
     * #90: imgproxy down and no local encoder → passthrough floor.
     */
    public function test_status_line_imgproxy_down_passthrough_when_no_encoder(): void
    {
        $this->signingOptions();
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        update_option(ImgproxyHealthCache::OPTION, 'down');

        $page = new SettingsPage(null, $this->noEncoderTransformer());
        $label = $page->buildDeliveryStatusLine();

        $this->assertStringContainsString('Active delivery:', $label);
        $this->assertStringContainsString('Passthrough (no optimization)', $label);
    }

    /**
     * #90: render() includes the active delivery status line.
     */
    public function test_render_outputs_active_delivery_status_line(): void
    {
        $GLOBALS['__oxpulse_current_user_can'] = ['manage_oxpulse_imager' => true];
        $page = new SettingsPage();

        ob_start();
        $page->render();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('oxpulse-delivery-status', $out);
        $this->assertStringContainsString('Active delivery:', $out);
    }
}
