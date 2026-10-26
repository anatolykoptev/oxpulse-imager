<?php
/**
 * Runs after the other ServiceRegistrar* tests that define WP_CONTENT_DIR
 * (a define-once constant that turns on the LocalBackend artifact check
 * for every later test), hence the file name.
 *
 * Runs after the other ServiceRegistrar* tests that define WP_CONTENT_DIR
 * (a define-once constant that turns on the LocalBackend artifact check
 * for every later test), hence the file name.
 *
 * #147: signing secrets + allowed_sources are seeded for installs that
 * never run the wizard (WP-CLI / provisioning scripts), without ever
 * overwriting an existing value, and the misconfigured state is visible.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\WordPress {
    /**
     * Test seam: an unqualified random_bytes() inside this namespace
     * resolves here first, so a CSPRNG failure can be simulated for
     * OptionSettingsRepository::generateSecretHex().
     */
    function random_bytes(int $length): string
    {
        if (!empty($GLOBALS['__oxpulse_random_fail'])) {
            throw new \Exception('no entropy');
        }
        return \random_bytes($length);
    }
}

namespace OXPulse\Imager\Tests\Unit {

use OXPulse\Imager\Application\Delivery\UrlRewriterFactory;
use OXPulse\Imager\Infrastructure\Local\LocalDeliveryInstaller;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use OXPulse\Imager\Integration\WordPress\Admin\StatusRestController;
use OXPulse\Imager\Plugin;
use PHPUnit\Framework\TestCase;

class ServiceRegistrarSeedingTest extends TestCase
{
    private const UPLOADS = 'https://example.test/wp-content/uploads/';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_autoload'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_is_admin'] = false;
        $GLOBALS['__oxpulse_current_user_can'] = [];
        $GLOBALS['__oxpulse_get_option_calls'] = 0;
        unset($GLOBALS['__oxpulse_random_fail'], $GLOBALS['__oxpulse_upload_dir']);
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_autoload'],
            $GLOBALS['__oxpulse_actions'],
            $GLOBALS['__oxpulse_filters'],
            $GLOBALS['__oxpulse_is_admin'],
            $GLOBALS['__oxpulse_current_user_can'],
            $GLOBALS['__oxpulse_random_fail'],
            $GLOBALS['__oxpulse_upload_dir'],
            $GLOBALS['__oxpulse_get_option_calls']
        );
        if (defined('WP_CONTENT_DIR')) {
            @unlink(WP_CONTENT_DIR . '/' . LocalDeliveryInstaller::ENDPOINT_FILENAME);
            @unlink(WP_CONTENT_DIR . '/cache/oxpulse/.htaccess');
        }
        parent::tearDown();
    }

    private function repo(): OptionSettingsRepository
    {
        return new OptionSettingsRepository();
    }

    // ── Activation ──────────────────────────────────────────────────

    public function test_activation_on_empty_options_seeds_secrets_and_uploads_origin(): void
    {
        oxpulse_imager_activate();

        $key = (string) get_option(OptionSettingsRepository::OPTION_KEY, '');
        $salt = (string) get_option(OptionSettingsRepository::OPTION_SALT, '');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key, 'activation must seed a 32-byte hex key');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $salt, 'activation must seed a 32-byte hex salt');
        $this->assertNotSame($key, $salt, 'key and salt must be independent draws');
        $this->assertSame([self::UPLOADS], get_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES));
        $this->assertNotNull($this->repo()->loadSigningConfig(), 'seeded pair must be a valid SigningConfig');
        $this->assertFalse(get_option(OptionSettingsRepository::OPTION_ENABLED), 'delivery stays disabled');
    }

    public function test_activation_never_overwrites_existing_values(): void
    {
        update_option(OptionSettingsRepository::OPTION_KEY, str_repeat('ab', 16));
        update_option(OptionSettingsRepository::OPTION_SALT, str_repeat('cd', 16));
        update_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES, ['https://cdn.test/']);

        oxpulse_imager_activate();

        $this->assertSame(str_repeat('ab', 16), get_option(OptionSettingsRepository::OPTION_KEY));
        $this->assertSame(str_repeat('cd', 16), get_option(OptionSettingsRepository::OPTION_SALT));
        $this->assertSame(['https://cdn.test/'], get_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES));
    }

    public function test_seeded_allowed_sources_is_autoloaded(): void
    {
        oxpulse_imager_activate();

        $this->assertTrue($GLOBALS['__oxpulse_autoload'][OptionSettingsRepository::OPTION_ALLOWED_SOURCES]);
    }

    // ── Self-heal on admin_init ─────────────────────────────────────

    public function test_admin_init_self_heals_an_installed_site(): void
    {
        // Already-installed site: options exist but are empty.
        update_option(OptionSettingsRepository::OPTION_ENABLED, true);
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        update_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES, []);

        $GLOBALS['__oxpulse_current_user_can'] = ['manage_options' => true];
        ServiceRegistrar::register((new \ReflectionClass(Plugin::class))->newInstanceWithoutConstructor());
        do_action('admin_init');

        $this->assertNotNull($this->repo()->loadSigningConfig());
        $this->assertSame([self::UPLOADS], get_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES));
    }

    public function test_self_heal_is_idempotent_and_keeps_secrets_stable(): void
    {
        ServiceRegistrar::seedMissingConfig();
        $key = get_option(OptionSettingsRepository::OPTION_KEY);
        $salt = get_option(OptionSettingsRepository::OPTION_SALT);

        ServiceRegistrar::seedMissingConfig();

        $this->assertSame($key, get_option(OptionSettingsRepository::OPTION_KEY), 're-run must not rotate the key');
        $this->assertSame($salt, get_option(OptionSettingsRepository::OPTION_SALT));
    }

    // ── Fail-safe edges ─────────────────────────────────────────────

    public function test_imgproxy_site_gets_no_random_secrets(): void
    {
        // A random pair would not match the imgproxy server: signed URLs
        // would 403 instead of preserving the original URL.
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.test');

        $seeded = $this->repo()->seedMissingConfig(self::UPLOADS);

        $this->assertSame(['allowed_sources'], $seeded);
        $this->assertNull(get_option(OptionSettingsRepository::OPTION_KEY, null));
        $this->assertNull($this->repo()->loadSigningConfig());
    }

    public function test_partial_secrets_are_not_completed_and_are_reported(): void
    {
        update_option(OptionSettingsRepository::OPTION_KEY, str_repeat('ab', 16));

        $this->repo()->seedMissingConfig(self::UPLOADS);

        $this->assertSame(str_repeat('ab', 16), get_option(OptionSettingsRepository::OPTION_KEY));
        $this->assertSame('', (string) get_option(OptionSettingsRepository::OPTION_SALT, ''));
        $this->assertContains('signing_partial', $this->repo()->configProblems());
    }

    // ── Visibility ──────────────────────────────────────────────────

    public function test_config_problems_lists_what_is_missing_and_clears_once_seeded(): void
    {
        $this->assertSame(
            ['signing_not_configured', 'no_allowed_sources_configured'],
            $this->repo()->configProblems(),
        );

        $this->repo()->seedMissingConfig(self::UPLOADS);

        $this->assertSame([], $this->repo()->configProblems());
    }

    public function test_status_endpoint_reports_config_problems_without_values(): void
    {
        $repo = $this->repo();
        $controller = new StatusRestController($repo);
        $before = $controller->handleStatus()->get_data();
        $this->assertSame(['signing_not_configured', 'no_allowed_sources_configured'], $before['configProblems']);

        $repo->seedMissingConfig(self::UPLOADS);
        $after = $controller->handleStatus()->get_data();

        $this->assertSame([], $after['configProblems']);
        $this->assertStringNotContainsString(
            (string) get_option(OptionSettingsRepository::OPTION_KEY),
            json_encode($after),
            'the status payload must never carry the key',
        );
    }

    // ── MAJOR 1: auto-generated secrets never sign for imgproxy ─────

    public function test_autogenerated_secrets_do_not_sign_imgproxy_urls_until_the_operator_saves_real_ones(): void
    {
        $repo = $this->repo();
        $repo->seedMissingConfig(self::UPLOADS); // LocalBackend site: seeded + flagged.
        $this->assertTrue((bool) get_option(OptionSettingsRepository::OPTION_SIGNING_AUTOGENERATED, false));

        // Operator later points the site at imgproxy and never types a key.
        add_filter('oxpulse_is_pro', '__return_true');
        $repo->saveDeliverySettings(['enabled' => true, 'endpoint' => 'https://imgproxy.test']);

        $rewrite = function () use ($repo) {
            $r = new OptionSettingsRepository();
            return UrlRewriterFactory::fromConfig($r->loadDeliveryConfig(), $r->loadSigningConfig())
                ->rewrite(self::UPLOADS . '2026/01/a.jpg', 400, 0, 'test');
        };

        $result = $rewrite();
        $this->assertFalse($result->rewritten, 'no imgproxy URL may be emitted with auto-generated secrets');
        $this->assertSame(self::UPLOADS . '2026/01/a.jpg', $result->url, 'the original URL is preserved');
        $this->assertContains('signing_autogenerated', $repo->configProblems());
        $this->assertSame('autogenerated', $repo->secretStatus(), 'the SPA must not see "configured"');

        // The operator enters the real imgproxy pair: delivery resumes.
        $repo->saveSecrets(str_repeat('ab', 16), str_repeat('cd', 16));
        $this->assertFalse(get_option(OptionSettingsRepository::OPTION_SIGNING_AUTOGENERATED, false));
        $this->assertNotContains('signing_autogenerated', $repo->configProblems());
        $this->assertSame('configured', $repo->secretStatus());
        $after = $rewrite();
        $this->assertTrue($after->rewritten);
        $this->assertStringContainsString('imgproxy.test', $after->url);
    }

    public function test_wp_cli_flow_with_real_secrets_resumes_delivery(): void
    {
        // wp plugin activate (seeds + marks), then wp option update x4.
        oxpulse_imager_activate();
        add_filter('oxpulse_is_pro', '__return_true');
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.test');
        update_option(OptionSettingsRepository::OPTION_KEY, str_repeat('ab', 16));
        update_option(OptionSettingsRepository::OPTION_SALT, str_repeat('cd', 16));
        update_option(OptionSettingsRepository::OPTION_ENABLED, true);

        $repo = new OptionSettingsRepository();
        $result = UrlRewriterFactory::fromConfig($repo->loadDeliveryConfig(), $repo->loadSigningConfig())
            ->rewrite(self::UPLOADS . '2026/01/a.jpg', 400, 0, 'test');

        $this->assertTrue($result->rewritten, 'real secrets written directly must not stay guarded');
        $this->assertStringContainsString('imgproxy.test', $result->url);
        $this->assertSame('configured', $repo->secretStatus());
        $this->assertSame([], $repo->configProblems());
    }

    public function test_overwriting_only_one_half_deactivates_the_marker_without_new_behaviour(): void
    {
        $repo = $this->repo();
        $repo->seedMissingConfig(self::UPLOADS);
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.test');
        $this->assertSame('autogenerated', (new OptionSettingsRepository())->secretStatus());

        update_option(OptionSettingsRepository::OPTION_KEY, str_repeat('ab', 16));

        $fresh = new OptionSettingsRepository();
        $this->assertSame('configured', $fresh->secretStatus());
        $this->assertNotContains('signing_autogenerated', $fresh->configProblems());
        // Same as it would be without a marker: both halves are valid hex.
        $this->assertNotNull($fresh->loadSigningConfig());
    }

    public function test_autogenerated_secrets_still_sign_on_local_backend(): void
    {
        $repo = $this->repo();
        $repo->seedMissingConfig(self::UPLOADS);

        $this->assertNotNull($repo->loadSigningConfig(), 'LocalBackend (empty endpoint) signs with the generated pair');
        $this->assertSame('configured', $repo->secretStatus());
        $this->assertNotContains('signing_autogenerated', $repo->configProblems());
    }

    // ── MAJOR 2: delivery is installed after a seed ─────────────────

    public function test_seed_on_a_rowless_install_generates_the_endpoint_file(): void
    {
        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', '/tmp/oxpulse-ms-test/wp-content');
        }
        $endpoint = WP_CONTENT_DIR . '/' . LocalDeliveryInstaller::ENDPOINT_FILENAME;
        @unlink($endpoint);
        // No key / salt / allowed_sources rows at all: the seed creates
        // them (added_option), which the updated_option listener ignores,
        // so only the explicit install can produce the file.
        $this->assertSame([], $GLOBALS['__oxpulse_options']);

        ServiceRegistrar::seedMissingConfig();

        $this->assertFileExists($endpoint, 'seeding must install LocalBackend delivery explicitly');
    }

    // ── MAJOR 3: the misconfiguration reaches the diagnostic log ────

    public function test_enabled_but_unconfigured_delivery_logs_the_reason(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENABLED, true);
        update_option(OptionSettingsRepository::OPTION_DIAGNOSTIC_LEVEL, 'basic');
        $logger = new \ReflectionProperty(ServiceRegistrar::class, 'diagnosticLogger');
        $logger->setValue(null, null);

        ServiceRegistrar::register((new \ReflectionClass(Plugin::class))->newInstanceWithoutConstructor());
        do_action('plugins_loaded');

        $reasons = array_map(
            static fn($e) => $e->context . ':' . $e->reason,
            ServiceRegistrar::diagnosticLogger()->getEntries(),
        );
        $this->assertContains('config:signing_not_configured', $reasons);
        $this->assertContains('config:no_allowed_sources_configured', $reasons);
        $logger->setValue(null, null);
    }

    // ── MINOR 5: capability gate, atomic seeding ────────────────────

    public function test_admin_init_without_manage_options_seeds_nothing(): void
    {
        // Anonymous admin-ajax.php / admin-post.php request.
        ServiceRegistrar::register((new \ReflectionClass(Plugin::class))->newInstanceWithoutConstructor());
        do_action('admin_init');

        $this->assertNull(get_option(OptionSettingsRepository::OPTION_KEY, null));
    }

    public function test_a_concurrent_seed_holding_the_lock_blocks_a_second_pair(): void
    {
        update_option(OptionSettingsRepository::OPTION_SEED_LOCK, time());

        $seeded = $this->repo()->seedMissingConfig(self::UPLOADS);

        $this->assertSame([], $seeded);
        $this->assertNull(get_option(OptionSettingsRepository::OPTION_KEY, null));
    }

    public function test_a_stale_lock_is_stolen_and_released_after_seeding(): void
    {
        update_option(OptionSettingsRepository::OPTION_SEED_LOCK, time() - 3600);

        $seeded = $this->repo()->seedMissingConfig(self::UPLOADS);

        $this->assertContains('signing', $seeded);
        $this->assertNull(get_option(OptionSettingsRepository::OPTION_SEED_LOCK, null), 'lock released');
    }

    // ── MINOR 6: imgproxy sites stay cheap ──────────────────────────

    public function test_imgproxy_site_with_nothing_to_seed_does_no_lock_and_no_full_config_load(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.test');
        update_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES, ['https://s.test/']);
        update_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES_SEEDED, true);
        $GLOBALS['__oxpulse_get_option_calls'] = 0;

        $this->assertSame([], $this->repo()->seedMissingConfig(self::UPLOADS));

        $this->assertLessThanOrEqual(3, $GLOBALS['__oxpulse_get_option_calls'], 'must not pay loadDeliveryConfig()');
        $this->assertArrayNotHasKey(OptionSettingsRepository::OPTION_SEED_LOCK, $GLOBALS['__oxpulse_autoload'], 'no lock taken');
    }

    // ── MINOR 7: allowed_sources is seeded once ─────────────────────

    public function test_a_deliberately_cleared_allowlist_is_not_reseeded(): void
    {
        $repo = $this->repo();
        $repo->seedMissingConfig(self::UPLOADS);
        $repo->saveDeliverySettings(['allowed_sources' => []]); // operator clears it.

        $this->repo()->seedMissingConfig(self::UPLOADS);

        $this->assertSame([], get_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES));
    }

    public function test_clearing_via_a_raw_option_write_is_not_reseeded_either(): void
    {
        $this->repo()->seedMissingConfig(self::UPLOADS);
        update_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES, []);

        $this->repo()->seedMissingConfig(self::UPLOADS);

        $this->assertSame([], get_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES));
    }

    // ── MINOR 8: seeded origin follows the pages' scheme ────────────

    public function test_http_uploads_baseurl_behind_https_pages_is_seeded_as_https(): void
    {
        $GLOBALS['__oxpulse_upload_dir'] = [
            'baseurl' => 'http://example.test/wp-content/uploads',
            'basedir' => '/tmp/wp-content/uploads',
            'error' => false,
        ];
        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', '/tmp/oxpulse-ms-test/wp-content');
        }

        ServiceRegistrar::seedMissingConfig(); // home_url() stub is https.

        $this->assertSame([self::UPLOADS], get_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES));
    }

    // ── NIT: no entropy never fatals ────────────────────────────────

    public function test_csprng_failure_skips_secret_seeding_without_fatal(): void
    {
        $GLOBALS['__oxpulse_random_fail'] = true;

        $seeded = $this->repo()->seedMissingConfig(self::UPLOADS);

        $this->assertNotContains('signing', $seeded);
        $this->assertNull(get_option(OptionSettingsRepository::OPTION_KEY, null));
        $this->assertNull(get_option(OptionSettingsRepository::OPTION_SIGNING_AUTOGENERATED, null), 'no marker without secrets');
    }
}
}
