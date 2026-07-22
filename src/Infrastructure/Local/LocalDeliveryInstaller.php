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

final class LocalDeliveryInstaller
{
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

        $endpointFile = $this->wpContentDir . '/oxpulse-img.php';

        $generator = new MissEndpointGenerator();
        $generator->generate(
            outputFile: $endpointFile,
            signingKey: $signing->key,
            signingSalt: $signing->salt,
            uploadsBasedir: $this->uploadsBasedir,
            uploadsBaseurl: $this->uploadsBaseurl,
            cacheDir: $this->cacheDir,
            srcDir: $this->srcDir,
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
        $endpointFile = $this->wpContentDir . '/oxpulse-img.php';
        if (is_file($endpointFile)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- endpoint removal; wp_delete_file has FTP-fallback side effects that change behavior.
            @unlink($endpointFile);
        }

        $htaccess = $this->cacheDir . '/.htaccess';
        if (is_file($htaccess)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- htaccess removal; wp_delete_file has FTP-fallback side effects that change behavior.
            @unlink($htaccess);
        }
    }
}
