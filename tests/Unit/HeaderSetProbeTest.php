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
        unset($GLOBALS['__oxpulse_options'], $_SERVER['SERVER_SOFTWARE']);
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
        $this->assertMatchesRegularExpression('#/\.probe-headers-[0-9a-f]+/probe\.txt\?oxp=\d+$#', $req->requestedUrl, 'unique dir + cache-buster');
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

    public function test_soft_200_page_without_the_header_is_unknown_not_no(): void
    {
        $html = '<html><body>Please log in</body></html>';
        $req = new ApacheSimRequester($this->cacheDir, false, body: $html);
        $this->assertSame('unknown', $this->probe($req)->probe());
        $tester = $this->tester($this->probe($req), true);
        $tester->recheckHeaders();
        $this->assertNull((new HeadersCapabilityCache())->read(), 'a soft-200 must not persist a verdict');
    }

    public function test_probe_files_are_purged_when_the_request_throws(): void
    {
        $req = new class implements HttpRequester {
            public function get(string $url): array { throw new \RuntimeException('boom'); }
            public function head(string $url): array { return []; }
            public function getImage(string $url): array { return []; }
        };
        try {
            $this->probe($req)->probe();
            $this->fail('exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame([], $this->leftovers());
        }
    }

    public function test_purge_refuses_to_delete_the_cache_dir_itself(): void
    {
        file_put_contents($this->cacheDir . '/keep.webp', 'x');
        \OXPulse\Imager\Infrastructure\Local\LocalRewriteProbe::purgeProbeDir($this->cacheDir, $this->cacheDir);
        $this->assertFileExists($this->cacheDir . '/keep.webp');
    }

    public function test_concurrent_probes_use_distinct_dirs(): void
    {
        $a = new ApacheSimRequester($this->cacheDir, true);
        $b = new ApacheSimRequester($this->cacheDir, true);
        $this->probe($a)->probe();
        $this->probe($b)->probe();
        $this->assertNotSame(parse_url($a->requestedUrl, PHP_URL_PATH), parse_url($b->requestedUrl, PHP_URL_PATH));
    }

    /** @return list<string> */
    private function leftovers(): array
    {
        return array_values(array_diff(scandir($this->cacheDir), ['.', '..']));
    }

    public function test_probe_files_are_removed_after_every_outcome(): void
    {
        foreach ([true, false] as $mod) {
            $this->probe(new ApacheSimRequester($this->cacheDir, $mod))->probe();
            $this->assertSame([], $this->leftovers());
        }
        $this->probe(new ApacheSimRequester($this->cacheDir, true, error: 'x'))->probe();
        $this->assertSame([], $this->leftovers());
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
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.27';
        $req = new ApacheSimRequester($this->cacheDir, false);
        (new HeadersCapabilityCache())->write('no'); // stale verdict from a previous host

        $this->assertSame('skipped', $this->tester($this->probe($req), false)->recheckHeaders());

        $this->assertSame('', $req->requestedUrl, 'no HTTP request on nginx');
        $this->assertSame([], $this->leftovers());
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

    public function test_cli_without_server_software_keeps_the_cached_verdict(): void
    {
        unset($_SERVER['SERVER_SOFTWARE']);
        (new HeadersCapabilityCache())->write('no');
        $req = new ApacheSimRequester($this->cacheDir, true);

        $this->assertSame('skipped', $this->tester($this->probe($req), false)->recheckHeaders());

        $this->assertSame('no', (new HeadersCapabilityCache())->read(), 'WP-CLI must not wipe a verdict');
        $this->assertSame('', $req->requestedUrl);
    }

    public function test_settings_page_render_prints_flap_line_and_headers_warning(): void
    {
        $GLOBALS['__oxpulse_current_user_can'] = ['manage_options' => true, OXPULSE_IMAGER_CAPABILITY => true];
        add_filter('oxpulse_is_pro', '__return_true');
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        (new HeadersCapabilityCache())->write('no');

        ob_start();
        (new SettingsPage())->render();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('oxpulse-headers-warning', $html);
        $this->assertStringContainsString('mod_headers', $html);
        $this->assertStringNotContainsString('oxpulse-flaps', $html);

        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        (new \OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyFlapLog())->record('down');
        ob_start();
        (new SettingsPage())->render();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('class="oxpulse-flaps"', $html);
        $this->assertStringContainsString('1 in total', $html);
        unset($GLOBALS['__oxpulse_current_user_can'], $GLOBALS['__oxpulse_filters']);
    }

    public function test_requester_returns_lowercased_header_map(): void
    {
        $url = 'https://example.test/probe.txt?oxp=1';
        $GLOBALS['__oxpulse_http_responses'][$url] = [
            'response' => ['code' => 200],
            'headers'  => ['X-Oxpulse-Probe' => '1', 'Vary' => ['Accept', 'Cookie']],
            'body'     => 'ok',
        ];
        $resp = (new \OXPulse\Imager\Infrastructure\Local\WpRemoteHttpRequester())->get($url);
        unset($GLOBALS['__oxpulse_http_responses']);

        $this->assertSame('1', $resp['headers']['x-oxpulse-probe']);
        $this->assertSame('Accept, Cookie', $resp['headers']['vary']);
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
        private ?string $body = null,
    ) {}

    public function get(string $url): array
    {
        $this->requestedUrl = $url;
        $dir = $this->cacheDir . '/' . basename(dirname((string) parse_url($url, PHP_URL_PATH)));
        $headers = [];
        $htaccess = @file_get_contents($dir . '/.htaccess');
        if (
            $this->modHeaders && is_string($htaccess)
            && preg_match('#<IfModule mod_headers\.c>\s*Header set (\S+) "([^"]*)"\s*</IfModule>#', $htaccess, $m)
            && is_file($dir . '/probe.txt')
        ) {
            $headers[strtolower($m[1])] = $m[2];
        }
        $resp = ['status' => $this->status, 'body' => $this->body ?? 'ok', 'error' => $this->error];
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
