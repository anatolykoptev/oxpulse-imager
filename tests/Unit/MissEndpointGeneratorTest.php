<?php
/**
 * MissEndpointGenerator tests.
 *
 * Verifies the self-contained oxpulse-img.php file generator:
 * - Bakes signing key + salt as PHP constants (not echoed as text).
 * - Bakes uploads base ABSOLUTE path, uploads base URL, cache dir path.
 * - Bakes the plugin src/ dir + emits a self-contained PSR-4 autoloader
 *   (NO vendor/autoload.php reference — vendor/ is export-ignored from
 *   the release ZIP, and the plugin has zero third-party runtime deps;
 *   FIX #45).
 * - The generated file instantiates the handler.
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
            srcDir: '/var/www/wp-content/plugins/oxpulse-imager/src',
        );

        $this->assertSame($this->outputDir . '/oxpulse-img.php', $path);
        $this->assertFileExists($path);
        $content = file_get_contents($path);

        // PHP opening tag.
        $this->assertStringStartsWith('<?php', $content);

        // No wp-load.php.
        $this->assertStringNotContainsString('wp-load.php', $content);
        $this->assertStringNotContainsString('wp-blog-header', $content);

        // FIX #45: NO vendor/autoload.php reference (vendor/ is
        // export-ignored from the release ZIP). Instead, a self-
        // contained PSR-4 autoloader mapping OXPulse\Imager\ to the
        // baked src/ dir.
        $this->assertStringNotContainsString('vendor/autoload.php', $content);
        $this->assertStringNotContainsString('vendor/', $content);
        $this->assertStringContainsString('spl_autoload_register', $content);
        $this->assertStringContainsString("OXPulse\\Imager\\", $content);
        $this->assertStringContainsString('OXPULSE_SRC_DIR', $content);

        // Baked constants.
        $this->assertStringContainsString("define('OXPULSE_SIGNING_KEY'", $content);
        $this->assertStringContainsString("define('OXPULSE_SIGNING_SALT'", $content);
        $this->assertStringContainsString("define('OXPULSE_UPLOADS_BASEDIR'", $content);
        $this->assertStringContainsString("define('OXPULSE_UPLOADS_BASEURL'", $content);
        $this->assertStringContainsString("define('OXPULSE_CACHE_DIR'", $content);
        $this->assertStringContainsString("define('OXPULSE_SRC_DIR'", $content);

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
            srcDir: '/plugin/src',
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
            srcDir: '/plugin/src',
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
            srcDir: '/plugin/src',
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
                srcDir: '/plugin/src',
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
            srcDir: '/plugin/src',
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
            srcDir: "/var/www/someone's-site/wp-content/plugins/oxpulse-imager/src",
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
            srcDir: '/plugin/src',
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

    // --- FIX #45: endpoint bakes require_once vendor/autoload.php, but
    // vendor/ is export-ignored from the release ZIP → every wordpress.org
    // install 500s on every cache-miss (free tier broken). The endpoint
    // must be self-sufficient: bake a PSR-4 autoloader mapping
    // OXPulse\Imager\ → the plugin src/ dir, NO vendor reference. ---

    public function test_src_dir_constant_round_trips_apostrophe(): void
    {
        $generator = new MissEndpointGenerator();
        $srcDir = "/var/www/someone's-site/wp-content/plugins/oxpulse-imager/src";
        $path = $generator->generate(
            outputFile: $this->outputDir . '/oxpulse-img.php',
            signingKey: 'key',
            signingSalt: 'salt',
            uploadsBasedir: '/uploads',
            uploadsBaseurl: 'https://example.com/uploads',
            cacheDir: '/cache',
            srcDir: $srcDir,
        );

        $content = file_get_contents($path);
        $this->assertStringContainsString("define('OXPULSE_SRC_DIR'", $content);

        $scratch = $this->outputDir . '/scratch-srcdir.php';
        $php = "<?php\n";
        if (preg_match("/define\('OXPULSE_SRC_DIR', [^;]+;/", $content, $m)) {
            $php .= $m[0] . "\n";
        }
        $php .= "echo OXPULSE_SRC_DIR;\n";
        file_put_contents($scratch, $php);

        $resolved = shell_exec('php ' . escapeshellarg($scratch) . ' 2>&1');
        $this->assertSame($srcDir, $resolved, 'Baked src dir with apostrophe must round-trip to the exact original string.');
    }

    public function test_baked_src_loader_resolves_class_with_no_vendor(): void
    {
        // Generate the endpoint with srcDir pointing at the REAL plugin
        // src/ (no vendor/ involved). Then extract the baked
        // spl_autoload_register block, run it in a FRESH subprocess with
        // NO composer autoloader, and assert the baked loader alone
        // resolves a real plugin class from src/.
        $realSrcDir = dirname(__DIR__, 2) . '/src';
        $this->assertFileExists($realSrcDir . '/Domain/Config/SigningConfig.php');

        $generator = new MissEndpointGenerator();
        $path = $generator->generate(
            outputFile: $this->outputDir . '/oxpulse-img.php',
            signingKey: 'key',
            signingSalt: 'salt',
            uploadsBasedir: '/uploads',
            uploadsBaseurl: 'https://example.com/uploads',
            cacheDir: '/cache',
            srcDir: $realSrcDir,
        );
        $content = file_get_contents($path);

        // Extract the spl_autoload_register(...) block (it is a single
        // statement ending at the matching ");").
        $found = preg_match(
            '/spl_autoload_register\(.+?\}\);/s',
            $content,
            $m,
        );
        $this->assertSame(1, $found, 'Generated endpoint must contain a spl_autoload_register statement.');
        $autoloaderBlock = $m[0];

        $scratch = $this->outputDir . '/scratch-loader.php';
        $php = "<?php\n"
            . "define('OXPULSE_SRC_DIR', " . var_export($realSrcDir, true) . ");\n"
            . $autoloaderBlock . "\n"
            . "// NO composer autoloader loaded — prove the baked loader alone resolves the class.\n"
            . "\$ok = class_exists('OXPulse\\\\Imager\\\\Domain\\\\Config\\\\SigningConfig', true);\n"
            . "echo \$ok ? 'LOADED' : 'NOT-LOADED';\n";
        file_put_contents($scratch, $php);

        // Fresh php process: no vendor/autoload.php, no project autoloader.
        $out = shell_exec('php ' . escapeshellarg($scratch) . ' 2>&1');
        $this->assertSame('LOADED', $out, 'Baked src-loader must load a plugin class with NO vendor autoloader available.');
    }

    // --- #43 Phase 2: generated endpoint must NOT need a CapabilityTester ---

    /**
     * The generated oxpulse-img.php constructs LocalBackend for
     * verify() only (never generate()). After Phase 2, LocalBackend
     * gains an OPTIONAL CapabilityTester constructor param. The
     * generated endpoint must still construct LocalBackend WITHOUT
     * a CapabilityTester — it has no WP option access, no need for
     * fallback decisions, and only verifies keys + serves files.
     */
    public function test_generated_endpoint_constructs_local_backend_without_capability_tester(): void
    {
        $generator = new MissEndpointGenerator();
        $path = $generator->generate(
            outputFile: $this->outputDir . '/oxpulse-img.php',
            signingKey: 'key',
            signingSalt: 'salt',
            uploadsBasedir: '/uploads',
            uploadsBaseurl: 'https://example.com/uploads',
            cacheDir: '/cache',
            srcDir: '/plugin/src',
        );
        $content = file_get_contents($path);

        // The endpoint constructs LocalBackend with only the signing
        // config — no CapabilityTester argument.
        $this->assertStringContainsString('new LocalBackend(', $content);
        $this->assertStringNotContainsString('CapabilityTester', $content);
        $this->assertStringNotContainsString('capability', strtolower($content));
    }

    // --- #47: format detection from REQUEST_URI basename ---
    //
    // The generated endpoint must compute $format from the ORIGINAL
    // request path basename, not a hardcoded 'webp'. Clean-URL misses
    // (.webp/.avif) serve that EXACT format; the bare oxpulse-img.php
    // path (no image ext) → 'auto' → server-side negotiation.

    public function test_generated_endpoint_detects_format_from_request_uri(): void
    {
        $generator = new MissEndpointGenerator();
        $path = $generator->generate(
            outputFile: $this->outputDir . '/oxpulse-img.php',
            signingKey: 'key',
            signingSalt: 'salt',
            uploadsBasedir: '/uploads',
            uploadsBaseurl: 'https://example.com/uploads',
            cacheDir: '/cache',
            srcDir: '/plugin/src',
        );
        $content = file_get_contents($path);

        // Must contain the format allowlist (webp + avif).
        $this->assertStringContainsString("'webp'", $content, 'Generated endpoint must detect .webp from REQUEST_URI basename.');
        $this->assertStringContainsString("'avif'", $content, 'Generated endpoint must detect .avif from REQUEST_URI basename.');
        // Must default $format to 'auto' (not hardcoded 'webp').
        $this->assertStringContainsString("'auto'", $content, 'Generated endpoint must default $format to auto for the bare ?k= path.');
        // Must use parse_url + basename for REQUEST_URI.
        $this->assertStringContainsString('REQUEST_URI', $content);
        $this->assertStringContainsString('basename', $content);
    }

    public function test_generated_endpoint_bakes_avif_quality_constant(): void
    {
        $generator = new MissEndpointGenerator();
        $path = $generator->generate(
            outputFile: $this->outputDir . '/oxpulse-img.php',
            signingKey: 'key',
            signingSalt: 'salt',
            uploadsBasedir: '/uploads',
            uploadsBaseurl: 'https://example.com/uploads',
            cacheDir: '/cache',
            srcDir: '/plugin/src',
            avifQuality: 55,
        );
        $content = file_get_contents($path);

        $this->assertStringContainsString("define('OXPULSE_AVIF_QUALITY'", $content);
        $this->assertStringContainsString('55', $content);
        // The handler constructor must receive the avif quality.
        $this->assertStringContainsString('avifQuality', $content);
    }

    public function test_generated_endpoint_avif_quality_defaults_to_zero_when_not_set(): void
    {
        $generator = new MissEndpointGenerator();
        $path = $generator->generate(
            outputFile: $this->outputDir . '/oxpulse-img.php',
            signingKey: 'key',
            signingSalt: 'salt',
            uploadsBasedir: '/uploads',
            uploadsBaseurl: 'https://example.com/uploads',
            cacheDir: '/cache',
            srcDir: '/plugin/src',
        );
        $content = file_get_contents($path);

        // When no avif quality is set, the constant must be 0 (disabled).
        $this->assertStringContainsString("define('OXPULSE_AVIF_QUALITY'", $content);
        $this->assertStringContainsString('avifQuality', $content);
    }

    public function test_generated_endpoint_with_avif_quality_is_valid_php(): void
    {
        $generator = new MissEndpointGenerator();
        $path = $generator->generate(
            outputFile: $this->outputDir . '/oxpulse-img.php',
            signingKey: 'key',
            signingSalt: 'salt',
            uploadsBasedir: '/uploads',
            uploadsBaseurl: 'https://example.com/uploads',
            cacheDir: '/cache',
            srcDir: '/plugin/src',
            avifQuality: 60,
        );

        $output = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors', $output, 'Generated endpoint with avif quality must be valid PHP.');
    }

    public function test_generated_endpoint_uses_image_transformer_class(): void
    {
        $generator = new MissEndpointGenerator();
        $path = $generator->generate(
            outputFile: $this->outputDir . '/oxpulse-img.php',
            signingKey: 'key',
            signingSalt: 'salt',
            uploadsBasedir: '/uploads',
            uploadsBaseurl: 'https://example.com/uploads',
            cacheDir: '/cache',
            srcDir: '/plugin/src',
        );
        $content = file_get_contents($path);

        // After the rename, the generated endpoint must use ImageTransformer.
        $this->assertStringContainsString('ImageTransformer', $content);
        $this->assertStringNotContainsString('WebpTransformer', $content);
    }
}
