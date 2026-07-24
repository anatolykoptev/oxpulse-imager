<?php
/**
 * Gate 2 — imgproxy delivery feature gate tests.
 *
 * Verifies the imgproxy delivery backend is NOT selectable under free:
 * the oxpulse_delivery_backends filter (registered at bootstrap by
 * ServiceRegistrar) strips the ImgproxyBackendProvider when !isPro(),
 * so the registry falls through to LocalBackend / Passthrough —
 * delivery never breaks.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\DeliveryBackendRegistry;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackendProvider;
use OXPulse\Imager\Infrastructure\Image\ImageTransformer;
use OXPulse\Imager\Infrastructure\Local\LocalBackend;
use OXPulse\Imager\Infrastructure\Local\LocalBackendProvider;
use OXPulse\Imager\Infrastructure\Local\LocalDeliveryInstaller;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use PHPUnit\Framework\TestCase;

class FeatureGateImgproxyTest extends TestCase
{
    /** Temp dir holding the miss-endpoint artifact for the BLOCKER
     *  tests. The artifact path is injected into the LocalBackendProvider
     *  via the oxpulse_delivery_backends filter (swapping the core
     *  local provider for one with an explicit path) — this avoids
     *  defining a competing WP_CONTENT_DIR (a define-once constant
     *  that would contaminate other test classes' assumptions). */
    private string $artifactDir;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_fs_stub'] = null;
        $GLOBALS['__oxpulse_is_multisite'] = false;
        $this->artifactDir = sys_get_temp_dir() . '/oxpulse-gate-imgproxy-' . uniqid();
        mkdir($this->artifactDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_filters'],
            $GLOBALS['__oxpulse_fs_stub'],
            $GLOBALS['__oxpulse_is_multisite'],
        );
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

    private function artifactPath(): string
    {
        return $this->artifactDir . '/' . LocalDeliveryInstaller::ENDPOINT_FILENAME;
    }

    /** Create the miss-endpoint artifact on disk (the healthy-local case). */
    private function installArtifact(): void
    {
        file_put_contents($this->artifactPath(), '<?php // present');
    }

    /**
     * Register a filter that swaps the core LocalBackendProvider for
     * one carrying an injected miss-endpoint artifact path, so the
     * BLOCKER tests exercise the REAL health() artifact check through
     * the REAL registry + select() without defining WP_CONTENT_DIR.
     */
    private function swapLocalProviderArtifact(): void
    {
        $artifact = $this->artifactPath();
        add_filter('oxpulse_delivery_backends', static function (array $providers) use ($artifact): array {
            foreach ($providers as $i => $p) {
                if ($p instanceof LocalBackendProvider) {
                    $providers[$i] = new LocalBackendProvider(new ImageTransformer(), $artifact);
                }
            }
            return $providers;
        }, 20, 1);
    }

    private function signing(): SigningConfig
    {
        return SigningConfig::fromHex(str_repeat('a', 64), str_repeat('b', 64));
    }

    private function imgproxyConfig(): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: [],
            outputFormat: 'auto',
            defaultQuality: 80,
            devHttpOverride: false,
            lqipEnabled: false,
            lqipBlur: 1,
            dprEnabled: false,
            dprVariants: [],
            watermark: null,
            formatQuality: [],
            sourceMode: 'http',
            localBasePath: '',
            bufferRewritingEnabled: false,
            pictureEnabled: false,
            rankMathCompatibility: true,
            saveDataQualityReduction: 15,
            sizeQualityTiers: [],
        );
    }

    /**
     * Register ONLY the imgproxy-delivery gate filter (the bootstrap
     * piece that strips ImgproxyBackendProvider under free). Mirrors
     * what ServiceRegistrar::register() wires in production without
     * pulling in the full bootstrap.
     */
    private function registerGateFilter(): void
    {
        $ref = new \ReflectionMethod(ServiceRegistrar::class, 'registerImgproxyDeliveryGate');
        $ref->setAccessible(true);
        $ref->invoke(null);
    }

    // ─── Pro: imgproxy selectable (unchanged) ────────────────────────

    public function test_pro_imgproxy_provider_present_in_registry(): void
    {
        add_filter('oxpulse_is_pro', '__return_true');
        $this->registerGateFilter();

        $registry = DeliveryBackendRegistry::default($this->imgproxyConfig(), $this->signing());
        $hasImgproxy = false;
        $ref = new \ReflectionProperty($registry, 'providers');
        $ref->setAccessible(true);
        foreach ($ref->getValue($registry) as $p) {
            if ($p instanceof ImgproxyBackendProvider) {
                $hasImgproxy = true;
                break;
            }
        }
        $this->assertTrue($hasImgproxy, 'Pro must keep the imgproxy provider registered');
    }

    // ─── Free: imgproxy NOT selectable ───────────────────────────────

    public function test_free_strips_imgproxy_provider_from_registry(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        $this->registerGateFilter();

        $registry = DeliveryBackendRegistry::default($this->imgproxyConfig(), $this->signing());
        $ref = new \ReflectionProperty($registry, 'providers');
        $ref->setAccessible(true);
        foreach ($ref->getValue($registry) as $p) {
            $this->assertNotInstanceOf(
                ImgproxyBackendProvider::class,
                $p,
                'Free must NOT register the imgproxy delivery backend provider',
            );
        }
    }

    // ─── BLOCKER: free + stored imgproxy endpoint → no 404 ───────────

    /**
     * BLOCKER: free + a stored imgproxy endpoint → gate 2 strips
     * ImgproxyBackendProvider → the registry would select
     * LocalBackendProvider (priority 50) — but the installer self-gated
     * (endpoint !== '') so the miss-endpoint artifact is ABSENT on disk.
     * LocalBackend would emit signed URLs to a non-existent endpoint →
     * 404 on every optimized <img> sitewide. health() MUST mark
     * LocalBackend Down so select() falls through to Passthrough
     * (build()=null → original URLs, unoptimized but WORKING).
     *
     * RED before fix: select() returns a LocalBackend (artifact check
     * absent from health()).
     */
    public function test_free_with_imgproxy_endpoint_selects_passthrough_not_local_with_absent_artifact(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        $this->registerGateFilter();
        // Swap the local provider for one whose artifact path is ABSENT
        // — mirrors a free user with a stored imgproxy endpoint where
        // install() self-gated (endpoint !== '') so no artifact exists.
        $this->swapLocalProviderArtifact();
        $this->assertFileDoesNotExist($this->artifactPath());

        $registry = DeliveryBackendRegistry::default($this->imgproxyConfig(), $this->signing());
        $backend = $registry->select($this->imgproxyConfig(), $this->signing());

        $this->assertNotInstanceOf(
            LocalBackend::class,
            $backend,
            'BLOCKER: free + imgproxy endpoint + absent artifact must NOT select LocalBackend (would 404)',
        );
        $this->assertNull(
            $backend,
            'select() must fall through to Passthrough (null) — original URLs served, no 404',
        );
    }

    /**
     * Healthy-local path must NOT regress: a real local site
     * (endpoint==='', artifact installed, encoder present) →
     * LocalBackend still Healthy + selected → free WebP works.
     * Under free the imgproxy gate is irrelevant (no endpoint), so
     * LocalBackend wins on priority.
     */
    public function test_healthy_local_path_still_selects_local_backend(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        $this->registerGateFilter();
        $this->installArtifact();
        $this->swapLocalProviderArtifact();
        $local = $this->localConfig();

        $registry = DeliveryBackendRegistry::default($local, $this->signing());
        $backend = $registry->select($local, $this->signing());

        $this->assertInstanceOf(
            LocalBackend::class,
            $backend,
            'Healthy local path: endpoint="" + artifact present + encoder → LocalBackend selected (free WebP works)',
        );
    }

    /**
     * A local site whose artifact was deleted (e.g. manual removal) →
     * LocalBackend Down → select() returns Passthrough (no 404).
     */
    public function test_local_site_with_deleted_artifact_falls_through_to_passthrough(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        $this->registerGateFilter();
        // Artifact absent (not installed) — swap injects the absent path.
        $this->swapLocalProviderArtifact();
        $local = $this->localConfig();

        $registry = DeliveryBackendRegistry::default($local, $this->signing());
        $backend = $registry->select($local, $this->signing());

        $this->assertNotInstanceOf(LocalBackend::class, $backend);
        $this->assertNull($backend, 'Deleted artifact → LocalBackend Down → Passthrough (null), no 404');
    }

    private function localConfig(): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: true,
            endpoint: '',
            allowedSources: [],
            outputFormat: 'auto',
            defaultQuality: 80,
            devHttpOverride: false,
            lqipEnabled: false,
            lqipBlur: 1,
            dprEnabled: false,
            dprVariants: [],
            watermark: null,
            formatQuality: [],
            sourceMode: 'http',
            localBasePath: '',
            bufferRewritingEnabled: false,
            pictureEnabled: false,
            rankMathCompatibility: true,
            saveDataQualityReduction: 15,
            sizeQualityTiers: [],
        );
    }
}
