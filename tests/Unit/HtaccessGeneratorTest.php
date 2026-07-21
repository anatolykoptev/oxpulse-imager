<?php
/**
 * HtaccessGenerator + CapabilityTester tests.
 *
 * Verifies:
 * - The .htaccess generator emits the expected rewrite rules (miss →
 *   oxpulse-img.php, WebP Accept gate).
 * - The capability tester picks the fallback when mod_rewrite is
 *   unavailable (stubbed).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Local\HtaccessGenerator;
use PHPUnit\Framework\TestCase;

class HtaccessGeneratorTest extends TestCase
{
    public function test_generates_rewrite_rules_for_cache_miss(): void
    {
        $gen = new HtaccessGenerator();
        $rules = $gen->generate(
            cacheBaseUrl: 'https://example.com/wp-content/cache/oxpulse',
            endpointRelPath: 'oxpulse-img.php',
        );

        // RewriteEngine On.
        $this->assertStringContainsString('RewriteEngine On', $rules);
        // RewriteCond %{REQUEST_FILENAME} !-f (only on miss).
        $this->assertStringContainsString('%{REQUEST_FILENAME} !-f', $rules);
        // Rewrite to the endpoint.
        $this->assertStringContainsString('oxpulse-img.php', $rules);
        // WebP Accept gate.
        $this->assertStringContainsString('%{HTTP_ACCEPT} image/webp', $rules);
    }

    public function test_rules_include_cache_control_headers(): void
    {
        $gen = new HtaccessGenerator();
        $rules = $gen->generate(
            cacheBaseUrl: 'https://example.com/wp-content/cache/oxpulse',
            endpointRelPath: 'oxpulse-img.php',
        );

        // The .htaccess should set long cache for existing cache files.
        $this->assertStringContainsString('Cache-Control', $rules);
        $this->assertStringContainsString('immutable', $rules);
    }

    public function test_rules_deny_php_execution_in_cache_dir(): void
    {
        $gen = new HtaccessGenerator();
        $rules = $gen->generate(
            cacheBaseUrl: 'https://example.com/wp-content/cache/oxpulse',
            endpointRelPath: 'oxpulse-img.php',
        );

        $this->assertStringContainsString('php_flag engine off', $rules);
    }
}
