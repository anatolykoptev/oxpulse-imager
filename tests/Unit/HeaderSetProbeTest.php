<?php
/**
 * #51: live `mod_headers` capability probe, its cache, and the settings
 * screen warning. The warning tests run probe -> cache -> screen end to end
 * against a simulated Apache, so forcing the probe to 'yes' turns them RED.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Local\CapabilityTester;
use OXPulse\Imager\Infrastructure\Local\HeaderSetProbe;
use OXPulse\Imager\Infrastructure\Local\HeadersCapabilityCache;
use OXPulse\Imager\Infrastructure\Local\HttpRequester;
use OXPulse\Imager\Infrastructure\Local\LocalRewriteProbe;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Integration\WordPress\Admin\SettingsPage;
use PHPUnit\Framework\TestCase;

class HeaderSetProbeTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $this->cacheDir = sys_get_temp_dir() . '/oxpulse-headers-probe-' . uniqid('', true);
        mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
        $this->rmTree($this->cacheDir);
    }

    private function probe(HttpRequester $requester): HeaderSetProbe
    {
        return new HeaderSetProbe($this->cacheDir, 'https://example.test/wp-content/cache/oxpulse', $requester);
    }

    private function tester(HeaderSetProbe $probe, bool $apache): CapabilityTester
    {
        return new class($probe, $apache) extends CapabilityTester {
            public function __construct(HeaderSetProbe $p, private bool $apache)
            {
                parent::__construct(null, null, $p);
            }
            protected function isApache(): bool
            {
                return $this->apache;
            }
        };
    }

    public function test_yes_when_the_header_set_in_htaccess_is_served(): void
    {
        $req = new ApacheSimRequester($this->cacheDir, true);
        $this->assertSame('yes', $this->probe($req)->probe());
        $this->assertStringContainsString('/.probe-headers/probe.txt', $req->requestedUrl);
    }

    public function test_no_when_the_htaccess_is_ignored(): void
    {
        $this->assertSame('no', $this->probe(new ApacheSimRequester($this->cacheDir, false))->probe());
    }

    public function test_unknown_on_transport_error_non_200_or_missing_headers(): void
    {
        $this->assertSame('unknown', $this->probe(new ApacheSimRequester($this->cacheDir, true, error: 'timeout'))->probe());
        $this->assertSame('unknown', $this->probe(new ApacheSimRequester($this->cacheDir, true, status: 403))->probe());
        $this->assertSame('unknown', $this->probe(new ApacheSimRequester($this->cacheDir, true, omitHeaders: true))->probe());
    }

    public function test_probe_files_are_removed_after_every_outcome(): void
    {
        foreach ([true, false] as $mod) {
            $this->probe(new ApacheSimRequester($this->cacheDir, $mod))->probe();
            $this->assertDirectoryDoesNotExist($this->cacheDir . '/.probe-headers');
        }
        $this->probe(new ApacheSimRequester($this->cacheDir, true, error: 'x'))->probe();
        $this->assertDirectoryDoesNotExist($this->cacheDir . '/.probe-headers');
        $this->assertSame([], array_values(array_diff(scandir($this->cacheDir), ['.', '..'])));
    }

    public function test_probe_leaves_the_rewrite_probe_dir_alone(): void
    {
        mkdir($this->cacheDir . '/.probe');
        file_put_contents($this->cacheDir . '/.probe/keep', 'x');
        $this->probe(new ApacheSimRequester($this->cacheDir, true))->probe();
        $this->assertFileExists($this->cacheDir . '/.probe/keep');
    }

    public function test_recheck_headers_persists_only_definitive_results(): void
    {
        $cache = new HeadersCapabilityCache();
        $this->assertSame('no', $this->tester($this->probe(new ApacheSimRequester($this->cacheDir, false)), true)->recheckHeaders());
        $this->assertSame('no', $cache->read());

        $this->assertSame('unknown', $this->tester($this->probe(new ApacheSimRequester($this->cacheDir, true, error: 'x')), true)->recheckHeaders());
        $this->assertSame('no', $cache->read(), 'unknown must not overwrite a definitive verdict');

        $this->assertSame('yes', $this->tester($this->probe(new ApacheSimRequester($this->cacheDir, true)), true)->recheckHeaders());
        $this->assertSame('yes', $cache->read());
    }

    public function test_recheck_headers_is_a_noop_on_nginx(): void
    {
        $req = new ApacheSimRequester($this->cacheDir, false);
        (new HeadersCapabilityCache())->write('no'); // stale verdict from a previous host

        $this->assertSame('skipped', $this->tester($this->probe($req), false)->recheckHeaders());

        $this->assertSame('', $req->requestedUrl, 'no HTTP request on nginx');
        $this->assertDirectoryDoesNotExist($this->cacheDir . '/.probe-headers');
        $this->assertNull((new HeadersCapabilityCache())->read(), 'stale verdict cleared');
        $this->assertSame('', (new SettingsPage())->buildHeadersWarning());
    }

    public function test_rewrite_capability_trigger_also_runs_the_headers_probe(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        $headersProbe = $this->probe(new ApacheSimRequester($this->cacheDir, false));
        $tester = new class($headersProbe) extends CapabilityTester {
            public function __construct(HeaderSetProbe $p)
            {
                parent::__construct(new FixedRewriteProbe('', '', new ApacheSimRequester('', false)), null, $p);
            }
            protected function isApache(): bool
            {
                return true;
            }
        };

        ServiceRegistrar::recheckRewriteCapability($tester);

        $this->assertSame('no', (new HeadersCapabilityCache())->read());
    }

    public function test_status_screen_warns_when_mod_headers_is_missing(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        $this->tester($this->probe(new ApacheSimRequester($this->cacheDir, false)), true)->recheckHeaders();

        $warning = (new SettingsPage())->buildHeadersWarning();

        $this->assertStringContainsString('mod_headers', $warning);
        $this->assertStringContainsString('Vary: Accept', $warning);
    }

    public function test_status_screen_is_silent_when_mod_headers_works_or_is_unknown(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        $this->assertSame('', (new SettingsPage())->buildHeadersWarning(), 'never probed');

        $this->tester($this->probe(new ApacheSimRequester($this->cacheDir, true)), true)->recheckHeaders();
        $this->assertSame('', (new SettingsPage())->buildHeadersWarning());
    }

    public function test_status_screen_is_silent_on_imgproxy_backend(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        (new HeadersCapabilityCache())->write('no');
        $this->assertSame('', (new SettingsPage())->buildHeadersWarning());
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $e) {
            is_dir("$dir/$e") ? $this->rmTree("$dir/$e") : unlink("$dir/$e");
        }
        rmdir($dir);
    }
}

/**
 * Simulated Apache: serves <probe dir>/probe.txt and applies the
 * .htaccess `Header set` only when the module is "loaded" and the rule sits
 * inside <IfModule mod_headers.c>, like the real thing.
 */
