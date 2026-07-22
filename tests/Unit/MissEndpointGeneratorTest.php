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

    // --- FIX #31: signing key baked WORLD-READABLE in oxpulse-img.php ---
    //
    // file_put_contents creates the file 0644 by default; the baked
    // signing key would then be world-readable on shared hosting → a
    // co-tenant reads it → forges signatures. After a successful write
    // the file MUST be chmod'd to 0600 (owner-only).

    public function test_generated_endpoint_file_has_0600_permissions(): void
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

        $perms = fileperms($path) & 0777;
        $this->assertSame(0600, $perms, 'Generated endpoint must be 0600 (owner-only) so the baked signing key is not world-readable.');
    }

    // --- FIX #30: silent file_put_contents failure breaks all images on
    // key rotation ---
    //
    // The write return was NOT checked; on a failed write (perms/disk-full)
    // the generator silently "succeeded" → the on-disk endpoint kept the
    // OLD signing key while UrlRewriter signed with the NEW key → every
    // image 400s. A failed write MUST surface as an error (throw), and
    // MUST NOT return the path as if OK. Atomic write (temp + chmod +
    // rename) ensures a partial/failed write never replaces a working
    // endpoint.

    public function test_write_failure_throws_and_does_not_return_path(): void
    {
        $generator = new MissEndpointGenerator();

        // Target inside a non-existent directory → file_put_contents
        // returns false (cannot open stream).
        $badPath = $this->outputDir . '/no-such-subdir/oxpulse-img.php';

        $this->expectException(\RuntimeException::class);
        try {
            $generator->generate(
                outputFile: $badPath,
                signingKey: 'key',
                signingSalt: 'salt',
                uploadsBasedir: '/uploads',
                uploadsBaseurl: 'https://example.com/uploads',
                cacheDir: '/cache',
                autoloaderPath: '/autoload.php',
            );
            $this->fail('generate() must throw on write failure, not return the path.');
        } catch (\RuntimeException $e) {
            // Re-throw to satisfy expectException; assert the bad path
            // was NOT created (no partial endpoint left behind).
            $this->assertFileDoesNotExist($badPath);
            throw $e;
        }
    }

    public function test_atomic_write_leaves_no_temp_file_on_success(): void
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

        $this->assertFileExists($path);
        $entries = glob($this->outputDir . '/{*,.*}', GLOB_BRACE);
        $basenames = array_map('basename', $entries);
        foreach ($basenames as $name) {
            $this->assertStringNotContainsString('.tmp', $name, 'No temp file must remain after a successful atomic write.');
        }
    }

    // --- FIX #33: unescaped path interpolation breaks the generated
    // endpoint ---
    //
    // MissEndpointGenerator bakes absolute paths (uploads base, cache dir,
    // autoloader) into single-quoted PHP string literals. If a path
    // contains an apostrophe (legit on some hosts), the literal breaks →
    // parse error → endpoint 500s. Paths MUST be emitted via var_export
    // (or addslashes) so ANY path round-trips safely.

    public function test_path_with_apostrophe_produces_valid_php(): void
    {
        $generator = new MissEndpointGenerator();
        $path = $generator->generate(
            outputFile: $this->outputDir . '/oxpulse-img.php',
            signingKey: 'key',
            signingSalt: 'salt',
            // Apostrophe in every baked path.
            uploadsBasedir: "/var/www/someone's-site/wp-content/uploads",
            uploadsBaseurl: "https://example.com/someone's-site/wp-content/uploads",
            cacheDir: "/var/www/someone's-site/wp-content/cache/oxpulse",
            autoloaderPath: "/var/www/someone's-site/wp-content/plugins/oxpulse-imager/vendor/autoload.php",
        );

        $output = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors', $output, 'A baked path with an apostrophe must produce syntactically valid PHP.');
    }

    public function test_path_with_apostrophe_round_trips_as_constant(): void
    {
        $generator = new MissEndpointGenerator();
        $uploadsBasedir = "/var/www/someone's-site/wp-content/uploads";
        $path = $generator->generate(
            outputFile: $this->outputDir . '/oxpulse-img.php',
            signingKey: 'key',
            signingSalt: 'salt',
            uploadsBasedir: $uploadsBasedir,
            uploadsBaseurl: 'https://example.com/uploads',
            cacheDir: '/cache',
            autoloaderPath: '/autoload.php',
        );

        // The generated file must define the constant to the EXACT
        // original string (round-trip). Eval the define() in isolation
        // by extracting the file's define() lines into a scratch script.
        $content = file_get_contents($path);
        $this->assertStringContainsString("define('OXPULSE_UPLOADS_BASEDIR'", $content);

        $scratch = $this->outputDir . '/scratch-roundtrip.php';
        $php = "<?php\n";
        // Pull just the OXPULSE_UPLOADS_BASEDIR define line.
        if (preg_match("/define\('OXPULSE_UPLOADS_BASEDIR', [^;]+;/", $content, $m)) {
            $php .= $m[0] . "\n";
        }
        $php .= "echo OXPULSE_UPLOADS_BASEDIR;\n";
        file_put_contents($scratch, $php);

        $resolved = shell_exec('php ' . escapeshellarg($scratch) . ' 2>&1');
        $this->assertSame($uploadsBasedir, $resolved, 'Baked path with apostrophe must round-trip to the exact original string.');
    }
}
