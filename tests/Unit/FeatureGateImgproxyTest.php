<?php
/**
 * Gate 2 — imgproxy delivery feature gate tests.
 *
 * Verifies the imgproxy delivery backend is NOT selectable under free:
 * the oxpulse_delivery_backends filter (registered at bootstrap by
 * ServiceRegistrar) strips the ImgproxyBackendProvider when !isPro(),
 * so the registry falls through to LocalBackend / Passthrough —
 * delivery never breaks.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\DeliveryBackendRegistry;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackendProvider;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use PHPUnit\Framework\TestCase;

class FeatureGateImgproxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_fs_stub'] = null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_filters'],
            $GLOBALS['__oxpulse_fs_stub']
        );
    }

    private function signing(): SigningConfig
    {
        return SigningConfig::fromHex(str_repeat('a', 64), str_repeat('b', 64));
    }

    private function imgproxyConfig(): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: [],
            outputFormat: 'auto',
            defaultQuality: 80,
            devHttpOverride: false,
            lqipEnabled: false,
            lqipBlur: 1,
            dprEnabled: false,
            dprVariants: [],
            watermark: null,
            formatQuality: [],
            sourceMode: 'http',
            localBasePath: '',
            bufferRewritingEnabled: false,
            pictureEnabled: false,
            rankMathCompatibility: true,
            saveDataQualityReduction: 15,
            sizeQualityTiers: [],
        );
    }

    /**
     * Register ONLY the imgproxy-delivery gate filter (the bootstrap
     * piece that strips ImgproxyBackendProvider under free). Mirrors
     * what ServiceRegistrar::register() wires in production without
     * pulling in the full bootstrap.
     */
    private function registerGateFilter(): void
    {
        $ref = new \ReflectionMethod(ServiceRegistrar::class, 'registerImgproxyDeliveryGate');
        $ref->setAccessible(true);
        $ref->invoke(null);
    }

    // ─── Pro: imgproxy selectable (unchanged) ────────────────────────

    public function test_pro_imgproxy_provider_present_in_registry(): void
    {
        add_filter('oxpulse_is_pro', '__return_true');
        $this->registerGateFilter();

        $registry = DeliveryBackendRegistry::default($this->imgproxyConfig(), $this->signing());
        $hasImgproxy = false;
        $ref = new \ReflectionProperty($registry, 'providers');
        $ref->setAccessible(true);
        foreach ($ref->getValue($registry) as $p) {
            if ($p instanceof ImgproxyBackendProvider) {
                $hasImgproxy = true;
                break;
            }
        }
        $this->assertTrue($hasImgproxy, 'Pro must keep the imgproxy provider registered');
    }

    // ─── Free: imgproxy NOT selectable ───────────────────────────────

    public function test_free_strips_imgproxy_provider_from_registry(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        $this->registerGateFilter();

        $registry = DeliveryBackendRegistry::default($this->imgproxyConfig(), $this->signing());
        $ref = new \ReflectionProperty($registry, 'providers');
        $ref->setAccessible(true);
        foreach ($ref->getValue($registry) as $p) {
            $this->assertNotInstanceOf(
                ImgproxyBackendProvider::class,
                $p,
                'Free must NOT register the imgproxy delivery backend provider',
            );
        }
    }
}
