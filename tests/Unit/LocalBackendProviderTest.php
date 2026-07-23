<?php
/**
 * LocalBackendProvider tests.
 *
 * Verifies the local provider's:
 * - isApplicable truth table (signing present + sourceMode !== 'local').
 * - health() maps to Healthy when the host can encode webp OR avif,
 *   Down otherwise (reusing ImageTransformer's real-encode probe).
 * - build() returns a LocalBackend carrying a CapabilityTester.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\BackendHealth;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Infrastructure\Image\ImageTransformer;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;
use OXPulse\Imager\Infrastructure\Local\LocalBackendProvider;
use PHPUnit\Framework\TestCase;

class LocalBackendProviderTest extends TestCase
{
    private const KEY_HEX = '736563726574';
    private const SALT_HEX = '68656C6C6F';
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    private function delivery(string $endpoint = '', string $sourceMode = 'http'): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: true,
            endpoint: $endpoint,
            allowedSources: [self::ALLOWED],
            sourceMode: $sourceMode,
        );
    }

    private function signing(): SigningConfig
    {
        return SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);
    }

    // ─── id / priority ───────────────────────────────────────────────

    public function test_id_is_local(): void
    {
        $provider = new LocalBackendProvider(new ProviderStubTransformer(true, false));
        $this->assertSame('local', $provider->id());
    }

    public function test_priority_is_50(): void
    {
        $provider = new LocalBackendProvider(new ProviderStubTransformer(true, false));
        $this->assertSame(50, $provider->priority());
    }

    // ─── isApplicable truth table ────────────────────────────────────

    public function test_is_applicable_when_signing_present_and_http_source(): void
    {
        $provider = new LocalBackendProvider(new ProviderStubTransformer(true, false));
        $this->assertTrue($provider->isApplicable($this->delivery(), $this->signing()));
    }

    public function test_is_not_applicable_when_signing_null(): void
    {
        $provider = new LocalBackendProvider(new ProviderStubTransformer(true, false));
        $this->assertFalse($provider->isApplicable($this->delivery(), null));
    }

    public function test_is_not_applicable_when_source_mode_local(): void
    {
        $provider = new LocalBackendProvider(new ProviderStubTransformer(true, false));
        $this->assertFalse($provider->isApplicable($this->delivery('', 'local'), $this->signing()));
    }

    // ─── health() ────────────────────────────────────────────────────

    public function test_health_healthy_when_webp_supported(): void
    {
        $provider = new LocalBackendProvider(new ProviderStubTransformer(true, false));
        $this->assertSame(BackendHealth::Healthy, $provider->health($this->delivery()));
    }

    public function test_health_healthy_when_avif_supported(): void
    {
        $provider = new LocalBackendProvider(new ProviderStubTransformer(false, true));
        $this->assertSame(BackendHealth::Healthy, $provider->health($this->delivery()));
    }

    public function test_health_down_when_neither_webp_nor_avif_supported(): void
    {
        $provider = new LocalBackendProvider(new ProviderStubTransformer(false, false));
        $this->assertSame(BackendHealth::Down, $provider->health($this->delivery()));
    }

    // ─── build() ─────────────────────────────────────────────────────

    public function test_build_returns_local_backend_with_capability_tester(): void
    {
        $provider = new LocalBackendProvider(new ProviderStubTransformer(true, false));
        $backend = $provider->build($this->delivery(), $this->signing());

        $this->assertInstanceOf(LocalBackend::class, $backend);
        $this->assertTrue($backend->hasCapabilityTester(), 'LocalBackend from the provider must carry a CapabilityTester');
    }
}

/**
 * Stub ImageTransformer overriding the public supportsWebp()/supportsAvif()
 * probes so the provider's health() can be tested without a real encoder.
 */
class ProviderStubTransformer extends ImageTransformer
{
    public function __construct(
        private bool $webp,
        private bool $avif,
    ) {}

    public function supportsWebp(): bool
    {
        return $this->webp;
    }

    public function supportsAvif(): bool
    {
        return $this->avif;
    }
}
