<?php
/**
 * AdminNotice unit tests.
 *
 * #43 Phase 5 (plan D.5 / E.1 steps 9 + 11 / G adjustment #2):
 * verifies the admin capability-fallback notice:
 *  - branch logic: nginx → warning + nginx snippet (with [0-9a-f]+,
 *    NOT {16}); apache 'no' → AllowOverride warning; apache
 *    'unknown' → info; litespeed → info; unknown → info.
 *  - nginx notice CONTAINS the nginx snippet + perf numbers + LCP
 *    framing.
 *  - NOT rendered when capability='yes' or not-LocalBackend or
 *    not-manage_options or not-admin.
 *  - dismissal persists + re-surfaces on capability change.
 *  - co-install notice fires when a competitor is "active"
 *    (stubbed is_plugin_active).
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Integration\WordPress\Admin\AdminNotice;
use PHPUnit\Framework\TestCase;

class AdminNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_is_admin'] = true;
        $GLOBALS['__oxpulse_current_user_can'] = ['manage_options' => true];
        $GLOBALS['__oxpulse_active_plugins'] = [];
        unset($_SERVER['SERVER_SOFTWARE']);
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_actions'],
            $GLOBALS['__oxpulse_is_admin'],
            $GLOBALS['__oxpulse_current_user_can'],
            $GLOBALS['__oxpulse_active_plugins'],
        );
        unset($_SERVER['SERVER_SOFTWARE']);
        // Reset the static script-emitted guard between tests.
        $ref = new \ReflectionClass(AdminNotice::class);
        $prop = $ref->getProperty('scriptEmitted');
        $prop->setAccessible(true);
        $prop->setValue(null, false);
    }

    private function localBackendActive(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
    }

    // ─── environment detection ─────────────────────────────────────────

    public function test_detect_environment_nginx(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';
        $notice = new AdminNotice();
        $this->assertSame('nginx', $notice->detectEnvironment());
    }

    public function test_detect_environment_apache(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.41 (Ubuntu)';
        $notice = new AdminNotice();
        $this->assertSame('apache', $notice->detectEnvironment());
    }

    public function test_detect_environment_litespeed(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed/6.2';
        $notice = new AdminNotice();
        $this->assertSame('litespeed', $notice->detectEnvironment());
    }

    public function test_detect_environment_unknown(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Caddy/2.7';
        $notice = new AdminNotice();
        $this->assertSame('unknown', $notice->detectEnvironment());
    }

    // ─── nginx snippet form ([0-9a-f]+ NOT {16}, has Vary) ─────────────

    public function test_nginx_snippet_uses_hex_class_not_quantifier(): void
    {
        $notice = new AdminNotice();
        $snippet = $notice->buildNginxSnippet();
        $this->assertStringContainsString('([0-9a-f]+)/', $snippet, 'snippet must use [0-9a-f]+ not {16}');
        $this->assertStringNotContainsString('{16}', $snippet, 'snippet must NOT use the {16} quantifier (bug #40)');
        $this->assertStringContainsString('add_header Vary Accept;', $snippet, 'snippet must set Vary: Accept');
        $this->assertStringContainsString('try_files $uri', $snippet);
        $this->assertStringContainsString('?k=$2', $snippet);
        $this->assertStringContainsString('deny all;', $snippet, 'snippet must include the deny-php location');
    }

    public function test_nginx_snippet_derives_paths_from_home_url(): void
    {
        $notice = new AdminNotice();
        $snippet = $notice->buildNginxSnippet();
        // home_url stub → https://example.test/<path>
        $this->assertStringContainsString('/wp-content/cache/oxpulse/', $snippet);
        $this->assertStringContainsString('/wp-content/oxpulse-img.php', $snippet);
    }

    // ─── branch logic: right message per environment ───────────────────

    public function test_nginx_branch_is_warning_with_snippet_and_perf(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';
        $notice = new AdminNotice();
        $built = $notice->buildCapabilityNotice('no', 'nginx');
        $this->assertNotNull($built);
        $this->assertSame('capability_nginx', $built['key']);
        $this->assertSame('warning', $built['class']);
        $html = $built['html'];
        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('oxpulse-nginx-snippet', $html, 'nginx notice must inline the snippet');
        $this->assertStringContainsString('try_files $uri', $html, 'nginx notice must contain the snippet body');
        $this->assertStringContainsString('Re-test capability', $html, 'nginx notice must have the Re-test button');
        // Perf quantification (G adjustment #2) — numbers + LCP/CWV framing.
        $this->assertStringContainsString('50-200ms', $html);
        $this->assertStringContainsString('5-20ms', $html);
        $this->assertStringContainsString('Largest Contentful Paint', $html);
        $this->assertStringContainsString('2.5s', $html);
        $this->assertStringContainsString('Core Web Vitals', $html);
    }

    public function test_apache_no_branch_is_allowoverride_warning(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
        $notice = new AdminNotice();
        $built = $notice->buildCapabilityNotice('no', 'apache');
        $this->assertSame('capability_apache', $built['key']);
        $this->assertSame('warning', $built['class']);
        $this->assertStringContainsString('notice-warning', $built['html']);
        $this->assertStringContainsString('AllowOverride', $built['html']);
        $this->assertStringContainsString('Re-test capability', $built['html']);
        $this->assertStringNotContainsString('oxpulse-nginx-snippet', $built['html'], 'apache notice must NOT inline the nginx snippet');
    }

    public function test_apache_unknown_branch_is_info(): void
    {
        $notice = new AdminNotice();
        $built = $notice->buildCapabilityNotice('unknown', 'apache');
        $this->assertSame('info', $built['class']);
        $this->assertStringContainsString('notice-info', $built['html']);
        $this->assertStringContainsString('Re-test capability', $built['html']);
    }

    public function test_litespeed_branch_is_info(): void
    {
        $notice = new AdminNotice();
        $built = $notice->buildCapabilityNotice('no', 'litespeed');
        $this->assertSame('capability_litespeed', $built['key']);
        $this->assertSame('info', $built['class']);
        $this->assertStringContainsString('notice-info', $built['html']);
        $this->assertStringContainsString('LiteSpeed', $built['html']);
        $this->assertStringContainsString('Re-test capability', $built['html']);
    }

    public function test_unknown_branch_is_info(): void
    {
        $notice = new AdminNotice();
        $built = $notice->buildCapabilityNotice('unknown', 'unknown');
        $this->assertSame('capability_unknown', $built['key']);
        $this->assertSame('info', $built['class']);
        $this->assertStringContainsString('Re-test capability', $built['html']);
    }

    public function test_capability_yes_returns_null(): void
    {
        $notice = new AdminNotice();
        $this->assertNull($notice->buildCapabilityNotice('yes', 'nginx'));
    }

    // ─── render() gating: NOT rendered when not-applicable ─────────────

    public function test_render_noop_when_not_admin(): void
    {
        $GLOBALS['__oxpulse_is_admin'] = false;
        $this->localBackendActive();
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'no');
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';

        $out = $this->captureRender();
        $this->assertSame('', $out, 'no notice when not admin');
    }

    public function test_render_noop_when_not_manage_options(): void
    {
        $GLOBALS['__oxpulse_current_user_can'] = [];
        $this->localBackendActive();
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'no');
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';

        $out = $this->captureRender();
        $this->assertSame('', $out, 'no notice without manage_options');
    }

    public function test_render_noop_when_not_local_backend(): void
    {
        // imgproxy endpoint configured → LocalBackend NOT active.
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'no');
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';

        $out = $this->captureRender();
        $this->assertStringNotContainsString('capability_nginx', $out, 'no capability notice when imgproxy active');
    }

    public function test_render_noop_when_capability_yes(): void
    {
        $this->localBackendActive();
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'yes');
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';

        $out = $this->captureRender();
        $this->assertStringNotContainsString('capability_nginx', $out, 'no notice when capability is yes');
    }

    public function test_render_emits_nginx_notice_when_fallback(): void
    {
        $this->localBackendActive();
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'no');
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';

        $out = $this->captureRender();
        $this->assertStringContainsString('oxpulse-nginx-snippet', $out);
        $this->assertStringContainsString('try_files $uri', $out);
        $this->assertStringContainsString('Re-test capability', $out);
    }

    // ─── dismissal: persists + re-surfaces on capability change ────────

    public function test_dismissed_notice_stays_hidden_for_same_capability(): void
    {
        $this->localBackendActive();
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'no');
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';
        $repo = new OptionSettingsRepository();
        $repo->dismissNotice('capability_nginx', 'no');

        $out = $this->captureRender();
        $this->assertStringNotContainsString('oxpulse-nginx-snippet', $out, 'dismissed notice must not render');
    }

    public function test_dismissed_notice_re_surfaces_on_capability_flip(): void
    {
        $this->localBackendActive();
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';
        $repo = new OptionSettingsRepository();
        // Dismissed when capability was 'no'.
        $repo->dismissNotice('capability_nginx', 'no');

        // Capability flips to 'unknown' → stored 'no' != 'unknown' → re-notify.
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'unknown');
        $out = $this->captureRender();
        $this->assertStringContainsString('oxpulse-nginx-snippet', $out, 'notice must re-surface after capability flip');
    }

    // ─── co-install notice (step 11) ───────────────────────────────────

    public function test_co_install_notice_fires_when_competitor_active(): void
    {
        $GLOBALS['__oxpulse_active_plugins'] = [
            'webp-express/webp-express.php' => true,
        ];
        $notice = new AdminNotice();
        $built = $notice->buildCoInstallNotice();
        $this->assertNotNull($built);
        $this->assertStringContainsString('WebP Express', $built['html']);
        $this->assertStringContainsString('notice-info', $built['html']);
        $this->assertStringContainsString('double-rewrite', $built['html']);
        $this->assertStringContainsString('coinstall_', $built['key']);
    }

    public function test_co_install_notice_null_when_no_competitor(): void
    {
        $notice = new AdminNotice();
        $this->assertNull($notice->buildCoInstallNotice());
    }

    public function test_co_install_notice_re_notifies_on_set_change(): void
    {
        $GLOBALS['__oxpulse_active_plugins'] = [
            'webp-express/webp-express.php' => true,
        ];
        $notice = new AdminNotice();
        $built = $notice->buildCoInstallNotice();
        $repo = new OptionSettingsRepository();
        $repo->dismissNotice($built['key'], 'active');

        // A second competitor added → new key → re-notify.
        $GLOBALS['__oxpulse_active_plugins'] = [
            'webp-express/webp-express.php' => true,
            'ewww-image-optimizer/ewww-image-optimizer.php' => true,
        ];
        $built2 = $notice->buildCoInstallNotice();
        $this->assertNotSame($built['key'], $built2['key'], 'different plugin set must produce a different key');
        $this->assertFalse($repo->isNoticeDismissed($built2['key'], 'active'));
    }

    public function test_co_install_notice_renders_even_when_capability_yes(): void
    {
        $this->localBackendActive();
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'yes');
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';
        $GLOBALS['__oxpulse_active_plugins'] = [
            'webp-converter-for-media/webp-converter-for-media.php' => true,
        ];

        $out = $this->captureRender();
        $this->assertStringContainsString('Converter for Media', $out, 'co-install notice renders regardless of capability state');
        $this->assertStringNotContainsString('oxpulse-nginx-snippet', $out, 'capability notice suppressed when yes');
    }

    // ─── register() hooks admin_notices ────────────────────────────────

    public function test_register_hooks_admin_notices(): void
    {
        $notice = new AdminNotice();
        $notice->register();
        $hooked = false;
        foreach ($GLOBALS['__oxpulse_actions'] ?? [] as $action) {
            if ($action['hook'] === 'admin_notices') {
                $hooked = true;
                break;
            }
        }
        $this->assertTrue($hooked, 'register() must hook admin_notices');
    }

    // ─── helper: capture render() output ───────────────────────────────

    private function captureRender(): string
    {
        $notice = new AdminNotice();
        ob_start();
        $notice->render();
        return (string) ob_get_clean();
    }
}
