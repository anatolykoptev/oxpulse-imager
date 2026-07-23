<?php
/**
 * PassthroughBackendProvider tests.
 *
 * The guaranteed floor: always applicable, always Healthy, build()
 * returns null (UrlRewriter preserves the original URL).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\BackendHealth;
use OXPulse\Imager\Application\Delivery\PassthroughBackendProvider;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use PHPUnit\Framework\TestCase;

class PassthroughBackendProviderTest extends TestCase
{
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    private function delivery(string $endpoint = '', string $sourceMode = 'local'): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: true,
            endpoint: $endpoint,
            allowedSources: [self::ALLOWED],
            sourceMode: $sourceMode,
        );
    }

    public function test_id_is_passthrough(): void
    {
        $this->assertSame('passthrough', (new PassthroughBackendProvider())->id());
    }

    public function test_priority_is_zero(): void
    {
        $this->assertSame(0, (new PassthroughBackendProvider())->priority());
    }

    public function test_is_applicable_always_true_with_signing(): void
    {
        $provider = new PassthroughBackendProvider();
        $this->assertTrue($provider->isApplicable($this->delivery(), SigningConfig::fromHex('ab', 'cd')));
    }

    public function test_is_applicable_always_true_without_signing(): void
    {
        $provider = new PassthroughBackendProvider();
        $this->assertTrue($provider->isApplicable($this->delivery(), null));
    }

    public function test_health_always_healthy(): void
    {
        $provider = new PassthroughBackendProvider();
        $this->assertSame(BackendHealth::Healthy, $provider->health($this->delivery()));
    }

    public function test_build_returns_null(): void
    {
        $provider = new PassthroughBackendProvider();
        $this->assertNull($provider->build($this->delivery(), SigningConfig::fromHex('ab', 'cd')));
    }
}
