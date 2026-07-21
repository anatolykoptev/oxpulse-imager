<?php
/**
 * OptionsMapper unit tests.
 *
 * Verifies camelCase ↔ snake_case key mapping between the React admin
 * SPA and the SettingsValidator input shape.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Integration\WordPress\Admin\OptionsMapper;
use PHPUnit\Framework\TestCase;

class OptionsMapperTest extends TestCase
{
    public function test_to_camel_maps_all_known_keys(): void
    {
        $snake = [
            'enabled'             => true,
            'endpoint'            => 'https://imgproxy.example.com',
            'allowed_sources'     => ['https://example.com/uploads/'],
            'output_format'       => 'auto',
            'default_quality'     => 80,
            'format_quality'      => ['avif' => 70],
            'lqip_enabled'        => true,
            'lqip_blur'           => 1.5,
            'dpr_enabled'         => true,
            'dpr_variants'        => [1, 2, 3],
            'watermark'           => null,
            'diagnostic_level'    => 'off',
            'dev_http_override'   => false,
            'remove_on_uninstall' => false,
        ];

        $camel = OptionsMapper::toCamel($snake);

        $this->assertTrue($camel['enabled']);
        $this->assertSame('https://imgproxy.example.com', $camel['endpoint']);
        $this->assertSame(['https://example.com/uploads/'], $camel['allowedSources']);
        $this->assertSame('auto', $camel['outputFormat']);
        $this->assertSame(80, $camel['defaultQuality']);
        $this->assertSame(['avif' => 70], $camel['formatQuality']);
        $this->assertTrue($camel['lqipEnabled']);
        $this->assertSame(1.5, $camel['lqipBlur']);
        $this->assertTrue($camel['dprEnabled']);
        $this->assertSame([1, 2, 3], $camel['dprVariants']);
        $this->assertNull($camel['watermark']);
        $this->assertSame('off', $camel['diagnosticLevel']);
        $this->assertFalse($camel['devHttpOverride']);
        $this->assertFalse($camel['removeOnUninstall']);
    }

    public function test_to_camel_skips_unknown_snake_keys(): void
    {
        $snake = [
            'enabled' => true,
            'unknown_key' => 'should be dropped',
        ];

        $camel = OptionsMapper::toCamel($snake);

        $this->assertArrayHasKey('enabled', $camel);
        $this->assertArrayNotHasKey('unknown_key', $camel);
        $this->assertArrayNotHasKey('unknownKey', $camel);
    }

    public function test_to_camel_omits_missing_keys(): void
    {
        $snake = ['enabled' => true];

        $camel = OptionsMapper::toCamel($snake);

        // Only keys present in $snake appear in the result.
        $this->assertArrayHasKey('enabled', $camel);
        $this->assertArrayNotHasKey('endpoint', $camel);
    }

    public function test_to_snake_maps_all_known_keys(): void
    {
        $camel = [
            'enabled'           => true,
            'endpoint'          => 'https://imgproxy.example.com',
            'allowedSources'    => ['https://example.com/uploads/'],
            'outputFormat'      => 'auto',
            'defaultQuality'    => 80,
            'formatQuality'     => ['avif' => 70, 'webp' => 85],
            'lqipEnabled'       => true,
            'lqipBlur'          => 2,
            'dprEnabled'        => true,
            'dprVariants'       => '1,2,3',
            'watermark'         => ['enabled' => '1', 'opacity' => '0.5'],
            'diagnosticLevel'   => 'basic',
            'devHttpOverride'   => false,
            'removeOnUninstall' => true,
        ];

        $snake = OptionsMapper::toSnake($camel);

        $this->assertTrue($snake['enabled']);
        $this->assertSame('https://imgproxy.example.com', $snake['endpoint']);
        $this->assertSame(['https://example.com/uploads/'], $snake['allowed_sources']);
        $this->assertSame('auto', $snake['output_format']);
        $this->assertSame(80, $snake['default_quality']);
        $this->assertSame(['avif' => 70, 'webp' => 85], $snake['format_quality']);
        $this->assertTrue($snake['lqip_enabled']);
        $this->assertSame(2, $snake['lqip_blur']);
        $this->assertTrue($snake['dpr_enabled']);
        $this->assertSame('1,2,3', $snake['dpr_variants']);
        $this->assertSame(['enabled' => '1', 'opacity' => '0.5'], $snake['watermark']);
        $this->assertSame('basic', $snake['diagnostic_level']);
        $this->assertFalse($snake['dev_http_override']);
        $this->assertTrue($snake['remove_on_uninstall']);
    }

    public function test_to_snake_skips_unknown_camel_keys(): void
    {
        $camel = [
            'enabled' => true,
            'unknownKey' => 'should be dropped',
        ];

        $snake = OptionsMapper::toSnake($camel);

        $this->assertArrayHasKey('enabled', $snake);
        $this->assertArrayNotHasKey('unknown_key', $snake);
        $this->assertArrayNotHasKey('unknownKey', $snake);
    }

    public function test_round_trip_camel_to_snake_to_camel_preserves_keys(): void
    {
        $originalCamel = [
            'enabled' => true,
            'endpoint' => 'https://test.example.com',
            'outputFormat' => 'webp',
            'defaultQuality' => 75,
        ];

        $snake = OptionsMapper::toSnake($originalCamel);
        $roundTrip = OptionsMapper::toCamel($snake);

        // Only the keys present in the original appear (no extras,
        // no missing).
        $this->assertSame($originalCamel, $roundTrip);
    }

    public function test_get_camel_to_snake_map_returns_complete_map(): void
    {
        $map = OptionsMapper::getCamelToSnakeMap();

        // Every key the SPA can read/write must be in the map.
        $this->assertArrayHasKey('enabled', $map);
        $this->assertArrayHasKey('endpoint', $map);
        $this->assertArrayHasKey('allowedSources', $map);
        $this->assertArrayHasKey('outputFormat', $map);
        $this->assertArrayHasKey('defaultQuality', $map);
        $this->assertArrayHasKey('formatQuality', $map);
        $this->assertArrayHasKey('lqipEnabled', $map);
        $this->assertArrayHasKey('lqipBlur', $map);
        $this->assertArrayHasKey('dprEnabled', $map);
        $this->assertArrayHasKey('dprVariants', $map);
        $this->assertArrayHasKey('watermark', $map);
        $this->assertArrayHasKey('diagnosticLevel', $map);
        $this->assertArrayHasKey('devHttpOverride', $map);
        $this->assertArrayHasKey('removeOnUninstall', $map);
    }
}
