<?php
/**
 * LocalRewriteProbe tests.
 *
 * Verifies the live HTTP self-probe that determines whether .htaccess
 * rewrite rules fire for the cache dir. A fake HttpRequester is injected
 * so no real HTTP round-trip occurs. The probe writes a test .htaccess +
 * 0.txt/1.txt into a temp cache dir, issues a GET, interprets the body,
 * and cleans up.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Local\HttpRequester;
use OXPulse\Imager\Infrastructure\Local\LocalRewriteProbe;
use PHPUnit\Framework\TestCase;

class LocalRewriteProbeTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/oxpulse-probe-test-' . uniqid('', true);
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        $this->assertDirectoryExists($this->cacheDir);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->cacheDir);
    }

    public function test_returns_yes_when_rewrite_fires(): void
    {
        $captured = new ProbeFileCapture($this->cacheDir);
        $requester = new FakeHttpRequester(status: 200, body: '1', onGet: $captured);

        $probe = new LocalRewriteProbe(
            $this->cacheDir,
            'https://example.test/wp-content/cache/oxpulse/.probe',
            $requester,
        );

        $this->assertSame('yes', $probe->probe());
    }

    public function test_returns_no_when_htaccess_read_but_rewrite_does_not_fire(): void
    {
        $requester = new FakeHttpRequester(status: 200, body: '0');

        $probe = new LocalRewriteProbe(
            $this->cacheDir,
            'https://example.test/wp-content/cache/oxpulse/.probe',
            $requester,
        );

        $this->assertSame('no', $probe->probe());
    }

    public function test_returns_unknown_on_transport_error(): void
    {
        $requester = new FakeHttpRequester(status: 0, body: '', error: 'curl_timeout');

        $probe = new LocalRewriteProbe(
            $this->cacheDir,
            'https://example.test/wp-content/cache/oxpulse/.probe',
            $requester,
        );

        $this->assertSame('unknown', $probe->probe());
    }

    public function test_returns_unknown_on_non_200_status(): void
    {
        $requester = new FakeHttpRequester(status: 404, body: '');

        $probe = new LocalRewriteProbe(
            $this->cacheDir,
            'https://example.test/wp-content/cache/oxpulse/.probe',
            $requester,
        );

        $this->assertSame('unknown', $probe->probe());
    }

    public function test_returns_unknown_on_unexpected_body(): void
    {
        $requester = new FakeHttpRequester(status: 200, body: 'unexpected');

        $probe = new LocalRewriteProbe(
            $this->cacheDir,
            'https://example.test/wp-content/cache/oxpulse/.probe',
            $requester,
        );

        $this->assertSame('unknown', $probe->probe());
    }

    /**
     * The probe MUST write the test .htaccess + 0.txt + 1.txt BEFORE
     * issuing the HTTP request (so the web server can serve them).
     */
    public function test_writes_probe_files_before_request(): void
    {
        $captured = new ProbeFileCapture($this->cacheDir);
        $requester = new FakeHttpRequester(status: 200, body: '1', onGet: $captured);

        $probe = new LocalRewriteProbe(
            $this->cacheDir,
            'https://example.test/wp-content/cache/oxpulse/.probe',
            $requester,
        );

        $probe->probe();

        $this->assertTrue(
            $captured->htaccessExisted,
            '.htaccess must exist in .probe/ before the HTTP request fires',
        );
        $this->assertTrue(
            $captured->zeroTxtExisted,
            '0.txt must exist in .probe/ before the HTTP request fires',
        );
        $this->assertTrue(
            $captured->oneTxtExisted,
            '1.txt must exist in .probe/ before the HTTP request fires',
        );
        $this->assertStringContainsString(
            'RewriteRule ^0\.txt$ 1.txt',
            $captured->htaccessContent,
        );
        $this->assertSame('0', $captured->zeroTxtContent);
        $this->assertSame('1', $captured->oneTxtContent);
    }

    /**
     * The probe MUST clean up the .probe/ dir after probing (best-effort).
     */
    public function test_cleans_up_probe_dir_after_probing(): void
    {
        $requester = new FakeHttpRequester(status: 200, body: '1');

        $probe = new LocalRewriteProbe(
            $this->cacheDir,
            'https://example.test/wp-content/cache/oxpulse/.probe',
            $requester,
        );

        $probe->probe();

        $this->assertDirectoryDoesNotExist($this->cacheDir . '/.probe');
    }

    public function test_requests_correct_url(): void
    {
        $requester = new FakeHttpRequester(status: 200, body: '1');

        $probe = new LocalRewriteProbe(
            $this->cacheDir,
            'https://example.test/wp-content/cache/oxpulse/.probe',
            $requester,
        );

        $probe->probe();

        $this->assertSame(
            'https://example.test/wp-content/cache/oxpulse/.probe/0.txt',
            $requester->requestedUrl,
        );
    }

    // ─── #43 Phase 1 review (MINOR 2): cleanup symlink containment ───────

    /**
     * A pre-planted `.probe` symlink pointing OUTSIDE the cache dir
     * must NOT cause cleanup to delete the target. The containment
     * guard refuses to touch a probe dir whose realpath is not under
     * the cache dir's realpath.
     */
    public function test_cleanup_refuses_symlink_pointing_outside_cache_dir(): void
    {
        // Victim dir OUTSIDE the cache dir, with a sentinel file that
        // must survive the probe's cleanup.
        $victimDir = sys_get_temp_dir() . '/oxpulse-probe-victim-' . uniqid('', true);
        @mkdir($victimDir, 0755, true);
        $sentinel = $victimDir . '/sentinel.txt';
        file_put_contents($sentinel, 'must-survive');
        $this->assertFileExists($sentinel);

        try {
            // Plant `.probe` as a symlink to the victim dir.
            $probeLink = $this->cacheDir . '/.probe';
            @symlink($victimDir, $probeLink);
            $this->assertTrue(is_link($probeLink) || is_dir($probeLink));

            $requester = new FakeHttpRequester(status: 200, body: '1');
            $probe = new LocalRewriteProbe(
                $this->cacheDir,
                'https://example.test/wp-content/cache/oxpulse/.probe',
                $requester,
            );

            $probe->probe();

            // The sentinel file inside the victim (symlink target) must
            // survive — cleanup refused because realpath(.probe) is not
            // under realpath(cacheDir).
            $this->assertFileExists(
                $sentinel,
                'cleanup must NOT delete files outside the cache dir via a .probe symlink',
            );
        } finally {
            @unlink($probeLink ?? '');
            if (is_dir($victimDir)) {
                self::removeTree($victimDir);
            }
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }
        @rmdir($path);
    }
}