class ApacheSimRequester implements HttpRequester
{
    public string $requestedUrl = '';

    public function __construct(
        private string $cacheDir,
        private bool $modHeaders,
        private ?string $error = null,
        private int $status = 200,
        private bool $omitHeaders = false,
    ) {}

    public function get(string $url): array
    {
        $this->requestedUrl = $url;
        $dir = $this->cacheDir . '/.probe-headers';
        $headers = [];
        $htaccess = @file_get_contents($dir . '/.htaccess');
        if (
            $this->modHeaders && is_string($htaccess)
            && preg_match('#<IfModule mod_headers\.c>\s*Header set (\S+) "([^"]*)"\s*</IfModule>#', $htaccess, $m)
            && is_file($dir . '/probe.txt')
        ) {
            $headers[strtolower($m[1])] = $m[2];
        }
        $resp = ['status' => $this->status, 'body' => 'ok', 'error' => $this->error];
        if (!$this->omitHeaders) {
            $resp['headers'] = $headers;
        }
        return $resp;
    }

    public function head(string $url): array
    {
        return ['status' => 200, 'body' => '', 'error' => null];
    }

    public function getImage(string $url): array
    {
        return ['status' => 200, 'content_type' => '', 'error' => null];
    }
}

class FixedRewriteProbe extends LocalRewriteProbe
{
    public function probe(): string
    {
        return 'yes';
    }
}
