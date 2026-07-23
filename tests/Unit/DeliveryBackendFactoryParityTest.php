<?php
/**
 * DeliveryBackendFactory behavior-parity tests.
 *
 * Verifies the factory's delegation to DeliveryBackendRegistry preserves
 * the 4 unchanged selection cases (BC parity) AND delivers the NEW
 * health-gated fallthrough (endpoint set + cached imgproxy Down →
 * LocalBackend, no more broken URLs on a dead imgproxy).
 *
 * Parity cases (MUST hold):
 *  1. signing === null → null (no backend).
 *  2. endpoint set + imgproxy healthy → ImgproxyBackend.
 *  3. endpoint empty + http source + signing → LocalBackend.
 *  4. endpoint empty + sourceMode === 'local' → null (passthrough).
 *
 * NEW behavior:
 *  5. endpoint set + cached imgproxy health Down → LocalBackend
 *     (fallthrough), NOT ImgproxyBackend.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\DeliveryBackendFactory;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackend;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;
use PHPUnit\Framework\TestCase;

class DeliveryBackendFactoryParityTest extends TestCase
{
    private const KEY_HEX = '736563726573';
    private const SALT_HEX = '68656C6C6F';
    private const ENDPOINT = 'https://imgproxy.example.com';
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    protected function setUp(): void
    {
        // Reset the imgproxy health cache option (#81: persistent
        // option, not transient) + the WP filter list so each parity
        // case starts from a clean slate (the registry's default()
        // applies the oxpulse_delivery_backends filter, which must
        // not leak between tests).
        $GLOBALS['__oxpulse_transients'] = [];
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_transients']);
        unset($GLOBALS['__oxpulse_options']);
        unset($GLOBALS['__oxpulse_filters']);
    }

    private function delivery(string $endpoint = self::ENDPOINT, string $sourceMode = 'http'): DeliveryConfig
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

    // ─── BC parity case 1: signing === null → null ───────────────────

    public function test_parity_signing_null_returns_null(): void
    {
        $this->assertNull(
            DeliveryBackendFactory::select($this->delivery(), null),
            'signing === null must return null (no backend can sign)',
        );
    }

    // ─── BC parity case 2: endpoint set + imgproxy healthy → ImgproxyBackend ──

    public function test_parity_endpoint_set_healthy_imgproxy_returns_imgproxy_backend(): void
    {
        // Cache unset → optimistic Healthy (the default).
        $backend = DeliveryBackendFactory::select($this->delivery(), $this->signing());

        $this->assertInstanceOf(ImgproxyBackend::class, $backend);
    }

    // ─── BC parity case 3: endpoint empty + http source + signing → LocalBackend ──

    public function test_parity_endpoint_empty_http_source_returns_local_backend(): void
    {
        // The host must be able to encode webp/avif for LocalBackend to
        // be Healthy; on an encoder-less host the registry correctly
        // falls through to passthrough (null). This host (and CI) has
        // GD webp, so LocalBackend is the parity expectation.
        $backend = DeliveryBackendFactory::select($this->delivery(''), $this->signing());

        $this->assertInstanceOf(LocalBackend::class, $backend);
        $this->assertTrue($backend->hasCapabilityTester(), 'Factory-constructed LocalBackend must carry a CapabilityTester');
    }

    // ─── BC parity case 4: endpoint empty + sourceMode local → null ──

    public function test_parity_endpoint_empty_local_source_mode_returns_null(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: '',
            allowedSources: [self::ALLOWED],
            sourceMode: 'local',
        );

        $backend = DeliveryBackendFactory::select($delivery, $this->signing());

        $this->assertNull(
            $backend,
            'endpoint empty + sourceMode=local → null (passthrough preserves original URL)',
        );
    }

    // ─── NEW behavior: cached imgproxy Down → fallthrough to LocalBackend ──

    public function test_new_cached_imgproxy_down_falls_through_to_local_backend(): void
    {
        // Mark imgproxy health Down in the cache (a prior probe found
        // the endpoint dead).
        (new ImgproxyHealthCache())->write('down');

        $backend = DeliveryBackendFactory::select($this->delivery(), $this->signing());

        $this->assertNotInstanceOf(
            ImgproxyBackend::class,
            $backend,
            'cached-Down imgproxy must NOT be selected — no more broken URLs on a dead imgproxy',
        );
        $this->assertInstanceOf(
            LocalBackend::class,
            $backend,
            'endpoint set + imgproxy Down → fall through to LocalBackend (the next-best applicable, healthy provider)',
        );
    }

    public function test_new_cached_imgproxy_down_with_local_source_unavailable_returns_null(): void
    {
        // imgproxy Down AND local not applicable (sourceMode=local) →
        // passthrough (null). No broken imgproxy URLs, no broken
        // local-cache URLs — preserve the original.
        (new ImgproxyHealthCache())->write('down');
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: self::ENDPOINT,
            allowedSources: [self::ALLOWED],
            sourceMode: 'local',
        );

        $backend = DeliveryBackendFactory::select($delivery, $this->signing());

        $this->assertNull(
            $backend,
            'imgproxy Down + local not applicable → passthrough (null), preserve original URL',
        );
    }
}
