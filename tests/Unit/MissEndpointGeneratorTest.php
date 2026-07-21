<?php
/**
 * MissEndpointGenerator tests.
 *
 * Verifies the self-contained oxpulse-img.php file generator:
 * - Bakes signing key + salt as PHP constants (not echoed as text).
 * - Bakes uploads base ABSOLUTE path, uploads base URL, cache dir path.
 * - Bakes the autoloader path.
 * - The generated file requires the autoloader + instantiates the handler.
 * - No wp-load.php reference.
 * - The signing secret appears only inside a PHP constant (not in a
 *   web-readable echo/print).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Local\MissEndpointGenerator;
use PHPUnit\Framework\TestCase;

class MissEndpointGeneratorTest extends TestCase
{
    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputDir = sys_get_temp_dir() . '/oxpulse-gen-' . uniqid();
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->outputDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->outputDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->outputDir);
        }
    }

    public function test_generates_self_contained_php_file(): void
    {
        $generator = new MissEndpointGenerator();
        $path = $generator->generate(
            outputFile: $this->outputDir . '/oxpulse-img.php',
            signingKey: 'raw-binary-key-bytes',
            signingSalt: 'raw-binary-salt-bytes',
            uploadsBasedir: '/var/www/wp-content/uploads',
            uploadsBaseurl: 'https://example.com/wp-content/uploads',
            cacheDir: '/var/www/wp-content/cache/oxpulse',
            autoloaderPath: '/var/www/wp-content/plugins/oxpulse-imager/vendor/autoload.php',
        );

        $this->assertSame($this->outputDir . '/oxpulse-img.php', $path);
        $this->assertFileExists($path);
        $content = file_get_contents($path);

        // PHP opening tag.
        $this->assertStringStartsWith('<?php', $content);

        // No wp-load.php.
        $this->assertStringNotContainsString('wp-load.php', $content);
        $this->assertStringNotContainsString('wp-blog-header', $content);

        // Requires the autoloader.
        $this->assertStringContainsString("require_once '/var/www/wp-content/plugins/oxpulse-imager/vendor/autoload.php'", $content);

        // Baked constants.
        $this->assertStringContainsString("define('OXPULSE_SIGNING_KEY'", $content);
        $this->assertStringContainsString("define('OXPULSE_SIGNING_SALT'", $content);
        $this->assertStringContainsString("define('OXPULSE_UPLOADS_BASEDIR'", $content);
        $this->assertStringContainsString("define('OXPULSE_UPLOADS_BASEURL'", $content);
        $this->assertStringContainsString("define('OXPULSE_CACHE_DIR'", $content);

        // The signing key is baked as a base64 constant (not raw binary
        // in the source, not echoed).
        $this->assertStringContainsString('base64_decode(', $content);

        // The raw binary key must NOT appear literally in the file.
        $this->assertStringNotContainsString('raw-binary-key-bytes', $content);
        $this->assertStringNotContainsString('raw-binary-salt-bytes', $content);

        // Instantiates the handler + calls handle().
        $this->assertStringContainsString('MissEndpointHandler', $content);
        $this->assertStringContainsString('->handle(', $content);

        // No echo of the secret.
        $this->assertStringNotContainsString('echo $key', $content);
        $this->assertStringNotContainsString('echo $salt', $content);
        $this->assertStringNotContainsString('print $key', $content);
    }

    public function test_generated_file_is_valid_php_syntax(): void
    {
        $generator = new MissEndpointGenerator();
        $path = $generator->generate(
            outputFile: $this->outputDir . '/oxpulse-img.php',
            signingKey: 'key',
            signingSalt: 'salt',
            uploadsBasedir: '/uploads',
            uploadsBaseurl: 'https://example.com/uploads',
            cacheDir: '/cache',
            autoloaderPath: '/autoload.php',
        );

        $output = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors', $output);
    }

    public function test_generated_file_resolves_key_from_query_or_path(): void
    {
        $generator = new MissEndpointGenerator();
        $path = $generator->generate(
            outputFile: $this->outputDir . '/oxpulse-img.php',
            signingKey: 'key',
            signingSalt: 'salt',
            uploadsBasedir: '/uploads',
            uploadsBaseurl: 'https://example.com/uploads',
            cacheDir: '/cache',
            autoloaderPath: '/autoload.php',
        );
        $content = file_get_contents($path);

        // Resolves key from $_GET['k'].
        $this->assertStringContainsString('$_GET', $content);
        $this->assertStringContainsString("'k'", $content);
    }
}
