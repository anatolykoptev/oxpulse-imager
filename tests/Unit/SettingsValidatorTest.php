<?php
/**
 * SettingsValidator tests.
 *
 * Verifies sanitization, HTTPS enforcement, minimum key/salt length,
 * allowed-source normalization, and output format / quality clamping.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\WordPress\SettingsValidator;
use PHPUnit\Framework\TestCase;

class SettingsValidatorTest extends TestCase
{
    private SettingsValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SettingsValidator();
    }

    public function test_valid_https_endpoint_passes(): void
    {
        $result = $this->validator->validate([
            'enabled' => '1',
            'endpoint' => 'https://imgproxy.example.com/',
            'key' => bin2hex(random_bytes(16)),
            'salt' => bin2hex(random_bytes(16)),
            'allowed_sources' => "https://example.com/wp-content/uploads/",
            'output_format' => 'avif',
            'default_quality' => '82',
        ]);

        $this->assertEmpty($result['errors']);
        $this->assertTrue($result['values']['enabled']);
        $this->assertSame('https://imgproxy.example.com', $result['values']['endpoint']);
        $this->assertSame('avif', $result['values']['output_format']);
        $this->assertSame(82, $result['values']['default_quality']);
        $this->assertCount(1, $result['values']['allowed_sources']);
        $this->assertSame('https://example.com/wp-content/uploads/', $result['values']['allowed_sources'][0]);
    }

    public function test_http_endpoint_rejected_without_dev_override(): void
    {
        $result = $this->validator->validate([
            'endpoint' => 'http://localhost:8080',
        ]);

        $this->assertArrayHasKey('endpoint', $result['errors']);
        $this->assertStringContainsString('HTTPS', $result['errors']['endpoint']);
    }

    public function test_http_endpoint_allowed_with_dev_override(): void
    {
        $result = $this->validator->validate([
            'endpoint' => 'http://localhost:8080',
            'dev_http_override' => '1',
        ]);

        $this->assertArrayNotHasKey('endpoint', $result['errors']);
        $this->assertSame('http://localhost:8080', $result['values']['endpoint']);
    }

    public function test_malformed_endpoint_rejected(): void
    {
        $result = $this->validator->validate([
            'endpoint' => 'not-a-url',
        ]);

        $this->assertArrayHasKey('endpoint', $result['errors']);
    }

    public function test_short_key_rejected(): void
    {
        $result = $this->validator->validate([
            'key' => 'abcd', // 2 bytes, below 16-byte minimum
        ]);

        $this->assertArrayHasKey('key', $result['errors']);
    }

    public function test_non_hex_key_rejected(): void
    {
        $result = $this->validator->validate([
            'key' => 'zz' . bin2hex(random_bytes(16)),
        ]);

        $this->assertArrayHasKey('key', $result['errors']);
    }

    public function test_odd_length_hex_rejected(): void
    {
        $result = $this->validator->validate([
            'key' => 'abc',
        ]);

        $this->assertArrayHasKey('key', $result['errors']);
    }

    public function test_empty_key_passes_when_omitted(): void
    {
        // Empty key means "keep existing" — must not error.
        $result = $this->validator->validate([
            'endpoint' => 'https://imgproxy.example.com',
        ]);

        $this->assertArrayNotHasKey('key', $result['errors']);
        $this->assertSame('', $result['values']['key']);
    }

    public function test_salt_minimum_length_enforced(): void
    {
        $result = $this->validator->validate([
            'salt' => bin2hex(random_bytes(8)), // 8 bytes, below 16
        ]);

        $this->assertArrayHasKey('salt', $result['errors']);
    }

    public function test_allowed_sources_normalized_with_trailing_slash(): void
    {
        $result = $this->validator->validate([
            'allowed_sources' => "https://example.com/wp-content/uploads\nhttps://cdn.example.com/images/",
        ]);

        $this->assertArrayNotHasKey('allowed_sources', $result['errors']);
        $this->assertSame('https://example.com/wp-content/uploads/', $result['values']['allowed_sources'][0]);
        $this->assertSame('https://cdn.example.com/images/', $result['values']['allowed_sources'][1]);
    }

    public function test_invalid_allowed_source_rejected(): void
    {
        $result = $this->validator->validate([
            'allowed_sources' => "not-a-url\nhttps://valid.example.com/",
        ]);

        $this->assertArrayHasKey('allowed_sources', $result['errors']);
    }

    public function test_unknown_output_format_clamped_to_auto(): void
    {
        $result = $this->validator->validate([
            'output_format' => 'gif',
        ]);

        $this->assertSame('auto', $result['values']['output_format']);
    }

    public function test_quality_clamped_to_valid_range(): void
    {
        $low = $this->validator->validate(['default_quality' => '0']);
        $this->assertSame(1, $low['values']['default_quality']);

        $high = $this->validator->validate(['default_quality' => '999']);
        $this->assertSame(100, $high['values']['default_quality']);
    }

    public function test_diagnostic_level_clamped(): void
    {
        $result = $this->validator->validate([
            'diagnostic_level' => 'chatty',
        ]);

        $this->assertSame('off', $result['values']['diagnostic_level']);
    }

    public function test_enabled_toggle_sanitized_to_bool(): void
    {
        $on = $this->validator->validate(['enabled' => '1']);
        $this->assertTrue($on['values']['enabled']);

        $off = $this->validator->validate([]);
        $this->assertFalse($off['values']['enabled']);
    }
}
