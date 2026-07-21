<?php
/**
 * Onboarding option flow tests.
 *
 * Verifies that the `onboarded` flag round-trips through the REST
 * options endpoint and the SettingsValidator.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Integration;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\SettingsValidator;
use OXPulse\Imager\Integration\WordPress\Admin\OptionsMapper;
use PHPUnit\Framework\TestCase;

class OnboardingOptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
        parent::tearDown();
    }

    public function test_options_mapper_includes_onboarded(): void
    {
        $map = OptionsMapper::getCamelToSnakeMap();
        $this->assertArrayHasKey('onboarded', $map);
        $this->assertSame('onboarded', $map['onboarded']);
    }

    public function test_options_mapper_to_snake_translates_onboarded(): void
    {
        $snake = OptionsMapper::toSnake(['onboarded' => true]);
        $this->assertArrayHasKey('onboarded', $snake);
        $this->assertTrue($snake['onboarded']);
    }

    public function test_options_mapper_to_camel_translates_onboarded(): void
    {
        $camel = OptionsMapper::toCamel(['onboarded' => true]);
        $this->assertArrayHasKey('onboarded', $camel);
        $this->assertTrue($camel['onboarded']);
    }

    public function test_settings_validator_passes_onboarded_through(): void
    {
        $validator = new SettingsValidator();
        $result = $validator->validate(['onboarded' => true]);
        $this->assertArrayHasKey('onboarded', $result['values']);
        $this->assertTrue($result['values']['onboarded']);
    }

    public function test_settings_validator_defaults_onboarded_to_false(): void
    {
        $validator = new SettingsValidator();
        $result = $validator->validate([]);
        $this->assertArrayHasKey('onboarded', $result['values']);
        $this->assertFalse($result['values']['onboarded']);
    }

    public function test_settings_validator_coerces_non_bool_to_bool(): void
    {
        $validator = new SettingsValidator();
        $result = $validator->validate(['onboarded' => 1]);
        $this->assertTrue($result['values']['onboarded']);

        $result = $validator->validate(['onboarded' => '']);
        $this->assertFalse($result['values']['onboarded']);
    }

    public function test_option_constant_defined(): void
    {
        $this->assertSame('oxpulse_imager_onboarded', OptionSettingsRepository::OPTION_ONBOARDED);
    }
}
