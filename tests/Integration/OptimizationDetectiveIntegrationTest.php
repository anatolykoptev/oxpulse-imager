<?php
/**
 * OptimizationDetectiveIntegration tests.
 *
 * Tests the preconnect link output and the tag visitor logic without
 * a real Optimization Detective installation (stub classes).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Integration;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Integration\WordPress\Performance\OptimizationDetectiveIntegration;
use PHPUnit\Framework\TestCase;

class OptimizationDetectiveIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
        unset($GLOBALS['__oxpulse_actions']);
        parent::tearDown();
    }

    private function setupFullConfig(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_enabled'] = true;
        $GLOBALS['__oxpulse_options']['oxpulse_imager_endpoint'] = 'https://imgproxy.example.com';
        $GLOBALS['__oxpulse_options']['oxpulse_imager_allowed_sources'] = ['https://example.com/uploads/'];
        $GLOBALS['__oxpulse_options']['oxpulse_imager_key'] = bin2hex(random_bytes(16));
        $GLOBALS['__oxpulse_options']['oxpulse_imager_salt'] = bin2hex(random_bytes(16));
    }

    public function test_addPreconnect_outputs_link_when_enabled(): void
    {
        $this->setupFullConfig();
        $integration = new OptimizationDetectiveIntegration();

        ob_start();
        $integration->addPreconnect();
        $output = ob_get_clean();

        $this->assertStringContainsString('<link rel="preconnect"', $output);
        $this->assertStringContainsString('href="https://imgproxy.example.com"', $output);
        $this->assertStringContainsString('crossorigin="anonymous"', $output);
    }

    public function test_addPreconnect_outputs_nothing_when_disabled(): void
    {
        $integration = new OptimizationDetectiveIntegration();

        ob_start();
        $integration->addPreconnect();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function test_addPreconnect_outputs_nothing_when_no_endpoint(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_enabled'] = true;
        $integration = new OptimizationDetectiveIntegration();

        ob_start();
        $integration->addPreconnect();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function test_addPreconnect_extracts_host_from_endpoint(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_enabled'] = true;
        $GLOBALS['__oxpulse_options']['oxpulse_imager_endpoint'] = 'https://cdn.example.org:8080';
        $integration = new OptimizationDetectiveIntegration();

        ob_start();
        $integration->addPreconnect();
        $output = ob_get_clean();

        // Should preconnect to the host (without port in the preconnect href).
        $this->assertStringContainsString('cdn.example.org', $output);
    }

    public function test_register_hooks_wp_head(): void
    {
        $integration = new OptimizationDetectiveIntegration();
        $integration->register();

        // Verify wp_head action was registered.
        $found = false;
        foreach ($GLOBALS['__oxpulse_actions'] ?? [] as $action) {
            if ($action['hook'] === 'wp_head') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'wp_head action not registered.');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_registerTagVisitor_skips_when_image_prioritizer_active(): void
    {
        // Simulate Image Prioritizer being active by defining the class
        // in this isolated process.
        if (!class_exists('\Image_Prioritizer_Img_Tag_Visitor')) {
            eval('final class Image_Prioritizer_Img_Tag_Visitor {}');
        }

        $integration = new OptimizationDetectiveIntegration();

        // Use a stub registry that records registrations.
        $registry = new class {
            public array $registered = [];
            public function register(string $name, callable $visitor): void
            {
                $this->registered[$name] = $visitor;
            }
        };

        $integration->registerTagVisitor($registry);

        $this->assertArrayNotHasKey('oxpulse-imgproxy-img', $registry->registered);
    }

    public function test_registerTagVisitor_registers_when_image_prioritizer_not_active(): void
    {
        // In the test env, Image Prioritizer is NOT loaded.
        $this->assertFalse(
            class_exists('\Image_Prioritizer_Img_Tag_Visitor', false),
            'Image_Prioritizer_Img_Tag_Visitor should not be loaded in test env.'
        );

        $integration = new OptimizationDetectiveIntegration();

        $registry = new class {
            public array $registered = [];
            public function register(string $name, callable $visitor): void
            {
                $this->registered[$name] = $visitor;
            }
        };

        $integration->registerTagVisitor($registry);

        $this->assertArrayHasKey('oxpulse-imgproxy-img', $registry->registered);
        $this->assertSame($integration, $registry->registered['oxpulse-imgproxy-img']);
    }
}
