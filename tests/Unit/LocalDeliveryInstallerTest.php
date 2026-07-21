<?php
/**
 * LocalDeliveryInstaller tests.
 *
 * Verifies the installer that generates the self-contained
 * oxpulse-img.php miss-endpoint + the cache-dir .htaccess at
 * activation / settings-save time, and cleans them up on
 * deactivation. Only active when LocalBackend is selected
 * (no imgproxy endpoint); a no-op when ImgproxyBackend is active.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Infrastructure\Local\LocalDeliveryInstaller;
use PHPUnit\Framework\TestCase;

class LocalDeliveryInstallerTest extends TestCase
{
    private string $wpContentDir;
    private string $uploadsBasedir;
    private string $cacheDir;
    private string $autoloaderPath;
    private string $uploadsBaseurl;
    private string $cacheBaseUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpContentDir = sys_get_temp_dir() . '/oxpulse-inst-wpcontent-' . uniqid();
        $this->uploadsBasedir = sys_get_temp_dir() . '/oxpulse-inst-uploads-' . uniqid();
        $this->cacheDir = $this->wpContentDir . '/cache/oxpulse';
        $this->autoloaderPath = sys_get_temp_dir() . '/oxpulse-inst-vendor-' . uniqid() . '/autoload.php';
        $this->uploadsBaseurl = 'https://example.com/wp-content/uploads';
        $this->cacheBaseUrl = 'https://example.com/wp-content/cache/oxpulse';

        mkdir($this->wpContentDir, 0755, true);
        mkdir($this->uploadsBasedir, 0755, true);
        mkdir(dirname($this->autoloaderPath), 0755, true);
        file_put_contents($this->autoloaderPath, '<?php // stub autoloader');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rmrf($this->wpContentDir);
        $this->rmrf($this->uploadsBasedir);
        $this->rmrf(dirname($this->autoloaderPath));
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

    private function installer(): LocalDeliveryInstaller
    {
        return new LocalDeliveryInstaller(
            wpContentDir: $this->wpContentDir,
            uploadsBasedir: $this->uploadsBasedir,
            uploadsBaseurl: $this->uploadsBaseurl,
            cacheDir: $this->cacheDir,
            cacheBaseUrl: $this->cacheBaseUrl,
            autoloaderPath: $this->autoloaderPath,
        );
    }

    private function localDelivery(): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: true,
            endpoint: '',
            allowedSources: ['https://example.com/wp-content/uploads/'],
        );
    }

    private function imgproxyDelivery(): DeliveryConfig
    {
        return new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: ['https://example.com/wp-content/uploads/'],
        );
    }

    private function signing(): SigningConfig
    {
        return SigningConfig::fromHex(
            'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            'f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3b2a1f6e5'
        );
    }

    public function test_install_local_generates_endpoint_and_htaccess(): void
    {
        $installer = $this->installer();

        $installer->install($this->localDelivery(), $this->signing());

        // Endpoint file at wp-content/oxpulse-img.php.
        $this->assertFileExists($this->wpContentDir . '/oxpulse-img.php');
        $endpointContent = file_get_contents($this->wpContentDir . '/oxpulse-img.php');
        $this->assertStringStartsWith('<?php', $endpointContent);
        $this->assertStringContainsString('MissEndpointHandler', $endpointContent);

        // Cache dir .htaccess.
        $this->assertFileExists($this->cacheDir . '/.htaccess');
        $htaccess = file_get_contents($this->cacheDir . '/.htaccess');
        $this->assertStringContainsString('RewriteEngine On', $htaccess);
        $this->assertStringContainsString('oxpulse-img.php', $htaccess);
    }

    public function test_install_imgproxy_is_noop(): void
    {
        $installer = $this->installer();

        $installer->install($this->imgproxyDelivery(), $this->signing());

        // No endpoint file, no .htaccess when imgproxy is active.
        $this->assertFileDoesNotExist($this->wpContentDir . '/oxpulse-img.php');
        $this->assertFileDoesNotExist($this->cacheDir . '/.htaccess');
    }

    public function test_install_without_signing_is_noop(): void
    {
        $installer = $this->installer();

        // No signing config → cannot sign keys → no endpoint.
        $installer->install($this->localDelivery(), null);

        $this->assertFileDoesNotExist($this->wpContentDir . '/oxpulse-img.php');
    }

    public function test_uninstall_removes_endpoint_and_htaccess(): void
    {
        $installer = $this->installer();
        $installer->install($this->localDelivery(), $this->signing());

        $this->assertFileExists($this->wpContentDir . '/oxpulse-img.php');

        $installer->uninstall();

        $this->assertFileDoesNotExist($this->wpContentDir . '/oxpulse-img.php');
        $this->assertFileDoesNotExist($this->cacheDir . '/.htaccess');
    }

    public function test_uninstall_is_noop_when_not_installed(): void
    {
        $installer = $this->installer();

        // No files exist yet — uninstall must not error.
        $installer->uninstall();

        $this->expectNotToPerformAssertions();
    }

    public function test_reinstall_overwrites_existing_endpoint(): void
    {
        $installer = $this->installer();
        $installer->install($this->localDelivery(), $this->signing());

        // Write a stale marker to confirm reinstall overwrites.
        file_put_contents($this->wpContentDir . '/oxpulse-img.php', 'STALE');

        $installer->install($this->localDelivery(), $this->signing());

        $content = file_get_contents($this->wpContentDir . '/oxpulse-img.php');
        $this->assertStringStartsWith('<?php', $content);
        $this->assertStringNotContainsString('STALE', $content);
    }
}
