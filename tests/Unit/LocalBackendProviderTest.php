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
use OXPulse\Imager\Infrastructure\Local\LocalDeliveryInstaller;
use PHPUnit\Framework\TestCase;

class LocalBackendProviderTest extends TestCase
{
    private const KEY_HEX = '736563726574';
    private const SALT_HEX = '68656C6C6F';
    private const ALLOWED = 'https://example.com/wp-content/uploads/';

    /** Temp dir holding the miss-endpoint artifact for health() tests. */
    private string $artifactDir;

    protected function setUp(): void
    {
        // #87: single-site is the production default; tests flip this
        // to true to exercise the multisite LocalBackend gate.
        $GLOBALS['__oxpulse_is_multisite'] = false;
        // FIX (BLOCKER): health() now also checks the miss-endpoint
        // artifact exists on disk. Each test controls the artifact
        // explicitly via an injected path so the suite is robust to
        // cross-test WP_CONTENT_DIR contamination (other test classes
        // define WP_CONTENT_DIR to a temp dir).
        $this->artifactDir = sys_get_temp_dir() . '/oxpulse-provider-artifact-' . uniqid();
        mkdir($this->artifactDir, 0755, true);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_is_multisite']);
        $this->rmrf($this->artifactDir);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    /**
     * Path to a PRESENT miss-endpoint artifact (the file exists on
     * disk — the healthy-local case: installer ran, endpoint==='').
     */
    private function artifactPresent(): string
    {
        $path = $this->artifactDir . '/' . LocalDeliveryInstaller::ENDPOINT_FILENAME;
        file_put_contents($path, '<?php // present');
        return $path;
    }

    /**
     * Path to an ABSENT miss-endpoint artifact (the file does NOT
     * exist — the BLOCKER case: free + stored imgproxy endpoint →
     * gate 2 strips imgproxy → LocalBackend selected but installer
     * self-gated because endpoint !== '').
     */
    private function artifactAbsent(): string
    {
        return $this->artifactDir . '/' . LocalDeliveryInstaller::ENDPOINT_FILENAME;
    }

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
        $provider = new LocalBackendProvider(new ProviderStubTransformer(true, false), $this->artifactPresent());
        $this->assertSame('local', $provider->id());
    }

    public function test_priority_is_50(): void
    {
        $provider = new LocalBackendProvider(new ProviderStubTransformer(true, false), $this->artifactPresent());
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

    // ─── #87: multisite gate ─────────────────────────────────────────

    /**
     * #87: LocalBackend is NOT applicable on WordPress Multisite — one
     * shared oxpulse-img.php endpoint baked per-blog breaks every other
     * blog (HMAC mismatch / PathGuard reject). The registry falls
     * through to Passthrough (or ImgproxyBackend when an endpoint is
     * configured).
     */
    public function test_is_not_applicable_on_multisite(): void
    {
        $GLOBALS['__oxpulse_is_multisite'] = true;
        $provider = new LocalBackendProvider(new ProviderStubTransformer(true, false));
        $this->assertFalse(
            $provider->isApplicable($this->delivery(), $this->signing()),
            'LocalBackend must NOT be applicable on multisite (#87)',
        );
    }

    /**
     * #87: the multisite gate must NOT regress the single-site path —
     * LocalBackend stays applicable on a single-site install with
     * signing + http source mode.
     */
    public function test_is_applicable_on_single_site_unchanged(): void
    {
        $GLOBALS['__oxpulse_is_multisite'] = false;
        $provider = new LocalBackendProvider(new ProviderStubTransformer(true, false));
        $this->assertTrue($provider->isApplicable($this->delivery(), $this->signing()));
    }

    // ─── health() ────────────────────────────────────────────────────

    public function test_health_healthy_when_webp_supported_and_artifact_present(): void
    {
        $provider = new LocalBackendProvider(new ProviderStubTransformer(true, false), $this->artifactPresent());
        $this->assertSame(BackendHealth::Healthy, $provider->health($this->delivery()));
    }

    public function test_health_healthy_when_avif_supported_and_artifact_present(): void
    {
        $provider = new LocalBackendProvider(new ProviderStubTransformer(false, true), $this->artifactPresent());
        $this->assertSame(BackendHealth::Healthy, $provider->health($this->delivery()));
    }

    public function test_health_down_when_neither_webp_nor_avif_supported(): void
    {
        // Encoder absent → Down regardless of the artifact.
        $provider = new LocalBackendProvider(new ProviderStubTransformer(false, false), $this->artifactPresent());
        $this->assertSame(BackendHealth::Down, $provider->health($this->delivery()));
    }

    // ─── health() — BLOCKER: miss-endpoint artifact must exist ────────

    /**
     * BLOCKER fix: when the LocalBackend miss-endpoint artifact is
     * ABSENT on disk (free + a stored imgproxy endpoint → gate 2
     * strips imgproxy → registry selects LocalBackend → but the
     * installer self-gated because endpoint !== '' → no artifact →
     * LocalBackend would emit signed URLs to a non-existent endpoint
     * → 404 on every optimized <img> sitewide). health() MUST return
     * Down so select() falls through to Passthrough (original URLs,
     * unoptimized but WORKING).
     */
    public function test_health_down_when_artifact_absent_even_with_encoder(): void
    {
        $provider = new LocalBackendProvider(new ProviderStubTransformer(true, false), $this->artifactAbsent());
        $this->assertSame(
            BackendHealth::Down,
            $provider->health($this->delivery()),
            'LocalBackend must be Down when the miss-endpoint artifact is absent (BLOCKER: avoid 404)',
        );
    }

    /**
     * The healthy-local path must NOT regress: encoder present AND
     * artifact present → Healthy (free WebP still works on a real
     * local site where endpoint==='' and the installer ran).
     */
    public function test_health_healthy_when_encoder_and_artifact_present(): void
    {
        $provider = new LocalBackendProvider(new ProviderStubTransformer(true, false), $this->artifactPresent());
        $this->assertSame(BackendHealth::Healthy, $provider->health($this->delivery()));
    }

    // ─── build() ─────────────────────────────────────────────────────

    public function test_build_returns_local_backend_with_capability_tester(): void
    {
        $provider = new LocalBackendProvider(new ProviderStubTransformer(true, false), $this->artifactPresent());
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
