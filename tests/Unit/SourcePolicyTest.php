<?php
/**
 * SourcePolicy tests.
 *
 * Verifies source URL authorization against the configured allowlist,
 * including SSRF bypass attempts, proxy loop detection, and path
 * boundary enforcement.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use PHPUnit\Framework\TestCase;

class SourcePolicyTest extends TestCase
{
    private SourcePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new SourcePolicy();
    }

    private function config(array $sources, bool $enabled = true): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: $enabled,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: $sources
        );
    }

    // === Authorized cases ===

    public function test_authorizes_valid_uploads_url(): void
    {
        $config = $this->config(['https://example.com/wp-content/uploads/']);
        $decision = $this->policy->authorize(
            'https://example.com/wp-content/uploads/2026/07/image.jpg',
            $config
        );

        $this->assertTrue($decision->authorized);
        $this->assertSame('authorized', $decision->reason);
    }

    public function test_authorizes_root_origin_prefix(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'https://example.com/any/path/image.jpg',
            $config
        );

        $this->assertTrue($decision->authorized);
    }

    public function test_authorizes_explicit_subdirectory_prefix(): void
    {
        $config = $this->config(['https://example.com/wp-content/']);
        $decision = $this->policy->authorize(
            'https://example.com/wp-content/uploads/2026/image.jpg',
            $config
        );

        $this->assertTrue($decision->authorized);
    }

    // === Denied cases ===

    public function test_denies_when_delivery_disabled(): void
    {
        $config = $this->config(['https://example.com/'], enabled: false);
        $decision = $this->policy->authorize(
            'https://example.com/wp-content/uploads/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('delivery_disabled', $decision->reason);
    }

    public function test_denies_when_no_allowed_sources(): void
    {
        $config = $this->config([]);
        $decision = $this->policy->authorize(
            'https://example.com/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('no_allowed_sources_configured', $decision->reason);
    }

    public function test_denies_foreign_host(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'https://evil.com/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('source_not_in_allowlist', $decision->reason);
    }

    public function test_denies_host_suffix_bypass(): void
    {
        // https://example.com/ must NOT authorize https://example.com.evil.test/
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'https://example.com.evil.test/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('source_not_in_allowlist', $decision->reason);
    }

    public function test_denies_user_info_bypass(): void
    {
        // https://example.com/ must NOT authorize https://example.com@evil.test/
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'https://example.com@evil.test/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('malformed_url', $decision->reason);
    }

    public function test_denies_fragment(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'https://example.com/image.jpg#fragment',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('malformed_url', $decision->reason);
    }

    public function test_denies_non_http_scheme(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'data:image/png;base64,abc123',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('malformed_url', $decision->reason);
    }

    public function test_denies_ftp_scheme(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'ftp://example.com/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('malformed_url', $decision->reason);
    }

    public function test_denies_control_characters(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            "https://example.com/\x00image.jpg",
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('malformed_url', $decision->reason);
    }

    public function test_denies_empty_url(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize('', $config);

        $this->assertFalse($decision->authorized);
        $this->assertSame('malformed_url', $decision->reason);
    }

    public function test_denies_proxy_loop(): void
    {
        $config = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: ['https://imgproxy.example.com/']
        );
        $decision = $this->policy->authorize(
            'https://imgproxy.example.com/some/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('proxy_loop_detected', $decision->reason);
    }

    public function test_denies_sibling_path(): void
    {
        // /wp-content must NOT authorize /wp-content-foo
        $config = $this->config(['https://example.com/wp-content']);
        $decision = $this->policy->authorize(
            'https://example.com/wp-content-foo/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('source_not_in_allowlist', $decision->reason);
    }

    public function test_denies_different_port(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'https://example.com:8080/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('source_not_in_allowlist', $decision->reason);
    }

    public function test_denies_http_when_allowlist_is_https(): void
    {
        $config = $this->config(['https://example.com/']);
        $decision = $this->policy->authorize(
            'http://example.com/image.jpg',
            $config
        );

        $this->assertFalse($decision->authorized);
        $this->assertSame('source_not_in_allowlist', $decision->reason);
    }
}
