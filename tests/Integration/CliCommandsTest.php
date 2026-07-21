<?php
/**
 * WP-CLI command tests.
 *
 * Tests the 4 subcommands (status, info, warm, flush) without a real
 * WP-CLI environment — the AbstractCommand base class falls back to
 * echo/throw when \WP_CLI is not available, which is exactly the test
 * setup.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Integration;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Integration\WordPress\Cli\StatusCommand;
use OXPulse\Imager\Integration\WordPress\Cli\InfoCommand;
use OXPulse\Imager\Integration\WordPress\Cli\WarmCommand;
use OXPulse\Imager\Integration\WordPress\Cli\FlushCommand;
use PHPUnit\Framework\TestCase;

class CliCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_attachment_urls'] = [];
        $GLOBALS['__oxpulse_attachment_meta'] = [];
        $GLOBALS['__oxpulse_posts'] = [];
        $GLOBALS['__oxpulse_http_responses'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
        unset($GLOBALS['__oxpulse_attachment_urls']);
        unset($GLOBALS['__oxpulse_attachment_meta']);
        unset($GLOBALS['__oxpulse_posts']);
        parent::tearDown();
    }

    private function setupFullConfig(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_enabled'] = true;
        $GLOBALS['__oxpulse_options']['oxpulse_imager_endpoint'] = 'https://imgproxy.example.com';
        $GLOBALS['__oxpulse_options']['oxpulse_imager_allowed_sources'] = ['https://example.com/uploads/'];
        $GLOBALS['__oxpulse_options']['oxpulse_imager_key'] = bin2hex(random_bytes(16));
        $GLOBALS['__oxpulse_options']['oxpulse_imager_salt'] = bin2hex(random_bytes(16));
        $GLOBALS['__oxpulse_options']['oxpulse_imager_output_format'] = 'auto';
        $GLOBALS['__oxpulse_options']['oxpulse_imager_default_quality'] = 80;
    }

    public function test_status_command_outputs_config_with_no_health(): void
    {
        $this->setupFullConfig();
        $command = new StatusCommand();

        ob_start();
        $command->status([], ['no-health' => true]);
        $output = ob_get_clean();

        $this->assertStringContainsString('OXPulse Imager status', $output);
        $this->assertStringContainsString('Delivery enabled: yes', $output);
        $this->assertStringContainsString('Endpoint: https://imgproxy.example.com', $output);
        $this->assertStringContainsString('Signing: configured', $output);
        $this->assertStringContainsString('(skipped via --no-health)', $output);
    }

    public function test_status_command_warns_when_no_endpoint(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_enabled'] = true;
        $command = new StatusCommand();

        ob_start();
        $command->status([], ['no-health' => true]);
        $output = ob_get_clean();

        $this->assertStringContainsString('Endpoint: (not configured)', $output);
    }

    public function test_info_command_shows_imgproxy_url_when_authorized(): void
    {
        $this->setupFullConfig();
        $command = new InfoCommand();

        ob_start();
        $command->info(
            ['https://example.com/uploads/photo.jpg'],
            ['width' => 800]
        );
        $output = ob_get_clean();

        $this->assertStringContainsString('Source URL: https://example.com/uploads/photo.jpg', $output);
        $this->assertStringContainsString('Target width: 800px', $output);
        $this->assertStringContainsString('Result: REWRITTEN', $output);
        $this->assertStringContainsString('https://imgproxy.example.com/', $output);
    }

    public function test_info_command_shows_preserved_when_unauthorized(): void
    {
        $this->setupFullConfig();
        $command = new InfoCommand();

        ob_start();
        $command->info(['https://evil.com/uploads/photo.jpg'], []);
        $output = ob_get_clean();

        $this->assertStringContainsString('Result: PRESERVED', $output);
        $this->assertStringNotContainsString('https://imgproxy.example.com/', $output);
    }

    public function test_info_command_warns_when_delivery_disabled(): void
    {
        $command = new InfoCommand();

        ob_start();
        $command->info(['https://example.com/uploads/photo.jpg'], []);
        $output = ob_get_clean();

        $this->assertStringContainsString('Delivery is disabled', $output);
    }

    public function test_info_command_errors_when_no_url(): void
    {
        $command = new InfoCommand();

        $this->expectException(\RuntimeException::class);
        $command->info([], []);
    }

    public function test_warm_command_errors_when_delivery_disabled(): void
    {
        $command = new WarmCommand();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Delivery is disabled');
        $command->warm(['https://example.com/uploads/photo.jpg'], []);
    }

    public function test_warm_command_errors_when_no_endpoint(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_enabled'] = true;
        $command = new WarmCommand();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No imgproxy endpoint configured');
        $command->warm(['https://example.com/uploads/photo.jpg'], []);
    }

    public function test_warm_command_errors_when_no_signing(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_enabled'] = true;
        $GLOBALS['__oxpulse_options']['oxpulse_imager_endpoint'] = 'https://imgproxy.example.com';
        $command = new WarmCommand();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No signing secrets configured');
        $command->warm(['https://example.com/uploads/photo.jpg'], []);
    }

    public function test_warm_command_errors_when_no_urls(): void
    {
        $this->setupFullConfig();
        $command = new WarmCommand();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No URLs to warm');
        $command->warm([], []);
    }

    public function test_warm_command_with_attachment_resolves_urls(): void
    {
        $this->setupFullConfig();
        $GLOBALS['__oxpulse_attachment_urls'][42] = 'https://example.com/uploads/2026/01/photo.jpg';
        $GLOBALS['__oxpulse_attachment_meta'][42] = [
            'sizes' => [
                'thumbnail' => ['file' => 'photo-150x150.jpg'],
                'medium'    => ['file' => 'photo-300x300.jpg'],
            ],
        ];

        $command = new WarmCommand();

        ob_start();
        try {
            $command->warm([], ['attachment' => 42]);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            $output = ob_get_clean();
            // HTTP dispatch will fail (no real imgproxy) — but the URL
            // enumeration should have run.
        }

        $this->assertStringContainsString('Warming 3 URL(s)', $output);
        $this->assertStringContainsString('photo.jpg', $output);
        $this->assertStringContainsString('photo-150x150.jpg', $output);
        $this->assertStringContainsString('photo-300x300.jpg', $output);
    }

    public function test_flush_command_succeeds(): void
    {
        $command = new FlushCommand();

        ob_start();
        $command->flush([], []);
        $output = ob_get_clean();

        $this->assertStringContainsString('Success: Flushed', $output);
    }

    public function test_flush_command_purges_local_cache_dir(): void
    {
        $cacheDir = sys_get_temp_dir() . '/oxpulse-flush-' . uniqid();
        mkdir($cacheDir . '/abc123def456abc1', 0755, true);
        file_put_contents($cacheDir . '/abc123def456abc1/key.webp', 'bytes');

        $command = new FlushCommand(null, $cacheDir);

        ob_start();
        $command->flush([], []);
        $output = ob_get_clean();

        $this->assertStringContainsString('Success: Flushed', $output);
        $this->assertStringContainsString('local cache', $output);
        $this->assertFileDoesNotExist($cacheDir . '/abc123def456abc1');

        // Cleanup.
        if (is_dir($cacheDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($cacheDir);
        }
    }

    public function test_cli_service_provider_no_op_without_wp_cli(): void
    {
        // CliServiceProvider::register() should silently return when
        // \WP_CLI is not available (the test env).
        \OXPulse\Imager\Integration\WordPress\Cli\CliServiceProvider::register();
        $this->assertTrue(true); // No exception thrown = pass.
    }
}
