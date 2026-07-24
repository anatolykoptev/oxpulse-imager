<?php
/**
 * Local delivery installer (Phase 6, Dispatch 3).
 *
 * Generates the self-contained oxpulse-img.php miss-endpoint file and
 * the cache-directory .htaccess at plugin activation AND on settings-save
 * — but ONLY when LocalBackend is the active delivery backend (no
 * imgproxy endpoint configured). On deactivation, removes both.
 *
 * When ImgproxyBackend is active (endpoint configured), install() is a
 * no-op: imgproxy manages its own cache, the local endpoint + .htaccess
 * are not needed and must not be left stale on disk.
 *
 * Wired from:
 *   - register_activation_hook   → ServiceRegistrar::installLocalDelivery()
 *   - register_deactivation_hook → ServiceRegistrar::uninstallLocalDelivery()
 *   - updated_option (endpoint/key/salt) → re-install on settings-save
 *
 * @package OXPulse\Imager\Infrastructure\Local
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\Local;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;

final class LocalDeliveryInstaller
{
    /**
     * The miss-endpoint artifact filename (written to wp-content/ by
     * install(), removed by uninstall()). The SINGLE source of truth
     * for the artifact basename — LocalBackendProvider::health()
     * references this constant to check the artifact exists on disk
     * (FIX: BLOCKER — LocalBackend selected but artifact absent → 404),
     * so the provider and installer never drift on the filename.
     */
    public const ENDPOINT_FILENAME = 'oxpulse-img.php';

    public function __construct(
        private string $wpContentDir,
        private string $uploadsBasedir,
        private string $uploadsBaseurl,
        private string $cacheDir,
        private string $cacheBaseUrl,
        private string $srcDir,
    ) {}

    /**
     * Generate the endpoint file + cache .htaccess when LocalBackend is
     * active. No-op when imgproxy is configured or signing is missing.
     *
     * @param DeliveryConfig $delivery Resolved (absolute-endpoint) delivery config.
     * @param SigningConfig|null $signing Signing config (null = no secrets yet).
     */
    public function install(DeliveryConfig $delivery, ?SigningConfig $signing): void
    {
        // Only install for LocalBackend (no imgproxy endpoint). When
        // imgproxy is configured, it manages its own cache — the local
        // endpoint + .htaccess must not be generated (and any stale ones
        // from a previous LocalBackend config should be removed).
        // #43 Phase 2 fold-in: use the shared isLocalBackendActive()
        // predicate (same idiom as ServiceRegistrar::recheckRewriteCapability).
        if (!$delivery->isLocalBackendActive()) {
            $this->uninstall();
            return;
        }

        // Without signing keys the endpoint cannot verify cache keys —
        // skip generation until secrets are saved.
        if ($signing === null) {
            return;
        }

        $endpointFile = $this->wpContentDir . '/' . self::ENDPOINT_FILENAME;

        // #47: thread the per-format AVIF quality override from the admin
        // formatQuality setting into the generated endpoint (baked as a
        // constant — the endpoint has no WP option access at runtime).
        $avifQuality = $delivery->formatQuality['avif'] ?? null;

        $generator = new MissEndpointGenerator();
        $generator->generate(
            outputFile: $endpointFile,
            signingKey: $signing->key,
            signingSalt: $signing->salt,
            uploadsBasedir: $this->uploadsBasedir,
            uploadsBaseurl: $this->uploadsBaseurl,
            cacheDir: $this->cacheDir,
            srcDir: $this->srcDir,
            avifQuality: $avifQuality,
            // Gate 1 (ProFeatures::AVIF): bake the AVIF eligibility into
            // the self-contained endpoint from the license seam at
            // generation time. The endpoint has no WP access at request
            // time, so the gate is resolved here. Re-installed on every
            // settings-save + activation + version re-probe.
            avifAllowed: ServiceRegistrar::isPro(),
        );

        // Cache dir .htaccess (miss → endpoint routing).
        if (!is_dir($this->cacheDir)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- cache dir creation; wp_mkdir_p uses FS_CHMOD_DIR which may differ from 0755.
            @mkdir($this->cacheDir, 0755, true);
        }
        $htaccessGen = new HtaccessGenerator();
        $htaccess = $htaccessGen->generate(
            cacheBaseUrl: $this->cacheBaseUrl,
            endpointRelPath: 'oxpulse-img.php',
        );
        file_put_contents($this->cacheDir . '/.htaccess', $htaccess);
    }

    /**
     * Remove the endpoint file + cache .htaccess (deactivation, or
     * switching from LocalBackend to ImgproxyBackend).
     */
    public function uninstall(): void
    {
        $endpointFile = $this->wpContentDir . '/' . self::ENDPOINT_FILENAME;
        if (is_file($endpointFile)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- plugin-owned cache scratch; direct unlink avoids the wp_delete_file filter, no WP_Filesystem needed.
            @unlink($endpointFile);
        }

        $htaccess = $this->cacheDir . '/.htaccess';
        if (is_file($htaccess)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- plugin-owned cache scratch; direct unlink avoids the wp_delete_file filter, no WP_Filesystem needed.
            @unlink($htaccess);
        }
    }
}
