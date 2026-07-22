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
