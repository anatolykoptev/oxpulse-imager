<?php
/**
 * LocalBackend + DeliveryBackendFactory tests.
 *
 * Verifies the Phase 6 local delivery backend:
 * - Produces an absolute, stable, signed cache-file URL under
 *   /wp-content/cache/oxpulse/.
 * - Deterministic: same (source, transform, format) -> same key/URL.
 * - Key round-trips: verify() recovers the payload; a tampered key fails.
 * - Backend selection: endpoint present -> ImgproxyBackend; absent ->
 *   LocalBackend.
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
use OXPulse\Imager\Domain\Transform\TransformRequest;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackend;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;
use PHPUnit\Framework\TestCase;

class LocalBackendTest extends TestCase
{
    private const KEY_HEX = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
    private const SALT_HEX = 'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5';
    private const SOURCE = 'https://example.com/wp-content/uploads/2024/01/photo.jpg';

    private function backend(): LocalBackend
    {
        return new LocalBackend(SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX));
    }

    private function request(array $overrides = []): TransformRequest
    {
        return new TransformRequest(
            sourceUrl: $overrides['sourceUrl'] ?? self::SOURCE,
            width: $overrides['width'] ?? 800,
            height: $overrides['height'] ?? 0,
            resize: $overrides['resize'] ?? 'fit',
            format: $overrides['format'] ?? 'webp',
            quality: $overrides['quality'] ?? 80,
            context: $overrides['context'] ?? 'content',
            dpr: 0,
            blur: 0,
            watermark: null,
            formatQuality: [],
            sourceMode: $overrides['sourceMode'] ?? 'http',
        );
    }

    public function test_produces_absolute_url_under_cache_dir(): void
    {
        $url = $this->backend()->generate($this->request());

        $this->assertStringStartsWith('https://example.test/wp-content/cache/oxpulse/', $url);
        // URL ends with .webp (the output format).
        $this->assertStringEndsWith('.webp', $url);
    }

    public function test_url_contains_source_hash_segment(): void
    {
        $url = $this->backend()->generate($this->request());

        // Layout: /wp-content/cache/oxpulse/<sourceHash>/<key>.webp
        $path = parse_url($url, PHP_URL_PATH);
        $segments = explode('/', trim($path, '/'));
        // wp-content, cache, oxpulse, <sourceHash>, <key>.webp
        $this->assertCount(5, $segments);
        $sourceHash = $segments[3];
        $this->assertSame(
            LocalBackend::sourceHash(self::SOURCE),
            $sourceHash,
            'URL must contain the sourceHash path segment'
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $sourceHash);
    }

    public function test_different_sources_produce_different_source_hash_dirs(): void
    {
        $backend = $this->backend();
        $url1 = $backend->generate($this->request(['sourceUrl' => 'https://example.com/wp-content/uploads/a.jpg']));
        $url2 = $backend->generate($this->request(['sourceUrl' => 'https://example.com/wp-content/uploads/b.jpg']));

        $hash1 = explode('/', trim(parse_url($url1, PHP_URL_PATH), '/'))[3];
        $hash2 = explode('/', trim(parse_url($url2, PHP_URL_PATH), '/'))[3];
        $this->assertNotSame($hash1, $hash2);
    }

    public function test_same_source_same_hash_regardless_of_transform(): void
    {
        $backend = $this->backend();
        $url1 = $backend->generate($this->request(['width' => 800]));
        $url2 = $backend->generate($this->request(['width' => 400]));

        $hash1 = explode('/', trim(parse_url($url1, PHP_URL_PATH), '/'))[3];
        $hash2 = explode('/', trim(parse_url($url2, PHP_URL_PATH), '/'))[3];
        $this->assertSame($hash1, $hash2, 'Same source must share sourceHash dir regardless of transform');
    }

    public function test_url_is_absolute_and_starts_with_home_url(): void
    {
        $url = $this->backend()->generate($this->request());

        // home_url() stub returns https://example.test/...
        $this->assertStringStartsWith('https://example.test/', $url);
    }

    public function test_deterministic_same_input_same_key(): void
    {
        $backend = $this->backend();
        $req = $this->request();

        $url1 = $backend->generate($req);
        $url2 = $backend->generate($req);

        $this->assertSame($url1, $url2);
    }

    public function test_different_transform_produces_different_key(): void
    {
        $backend = $this->backend();

        $url1 = $backend->generate($this->request(['width' => 800]));
        $url2 = $backend->generate($this->request(['width' => 400]));

        $this->assertNotSame($url1, $url2);
    }

    public function test_different_source_produces_different_key(): void
    {
        $backend = $this->backend();

        $url1 = $backend->generate($this->request(['sourceUrl' => 'https://example.com/wp-content/uploads/a.jpg']));
        $url2 = $backend->generate($this->request(['sourceUrl' => 'https://example.com/wp-content/uploads/b.jpg']));

        $this->assertNotSame($url1, $url2);
    }

    public function test_different_format_produces_different_key_and_extension(): void
    {
        $backend = $this->backend();

        $urlWebp = $backend->generate($this->request(['format' => 'webp']));
        $urlAvif = $backend->generate($this->request(['format' => 'avif']));

        $this->assertNotSame($urlWebp, $urlAvif);
        $this->assertStringEndsWith('.webp', $urlWebp);
        $this->assertStringEndsWith('.avif', $urlAvif);
    }

    public function test_auto_format_defaults_to_webp_extension(): void
    {
        $url = $this->backend()->generate($this->request(['format' => 'auto']));

        // MVP: auto -> webp (Accept negotiation is Dispatch 2's concern).
        $this->assertStringEndsWith('.webp', $url);
    }

    public function test_key_round_trips_via_verify(): void
    {
        $backend = $this->backend();
        $req = $this->request(['width' => 600, 'height' => 400, 'resize' => 'fill', 'quality' => 75]);

        $url = $backend->generate($req);

        // Extract the key (basename without extension).
        $basename = basename(parse_url($url, PHP_URL_PATH));
        $key = substr($basename, 0, strrpos($basename, '.'));

        $payload = $backend->verify($key);

        $this->assertNotNull($payload);
        $this->assertSame(self::SOURCE, $payload['source']);
        $this->assertSame(600, $payload['width']);
        $this->assertSame(400, $payload['height']);
        $this->assertSame('fill', $payload['resize']);
        $this->assertSame('webp', $payload['format']);
        $this->assertSame(75, $payload['quality']);
    }

    public function test_tampered_key_fails_verification(): void
    {
        $backend = $this->backend();
        $req = $this->request();

        $url = $backend->generate($req);
        $basename = basename(parse_url($url, PHP_URL_PATH));
        $key = substr($basename, 0, strrpos($basename, '.'));

        // Tamper: flip the last character of the signature portion.
        $parts = explode('.', $key);
        $this->assertCount(2, $parts);
        $sig = $parts[1];
        $last = $sig[strlen($sig) - 1];
        $tampered = $last === 'A' ? 'B' : 'A';
        $tamperedKey = $parts[0] . '.' . substr($sig, 0, -1) . $tampered;

        $this->assertNull($backend->verify($tamperedKey));
    }

    public function test_tampered_payload_fails_verification(): void
    {
        $backend = $this->backend();
        $req = $this->request();

        $url = $backend->generate($req);
        $basename = basename(parse_url($url, PHP_URL_PATH));
        $key = substr($basename, 0, strrpos($basename, '.'));

        // Tamper: modify the payload portion but keep the original signature.
        $parts = explode('.', $key);
        $payloadB64 = $parts[0];
        $sig = $parts[1];

        // Decode, change the width, re-encode.
        $payloadJson = base64_decode(strtr($payloadB64, '-_', '+/'), true);
        $payload = json_decode($payloadJson, true);
        $payload['w'] = $payload['w'] + 100;
        $tamperedPayloadB64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        $tamperedKey = $tamperedPayloadB64 . '.' . $sig;

        $this->assertNull($backend->verify($tamperedKey));
    }

    public function test_malformed_key_fails_verification(): void
    {
        $backend = $this->backend();

        $this->assertNull($backend->verify('not-a-valid-key'));
        $this->assertNull($backend->verify('no-dot-here'));
        $this->assertNull($backend->verify(''));
    }

    public function test_available_always_true(): void
    {
        // LocalBackend needs only a signing key (checked by UrlRewriter's
        // signing guard), not an endpoint.
        $this->assertTrue($this->backend()->available());
    }

    // --- DeliveryBackendFactory ---

    public function test_factory_selects_imgproxy_when_endpoint_present(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: ['https://example.com/wp-content/uploads/'],
        );
        $signing = SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);

        $backend = DeliveryBackendFactory::select($delivery, $signing);

        $this->assertInstanceOf(ImgproxyBackend::class, $backend);
    }

    public function test_factory_selects_local_when_endpoint_absent(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: '',
            allowedSources: ['https://example.com/wp-content/uploads/'],
        );
        $signing = SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);

        $backend = DeliveryBackendFactory::select($delivery, $signing);

        $this->assertInstanceOf(LocalBackend::class, $backend);
    }

    /**
     * #29.2: LocalBackend is incompatible with sourceMode='local'.
     *
     * When sourceMode='local', SourcePolicy produces a SourceDecision
     * with fsPath !== null, and UrlRewriter uses that bare filesystem
     * path as the TransformRequest source. LocalBackend signs a key
     * whose payload 'source' is a bare fs path (no scheme+host). At
     * miss-endpoint time, PathGuard::resolve() requires scheme+host
     * from payload['source'] → null → 404 on every image; URL-
     * normalized invalidation can't match either.
     *
     * The safe fix: LocalBackend requires http source mode. When
     * sourceMode='local' + no imgproxy endpoint, the factory returns
     * null (no backend) → UrlRewriter preserves the original URL.
     */
    public function test_factory_returns_null_when_source_mode_local_and_no_endpoint(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: '',
            allowedSources: ['https://example.com/wp-content/uploads/'],
            sourceMode: 'local',
            localBasePath: '/tmp/wp-content/uploads',
        );
        $signing = SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);

        $backend = DeliveryBackendFactory::select($delivery, $signing);

        // No LocalBackend — preserve original (no rewrite).
        $this->assertNull($backend);
    }

    /**
     * #29.2: imgproxy + sourceMode='local' is a valid combo (imgproxy
     * reads via local:// transport). The factory must still select
     * ImgproxyBackend in that case — the guard only blocks LocalBackend.
     */
    public function test_factory_selects_imgproxy_when_source_mode_local_with_endpoint(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: ['https://example.com/wp-content/uploads/'],
            sourceMode: 'local',
            localBasePath: '/tmp/wp-content/uploads',
        );
        $signing = SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);

        $backend = DeliveryBackendFactory::select($delivery, $signing);

        $this->assertInstanceOf(ImgproxyBackend::class, $backend);
    }

    /**
     * #29.2: the guard must not regress the default (http) path —
     * sourceMode='http' + no endpoint still selects LocalBackend.
     */
    public function test_factory_selects_local_when_source_mode_http_and_no_endpoint(): void
    {
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: '',
            allowedSources: ['https://example.com/wp-content/uploads/'],
            sourceMode: 'http',
        );
        $signing = SigningConfig::fromHex(self::KEY_HEX, self::SALT_HEX);

        $backend = DeliveryBackendFactory::select($delivery, $signing);

        $this->assertInstanceOf(LocalBackend::class, $backend);
    }
}