/**
 * Fake HttpRequester that returns a canned response and optionally
 * captures the state of the .probe/ dir at request time.
 */
class FakeHttpRequester implements HttpRequester
{
    public string $requestedUrl = '';

    public function __construct(
        private int $status,
        private string $body,
        private ?string $error = null,
        private ?ProbeFileCapture $onGet = null,
    ) {}

    public function get(string $url): array
    {
        $this->requestedUrl = $url;
        if ($this->onGet !== null) {
            $this->onGet->capture();
        }
        return [
            'status' => $this->status,
            'body'   => $this->body,
            'error'  => $this->error,
        ];
    }
}

/**
 * Captures the filesystem state of the .probe/ dir at the moment
 * the HTTP request fires.
 */
class ProbeFileCapture
{
    public bool $htaccessExisted = false;
    public bool $zeroTxtExisted = false;
    public bool $oneTxtExisted = false;
    public string $htaccessContent = '';
    public string $zeroTxtContent = '';
    public string $oneTxtContent = '';

    public function __construct(private string $cacheDir) {}

    public function capture(): void
    {
        $probeDir = $this->cacheDir . '/.probe';
        $htaccess = $probeDir . '/.htaccess';
        $zero = $probeDir . '/0.txt';
        $one = $probeDir . '/1.txt';

        $this->htaccessExisted = is_file($htaccess);
        $this->zeroTxtExisted = is_file($zero);
        $this->oneTxtExisted = is_file($one);

        if ($this->htaccessExisted) {
            $this->htaccessContent = (string) file_get_contents($htaccess);
        }
        if ($this->zeroTxtExisted) {
            $this->zeroTxtContent = (string) file_get_contents($zero);
        }
        if ($this->oneTxtExisted) {
            $this->oneTxtContent = (string) file_get_contents($one);
        }
    }
}
