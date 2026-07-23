<?php
/**
 * Uninstall cleanup tests (#88, #88-followup: toggle-gated).
 *
 * Verifies the ephemeral/persistent split in Uninstaller::run():
 *  - ALWAYS: cron events, transients (static + dynamic), on-disk cache
 *    dir (with path-safety guard), generated endpoint artifacts.
 *  - GATED by oxpulse_imager_remove_on_uninstall (default false):
 *    persistent user CONFIG options (prefix family + standalone,
 *    including key/salt/settings).
 *
 * Covers both toggle states (true → options deleted; false → options
 * PRESERVED but ephemeral still cleaned), the toggle-read-before-
 * deletion ordering, multisite per-site split, path-safety, and the
 * uninstall.php WP_UNINSTALL_PLUGIN guard + wiring.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\WordPress\Uninstaller;
use PHPUnit\Framework\TestCase;

class UninstallTest extends TestCase
{
    private string $wpContentDir;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_transients'] = [];
        $GLOBALS['__oxpulse_scheduled_events'] = [];
        $GLOBALS['__oxpulse_is_multisite'] = false;
        $GLOBALS['__oxpulse_sites'] = [];
        $GLOBALS['__oxpulse_blog_options'] = [];
        $GLOBALS['__oxpulse_blog_stack'] = [];
        $GLOBALS['__oxpulse_current_blog'] = 1;
        $GLOBALS['__oxpulse_network_options'] = [];
        $this->wpContentDir = sys_get_temp_dir() . '/oxpulse-uninstall-' . uniqid();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rmrf($this->wpContentDir);
    }

    private function rmrf(string $dir): void
    {
        if (is_link($dir)) {
            unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if (is_link($file->getPathname())) {
                unlink($file->getPathname());
            } else {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }

    /**
     * Populate every enumerated option so we can assert they are all
     * removed. Returns the full list of keys that SHOULD be gone after
     * uninstall.
     *
     * @return array<int,string>
     */
    private function allExpectedOptionKeys(): array
    {
        return array_merge(
            Uninstaller::PREFIX_OPTIONS,
            Uninstaller::STANDALONE_OPTIONS,
        );
    }

    /**
     * Opt into persistent-data removal by setting the toggle to true.
     * Call before Uninstaller::run() in tests that assert options ARE
     * deleted (the toggle-gated path).
     */
    private function enableRemoveOnUninstall(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_remove_on_uninstall'] = true;
    }

    // ------------------------------------------------------------------
    // Toggle = true → persistent options deleted (existing assertions).
    // ------------------------------------------------------------------

    public function test_run_removes_all_enumerated_prefix_options(): void
    {
        $this->enableRemoveOnUninstall();
        foreach (Uninstaller::PREFIX_OPTIONS as $key) {
            $GLOBALS['__oxpulse_options'][$key] = 'value';
        }

        Uninstaller::run();

        foreach (Uninstaller::PREFIX_OPTIONS as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $GLOBALS['__oxpulse_options'],
                "Option {$key} was not removed on uninstall."
            );
        }
    }

    public function test_run_removes_standalone_options(): void
    {
        $this->enableRemoveOnUninstall();
        foreach (Uninstaller::STANDALONE_OPTIONS as $key) {
            $GLOBALS['__oxpulse_options'][$key] = 'value';
        }

        Uninstaller::run();

        foreach (Uninstaller::STANDALONE_OPTIONS as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $GLOBALS['__oxpulse_options'],
                "Standalone option {$key} was not removed on uninstall."
            );
        }
    }

    // ------------------------------------------------------------------
    // Toggle = false (default) → persistent options PRESERVED, but
    // ephemeral (cron, transients, cache, endpoint) still cleaned.
    // ------------------------------------------------------------------

    public function test_run_preserves_all_options_when_toggle_false(): void
    {
        // Populate every enumerated config option, then explicitly set
        // the toggle to false (the bulk populate below would otherwise
        // set it to a truthy string, flipping the gate to true).
        foreach (Uninstaller::PREFIX_OPTIONS as $key) {
            $GLOBALS['__oxpulse_options'][$key] = 'value-' . $key;
        }
        foreach (Uninstaller::STANDALONE_OPTIONS as $key) {
            $GLOBALS['__oxpulse_options'][$key] = 'value-' . $key;
        }
        $GLOBALS['__oxpulse_options']['oxpulse_imager_remove_on_uninstall'] = false;

        Uninstaller::run();

        // Every config option must survive — a reinstall restores setup.
        foreach (Uninstaller::PREFIX_OPTIONS as $key) {
            $this->assertArrayHasKey(
                $key,
                $GLOBALS['__oxpulse_options'],
                "Option {$key} was deleted despite remove_on_uninstall=false."
            );
        }
        foreach (Uninstaller::STANDALONE_OPTIONS as $key) {
            $this->assertArrayHasKey(
                $key,
                $GLOBALS['__oxpulse_options'],
                "Standalone option {$key} was deleted despite remove_on_uninstall=false."
            );
        }
    }

    public function test_run_preserves_key_and_salt_when_toggle_false(): void
    {
        // The critical paid-plugin invariant: signing key + salt survive
        // an uninstall without the opt-in, so a reinstall picks up the
        // same signing identity (no broken image URLs mid-reinstall).
        $GLOBALS['__oxpulse_options']['oxpulse_imager_key'] = 'secret-key';
        $GLOBALS['__oxpulse_options']['oxpulse_imager_salt'] = 'secret-salt';

        Uninstaller::run();

        $this->assertSame('secret-key', $GLOBALS['__oxpulse_options']['oxpulse_imager_key'] ?? null);
        $this->assertSame('secret-salt', $GLOBALS['__oxpulse_options']['oxpulse_imager_salt'] ?? null);
    }

    public function test_run_still_clears_cron_when_toggle_false(): void
    {
        // Toggle false, but cron is ephemeral → always cleared.
        foreach (Uninstaller::CRON_HOOKS as $hook) {
            wp_schedule_event(time() + 3600, 'hourly', $hook);
        }

        Uninstaller::run();

        foreach (Uninstaller::CRON_HOOKS as $hook) {
            $this->assertFalse(
                wp_next_scheduled($hook),
                "Cron hook {$hook} was not cleared despite toggle=false (cron is ephemeral)."
            );
        }
    }

    public function test_run_still_removes_transients_when_toggle_false(): void
    {
        // Toggle false, but transients are ephemeral → always removed.
        foreach (Uninstaller::TRANSIENTS as $transient) {
            $GLOBALS['__oxpulse_transients'][$transient] = 'data';
        }
        $GLOBALS['__oxpulse_options']['_transient_oxpulse_prewarm_job_abc'] = ['status' => 'pending'];
        $GLOBALS['__oxpulse_options']['_transient_timeout_oxpulse_prewarm_job_abc'] = time() + 3600;

        Uninstaller::run();

        foreach (Uninstaller::TRANSIENTS as $transient) {
            $this->assertArrayNotHasKey(
                $transient,
                $GLOBALS['__oxpulse_transients'],
                "Transient {$transient} was not removed despite toggle=false (transients are ephemeral)."
            );
        }
        $this->assertArrayNotHasKey('_transient_oxpulse_prewarm_job_abc', $GLOBALS['__oxpulse_options']);
        $this->assertArrayNotHasKey('_transient_timeout_oxpulse_prewarm_job_abc', $GLOBALS['__oxpulse_options']);
    }

    public function test_toggle_read_before_deletion_false_path_preserves_toggle_option(): void
    {
        // The toggle option itself is a persistent config option. When
        // it is false, run() must read it BEFORE any deletion and then
        // PRESERVE it (along with all other options). If the toggle
        // were deleted before being read, the false-path could not be
        // distinguished from the absent-path — this test guards the
        // read-before-deletion ordering.
        $GLOBALS['__oxpulse_options']['oxpulse_imager_remove_on_uninstall'] = false;
        $GLOBALS['__oxpulse_options']['oxpulse_imager_key'] = 'secret';

        Uninstaller::run();

        // Toggle option survives (it was read, then preserved).
        $this->assertArrayHasKey(
            'oxpulse_imager_remove_on_uninstall',
            $GLOBALS['__oxpulse_options'],
            'Toggle option was deleted on the false-path — read-before-deletion ordering is broken.'
        );
        $this->assertFalse($GLOBALS['__oxpulse_options']['oxpulse_imager_remove_on_uninstall']);
        // And so does the rest of the config.
        $this->assertArrayHasKey('oxpulse_imager_key', $GLOBALS['__oxpulse_options']);
    }

    public function test_multisite_preserves_options_when_toggle_false(): void
    {
        $GLOBALS['__oxpulse_is_multisite'] = true;
        $GLOBALS['__oxpulse_sites'] = [
            (object) ['blog_id' => 1, 'domain' => 'site1.test'],
            (object) ['blog_id' => 2, 'domain' => 'site2.test'],
        ];

        // Toggle false on the current blog (blog 1) → read once → preserve.
        $GLOBALS['__oxpulse_blog_options'] = [
            1 => [
                'oxpulse_imager_enabled' => true,
                'oxpulse_imager_key' => 'abc',
                'oxpulse_imager_remove_on_uninstall' => false,
            ],
            2 => [
                'oxpulse_imager_enabled' => true,
                'oxpulse_imager_salt' => 'def',
            ],
        ];
        $GLOBALS['__oxpulse_options'] = $GLOBALS['__oxpulse_blog_options'][1];
        $GLOBALS['__oxpulse_current_blog'] = 1;

        Uninstaller::run();

        // Both blogs' config options must survive.
        $this->assertArrayHasKey('oxpulse_imager_enabled', $GLOBALS['__oxpulse_blog_options'][1]);
        $this->assertArrayHasKey('oxpulse_imager_key', $GLOBALS['__oxpulse_blog_options'][1]);
        $this->assertArrayHasKey('oxpulse_imager_enabled', $GLOBALS['__oxpulse_blog_options'][2]);
        $this->assertArrayHasKey('oxpulse_imager_salt', $GLOBALS['__oxpulse_blog_options'][2]);
    }

    public function test_multisite_still_clears_cron_when_toggle_false(): void
    {
        $GLOBALS['__oxpulse_is_multisite'] = true;
        $GLOBALS['__oxpulse_sites'] = [
            (object) ['blog_id' => 1],
            (object) ['blog_id' => 2],
        ];
        $GLOBALS['__oxpulse_blog_options'] = [
            1 => ['oxpulse_imager_remove_on_uninstall' => false],
            2 => [],
        ];
        $GLOBALS['__oxpulse_options'] = $GLOBALS['__oxpulse_blog_options'][1];
        $GLOBALS['__oxpulse_current_blog'] = 1;

        wp_schedule_event(time() + 3600, 'hourly', 'oxpulse_imgproxy_health_recheck');

        Uninstaller::run();

        $this->assertFalse(wp_next_scheduled('oxpulse_imgproxy_health_recheck'));
    }

    // ------------------------------------------------------------------
    // Transients — static + dynamic UUID-suffixed.
    // ------------------------------------------------------------------

    public function test_run_removes_known_static_transients(): void
    {
        foreach (Uninstaller::TRANSIENTS as $transient) {
            $GLOBALS['__oxpulse_transients'][$transient] = 'data';
        }

        Uninstaller::run();

        foreach (Uninstaller::TRANSIENTS as $transient) {
            $this->assertArrayNotHasKey(
                $transient,
                $GLOBALS['__oxpulse_transients'],
                "Transient {$transient} was not removed on uninstall."
            );
        }
    }

    public function test_run_removes_dynamic_prewarm_job_transients(): void
    {
        // Simulate two prewarm job transients stored as options
        // (the $wpdb stub mirrors options for DELETE LIKE).
        $GLOBALS['__oxpulse_options']['_transient_oxpulse_prewarm_job_abc123'] = ['status' => 'pending'];
        $GLOBALS['__oxpulse_options']['_transient_oxpulse_prewarm_job_def456'] = ['status' => 'running'];
        $GLOBALS['__oxpulse_options']['_transient_timeout_oxpulse_prewarm_job_abc123'] = time() + 3600;

        Uninstaller::run();

        $this->assertArrayNotHasKey('_transient_oxpulse_prewarm_job_abc123', $GLOBALS['__oxpulse_options']);
        $this->assertArrayNotHasKey('_transient_oxpulse_prewarm_job_def456', $GLOBALS['__oxpulse_options']);
        $this->assertArrayNotHasKey('_transient_timeout_oxpulse_prewarm_job_abc123', $GLOBALS['__oxpulse_options']);
    }

    // ------------------------------------------------------------------
    // Prefix-scan safety net — catches future keys not in the enum.
    // ------------------------------------------------------------------

    public function test_prefix_scan_catches_unknown_prefixed_option(): void
    {
        $this->enableRemoveOnUninstall();
        // A key that is NOT in the enumerated list but matches the
        // oxpulse_imager_ prefix — the $wpdb prefix-scan must catch it.
        $GLOBALS['__oxpulse_options']['oxpulse_imager_future_key_v2'] = 'value';

        Uninstaller::run();

        $this->assertArrayNotHasKey(
            'oxpulse_imager_future_key_v2',
            $GLOBALS['__oxpulse_options'],
            'Prefix-scan failed to catch an unknown oxpulse_imager_ option.'
        );
    }

    public function test_prefix_scan_does_not_delete_unrelated_options(): void
    {
        $this->enableRemoveOnUninstall();
        // An unrelated option that does NOT match the prefix must survive.
        $GLOBALS['__oxpulse_options']['unrelated_plugin_option'] = 'keep-me';
        $GLOBALS['__oxpulse_options']['oxpulse_imager_enabled'] = true;

        Uninstaller::run();

        $this->assertArrayHasKey('unrelated_plugin_option', $GLOBALS['__oxpulse_options']);
        $this->assertArrayNotHasKey('oxpulse_imager_enabled', $GLOBALS['__oxpulse_options']);
    }

    // ------------------------------------------------------------------
    // Cron events — every enumerated hook is cleared.
    // ------------------------------------------------------------------

    public function test_run_clears_all_cron_hooks(): void
    {
        foreach (Uninstaller::CRON_HOOKS as $hook) {
            wp_schedule_event(time() + 3600, 'hourly', $hook);
        }
        // Also schedule a single-event cron for the prewarm hook.
        wp_schedule_single_event(time() + 60, 'oxpulse_prewarm_process_batch', ['job1']);

        Uninstaller::run();

        foreach (Uninstaller::CRON_HOOKS as $hook) {
            $this->assertFalse(
                wp_next_scheduled($hook),
                "Cron hook {$hook} was not cleared on uninstall."
            );
        }
    }

    // ------------------------------------------------------------------
    // Cache dir — recursive delete + path-safety guard.
    // ------------------------------------------------------------------

    public function test_remove_directory_deletes_cache_dir_recursively(): void
    {
        $root = $this->wpContentDir;
        $cacheDir = $root . '/cache/oxpulse';
        mkdir($cacheDir . '/sub/deep', 0755, true);
        file_put_contents($cacheDir . '/img1.avif', 'data');
        file_put_contents($cacheDir . '/sub/img2.webp', 'data');
        file_put_contents($cacheDir . '/sub/deep/img3.jpg', 'data');

        Uninstaller::removeDirectory($cacheDir, $root);

        $this->assertDirectoryDoesNotExist($cacheDir);
    }

    public function test_remove_directory_no_op_when_target_missing(): void
    {
        $root = $this->wpContentDir;
        mkdir($root, 0755, true);

        Uninstaller::removeDirectory($root . '/cache/oxpulse', $root);

        $this->expectNotToPerformAssertions();
    }

    public function test_remove_directory_refuses_path_outside_root(): void
    {
        // A symlink inside the root that points OUTSIDE the root —
        // realpath resolves the symlink, the startsWith guard must
        // refuse deletion.
        $root = $this->wpContentDir;
        mkdir($root . '/cache', 0755, true);

        $outside = sys_get_temp_dir() . '/oxpulse-evil-' . uniqid();
        mkdir($outside, 0755, true);
        file_put_contents($outside . '/innocent.txt', 'do-not-delete');

        // Symlink: root/cache/oxpulse → outside
        symlink($outside, $root . '/cache/oxpulse');

        Uninstaller::removeDirectory($root . '/cache/oxpulse', $root);

        // The outside target must survive.
        $this->assertFileExists($outside . '/innocent.txt');
        $this->rmrf($outside);
    }

    public function test_remove_directory_refuses_traversal_path(): void
    {
        // A path with .. that resolves outside the root.
        $root = $this->wpContentDir;
        $sibling = sys_get_temp_dir() . '/oxpulse-sibling-' . uniqid();
        mkdir($sibling, 0755, true);
        file_put_contents($sibling . '/keep.txt', 'data');
        mkdir($root . '/cache/oxpulse', 0755, true);

        // Path: root/cache/../sibling — realpath resolves to the sibling
        // which is NOT under root.
        $traversal = $root . '/cache/../' . basename($sibling);

        Uninstaller::removeDirectory($traversal, $root);

        $this->assertFileExists($sibling . '/keep.txt');
        $this->rmrf($sibling);
    }

    // ------------------------------------------------------------------
    // Multisite — per-site options + cron cleaned for every blog.
    // ------------------------------------------------------------------

    public function test_multisite_cleans_all_sites_options(): void
    {
        $GLOBALS['__oxpulse_is_multisite'] = true;
        $GLOBALS['__oxpulse_sites'] = [
            (object) ['blog_id' => 1, 'domain' => 'site1.test'],
            (object) ['blog_id' => 2, 'domain' => 'site2.test'],
        ];

        // Opt into persistent-data removal on both blogs.
        $GLOBALS['__oxpulse_blog_options'] = [
            1 => [
                'oxpulse_imager_enabled' => true,
                'oxpulse_imager_key' => 'abc',
                'oxpulse_imager_remove_on_uninstall' => true,
            ],
            2 => [
                'oxpulse_imager_enabled' => true,
                'oxpulse_imager_salt' => 'def',
                'oxpulse_imager_remove_on_uninstall' => true,
            ],
        ];
        $GLOBALS['__oxpulse_options'] = $GLOBALS['__oxpulse_blog_options'][1];
        $GLOBALS['__oxpulse_current_blog'] = 1;

        Uninstaller::run();

        // Both blogs' options must be cleaned.
        $this->assertArrayNotHasKey('oxpulse_imager_enabled', $GLOBALS['__oxpulse_blog_options'][1]);
        $this->assertArrayNotHasKey('oxpulse_imager_key', $GLOBALS['__oxpulse_blog_options'][1]);
        $this->assertArrayNotHasKey('oxpulse_imager_enabled', $GLOBALS['__oxpulse_blog_options'][2]);
        $this->assertArrayNotHasKey('oxpulse_imager_salt', $GLOBALS['__oxpulse_blog_options'][2]);
    }

    public function test_multisite_cleans_all_sites_cron(): void
    {
        $GLOBALS['__oxpulse_is_multisite'] = true;
        $GLOBALS['__oxpulse_sites'] = [
            (object) ['blog_id' => 1],
            (object) ['blog_id' => 2],
        ];
        $GLOBALS['__oxpulse_blog_options'] = [1 => [], 2 => []];
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_current_blog'] = 1;

        // Schedule cron on blog 1 (current).
        wp_schedule_event(time() + 3600, 'hourly', 'oxpulse_imgproxy_health_recheck');

        Uninstaller::run();

        $this->assertFalse(wp_next_scheduled('oxpulse_imgproxy_health_recheck'));
    }

    // ------------------------------------------------------------------
    // uninstall.php guard + wiring.
    // ------------------------------------------------------------------

    public function test_uninstall_file_has_wp_uninstall_plugin_guard(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');
        $this->assertStringContainsString("WP_UNINSTALL_PLUGIN", $source);
        $this->assertStringContainsString('exit;', $source);
    }

    public function test_uninstall_file_calls_uninstaller_run(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');
        $this->assertStringContainsString('Uninstaller::run()', $source);
    }

    /**
     * End-to-end: including uninstall.php with WP_UNINSTALL_PLUGIN
     * defined AND the remove_on_uninstall toggle set runs the full
     * cleanup path (options + ephemeral).
     */
    public function test_uninstall_file_removes_options_when_constant_defined(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }

        // Opt into persistent-data removal.
        $GLOBALS['__oxpulse_options']['oxpulse_imager_remove_on_uninstall'] = true;
        $GLOBALS['__oxpulse_options']['oxpulse_imager_enabled'] = true;
        $GLOBALS['__oxpulse_options']['oxpulse_imager_key'] = 'secret';
        $GLOBALS['__oxpulse_options']['oxpulse_imgproxy_health'] = 'down';
        $GLOBALS['__oxpulse_options']['unrelated_option'] = 'keep';

        include dirname(__DIR__, 2) . '/uninstall.php';

        $this->assertArrayNotHasKey('oxpulse_imager_enabled', $GLOBALS['__oxpulse_options']);
        $this->assertArrayNotHasKey('oxpulse_imager_key', $GLOBALS['__oxpulse_options']);
        $this->assertArrayNotHasKey('oxpulse_imgproxy_health', $GLOBALS['__oxpulse_options']);
        $this->assertArrayHasKey('unrelated_option', $GLOBALS['__oxpulse_options']);
    }
}
