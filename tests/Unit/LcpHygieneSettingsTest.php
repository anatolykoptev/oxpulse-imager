<?php
/**
 * LCP tag-hygiene option wiring: DeliveryConfig, OptionSettingsRepository,
 * SettingsValidator, OptionsMapper and the ServiceRegistrar call site.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use OXPulse\Imager\Infrastructure\WordPress\SettingsValidator;
use OXPulse\Imager\Integration\WordPress\Admin\OptionsMapper;
use OXPulse\Imager\Integration\WordPress\Delivery\LcpTagHygiene;
use PHPUnit\Framework\TestCase;

class LcpHygieneSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options'], $GLOBALS['__oxpulse_actions'], $GLOBALS['__oxpulse_filters']);
        parent::tearDown();
    }

    public function test_default_is_off(): void
    {
        $this->assertFalse((new DeliveryConfig(true, 'https://e.test', []))->lcpHygieneEnabled);
        $this->assertFalse((new OptionSettingsRepository())->loadDeliveryConfig()->lcpHygieneEnabled);
    }

    public function test_with_endpoint_preserves_the_flag(): void
    {
        $config = new DeliveryConfig(true, 'https://e.test', [], lcpHygieneEnabled: true);
        $this->assertTrue($config->withEndpoint('https://other.test')->lcpHygieneEnabled);
    }

    public function test_repository_round_trip(): void
    {
        $repo = new OptionSettingsRepository();
        $repo->saveDeliverySettings(['lcp_hygiene_enabled' => true]);
        $this->assertTrue(get_option(OptionSettingsRepository::OPTION_LCP_HYGIENE_ENABLED));
        $this->assertTrue((new OptionSettingsRepository())->loadDeliveryConfig()->lcpHygieneEnabled);

        $repo->saveDeliverySettings(['lcp_hygiene_enabled' => false]);
        $this->assertFalse((new OptionSettingsRepository())->loadDeliveryConfig()->lcpHygieneEnabled);
    }

    public function test_validator_coerces_toggle(): void
    {
        $v = new SettingsValidator();
        $this->assertTrue($v->validate(['lcp_hygiene_enabled' => '1'])['values']['lcp_hygiene_enabled']);
        $this->assertFalse($v->validate(['lcp_hygiene_enabled' => '0'])['values']['lcp_hygiene_enabled']);
        $this->assertFalse($v->validate([])['values']['lcp_hygiene_enabled']);
    }

    public function test_mapper_maps_both_directions(): void
    {
        $this->assertTrue(OptionsMapper::toCamel(['lcp_hygiene_enabled' => true])['lcpHygieneEnabled']);
        $this->assertTrue(OptionsMapper::toSnake(['lcpHygieneEnabled' => true])['lcp_hygiene_enabled']);
    }

    // ─── ServiceRegistrar call site ──────────────────────────────────

    /** @return list<array{hook:string,priority:int,callback:mixed}> */
    private function registeredTemplateRedirect(bool $enabled): array
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        update_option(OptionSettingsRepository::OPTION_ENABLED, true);
        update_option(OptionSettingsRepository::OPTION_LCP_HYGIENE_ENABLED, $enabled);
        $GLOBALS['__oxpulse_actions'] = [];

        $method = new \ReflectionMethod(ServiceRegistrar::class, 'registerDeliveryAdapters');
        $method->invoke(null);

        return array_values(array_filter(
            $GLOBALS['__oxpulse_actions'],
            static fn(array $a): bool => $a['hook'] === 'template_redirect'
        ));
    }

    public function test_registrar_wires_the_buffer_only_when_the_option_is_on(): void
    {
        $this->assertSame([], $this->registeredTemplateRedirect(false), 'LCP buffer registered while option is off');

        $on = $this->registeredTemplateRedirect(true);
        $this->assertCount(1, $on);
        $this->assertSame(LcpTagHygiene::BUFFER_PRIORITY, $on[0]['priority']);
    }
}
