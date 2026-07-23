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

use OXPulse\Imager\Infrastructure\Image\ImageTransformer;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Integration\WordPress\Admin\AdminNotice;
use OXPulse\Imager\Integration\WordPress\Admin\CapabilityRestController;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

class AdminNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_is_admin'] = true;
        $GLOBALS['__oxpulse_is_multisite'] = false;
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
            $GLOBALS['__oxpulse_is_multisite'],
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
        // Route through the REAL dismiss resolution (the same helper
        // handleDismiss() + the render gate use), NOT a hand-written
        // 'active' literal — otherwise the test masks a state-key
        // mismatch between the two ends (#57 review MAJOR).
        $repo->dismissNotice($built['key'], AdminNotice::noticeDismissState($built['key'], $repo));

        // A second competitor added → new key → re-notify.
        $GLOBALS['__oxpulse_active_plugins'] = [
            'webp-express/webp-express.php' => true,
            'ewww-image-optimizer/ewww-image-optimizer.php' => true,
        ];
        $built2 = $notice->buildCoInstallNotice();
        $this->assertNotSame($built['key'], $built2['key'], 'different plugin set must produce a different key');
        $this->assertFalse($repo->isNoticeDismissed($built2['key'], AdminNotice::noticeDismissState($built2['key'], $repo)));
    }

    /**
     * #57 review MAJOR regression lock: dismissing a co-install notice
     * via the REAL handleDismiss() REST path must suppress it on the
     * next render(). Pre-fix, handleDismiss() stored the live
     * capability ('no'|'unknown'|…) while the render gate checked the
     * literal 'active' → the stored state never matched → the notice
     * re-rendered forever, un-silenceable. This test drives the actual
     * production dismiss path (no hand-written dismiss state) and
     * asserts the notice stays hidden. Goes RED on the pre-fix code.
     */
    public function test_co_install_notice_dismissed_via_real_handle_dismiss_stays_hidden(): void
    {
        // imgproxy backend → LocalBackend NOT active → render() only
        // emits the co-install notice (clean isolation from the
        // capability notice).
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        $GLOBALS['__oxpulse_active_plugins'] = [
            'webp-converter-for-media/webp-converter-for-media.php' => true,
        ];

        // Build the notice to obtain its key, then dismiss it via the
        // REAL handleDismiss() path (the same code the dismiss button
        // hits in production).
        $notice = new AdminNotice();
        $built = $notice->buildCoInstallNotice();
        $this->assertNotNull($built);

        $controller = new CapabilityRestController();
        $request = new WP_REST_Request(['noticeKey' => $built['key']]);
        $response = $controller->handleDismiss($request);
        $this->assertFalse($response instanceof \WP_Error, 'handleDismiss must succeed for a co-install key');

        // After the real dismiss, render() must NOT re-emit the
        // co-install notice. Pre-fix this asserted-against the still-
        // rendering notice (stored capability != 'active' gate).
        $out = $this->captureRender();
        $this->assertStringNotContainsString(
            'Converter for Media',
            $out,
            'co-install notice dismissed via real handleDismiss() must NOT re-render'
        );
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

    // ─── #87: multisite LocalBackend-unsupported notice ───────────────

    /**
     * #87: buildMultisiteNotice() mirrors buildLitespeedNotice() — a
     * dismissable info notice, keyed `multisite_local_unsupported`, with
     * NO "Re-test capability" button (the fix is "configure imgproxy",
     * not a re-probe).
     */
    public function test_build_multisite_notice_is_dismissable_info_without_retest_button(): void
    {
        $notice = new AdminNotice();
        $built = $notice->buildMultisiteNotice();
        $this->assertNotNull($built);
        $this->assertSame('multisite_local_unsupported', $built['key']);
        $this->assertSame('info', $built['class']);
        $this->assertStringContainsString('notice-info', $built['html']);
        $this->assertStringContainsString('is-dismissible', $built['html'], 'multisite notice must be dismissable');
        $this->assertStringContainsString('Multisite', $built['html']);
        $this->assertStringContainsString('imgproxy', $built['html']);
        $this->assertStringNotContainsString('Re-test capability', $built['html'], 'multisite notice must NOT have a Re-test button');
        $this->assertStringNotContainsString('oxpulse-retest-btn', $built['html'], 'multisite notice must NOT have a retest button class');
    }

    /**
     * #87: on multisite + LocalBackend-active (endpoint empty), render()
     * emits the multisite notice and skips the capability notice.
     */
    public function test_render_emits_multisite_notice_when_multisite_and_local_backend_active(): void
    {
        $GLOBALS['__oxpulse_is_multisite'] = true;
        $this->localBackendActive();
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'no');
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';

        $out = $this->captureRender();
        $this->assertStringContainsString('multisite_local_unsupported', $out, 'multisite notice must render on multisite + LocalBackend active');
        $this->assertStringContainsString('Multisite', $out);
        // The capability notice must be SKIPPED in the multisite branch.
        $this->assertStringNotContainsString('oxpulse-nginx-snippet', $out, 'capability notice must NOT render on multisite');
        $this->assertStringNotContainsString('Re-test capability', $out, 'capability Re-test button must NOT render on multisite');
    }

    /**
     * #87: single-site + LocalBackend-active → the multisite notice must
     * NOT render (unchanged behavior — the capability notice path runs).
     */
    public function test_render_no_multisite_notice_on_single_site(): void
    {
        $GLOBALS['__oxpulse_is_multisite'] = false;
        $this->localBackendActive();
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'no');
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';

        $out = $this->captureRender();
        $this->assertStringNotContainsString('multisite_local_unsupported', $out, 'multisite notice must NOT render on single-site');
        $this->assertStringContainsString('oxpulse-nginx-snippet', $out, 'capability notice still renders on single-site (unchanged)');
    }

    /**
     * #87: multisite + imgproxy configured (LocalBackend NOT active) →
     * no multisite notice (the operator already has a supported backend).
     */
    public function test_render_no_multisite_notice_when_imgproxy_configured_on_multisite(): void
    {
        $GLOBALS['__oxpulse_is_multisite'] = true;
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'no');
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';

        $out = $this->captureRender();
        $this->assertStringNotContainsString('multisite_local_unsupported', $out, 'no multisite notice when imgproxy is configured');
    }

    /**
     * #87: the multisite notice is dismissable (keyed dismiss, capability-
     * independent like co-install). After dismissing, render() must NOT
     * re-emit it.
     */
    public function test_multisite_notice_dismissed_stays_hidden(): void
    {
        $GLOBALS['__oxpulse_is_multisite'] = true;
        $this->localBackendActive();
        update_option(OptionSettingsRepository::OPTION_REWRITE_CAPABILITY, 'no');
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';

        $repo = new OptionSettingsRepository();
        $repo->dismissNotice('multisite_local_unsupported', AdminNotice::noticeDismissState('multisite_local_unsupported', $repo));

        $out = $this->captureRender();
        $this->assertStringNotContainsString('multisite_local_unsupported', $out, 'dismissed multisite notice must NOT render');
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

    // ─── #90: no-encoder + imgproxy-down notices ─────────────────────

    /**
     * #90: no-encoder notice renders when LocalBackend is active and the
     * host has neither WebP nor AVIF encode support.
     */
    public function test_render_no_encoder_notice_when_local_backend_active_and_no_encoder(): void
    {
        $this->localBackendActive();
        $transformer = new class extends ImageTransformer {
            public function supportsWebp(): bool { return false; }
            public function supportsAvif(): bool { return false; }
        };

        $out = $this->captureRender($transformer);

        $this->assertStringContainsString('no_encoder', $out);
        $this->assertStringContainsString('no image encoder', $out);
        $this->assertStringContainsString('notice-warning', $out);
    }

    /**
     * #90: no-encoder notice must NOT render when an imgproxy endpoint is
     * configured — the server-side imgproxy encodes, local encoder irrelevant.
     */
    public function test_no_encoder_notice_not_rendered_when_imgproxy_configured(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        $transformer = new class extends ImageTransformer {
            public function supportsWebp(): bool { return false; }
            public function supportsAvif(): bool { return false; }
        };

        $out = $this->captureRender($transformer);

        $this->assertStringNotContainsString('no_encoder', $out);
    }

    /**
     * #90: no-encoder notice must NOT render when at least one encoder
     * (WebP or AVIF) is available.
     */
    public function test_no_encoder_notice_not_rendered_when_encoder_exists(): void
    {
        $this->localBackendActive();
        $transformer = new class extends ImageTransformer {
            public function supportsWebp(): bool { return true; }
            public function supportsAvif(): bool { return false; }
        };

        $out = $this->captureRender($transformer);

        $this->assertStringNotContainsString('no_encoder', $out);
    }

    /**
     * #90: a dismissed no-encoder notice stays hidden (keyed-dismiss, cap-
     * ability-independent ACTIVE marker like co-install/multisite).
     */
    public function test_no_encoder_notice_dismissed_stays_hidden(): void
    {
        $this->localBackendActive();
        $transformer = new class extends ImageTransformer {
            public function supportsWebp(): bool { return false; }
            public function supportsAvif(): bool { return false; }
        };
        $repo = new OptionSettingsRepository();
        $repo->dismissNotice('no_encoder', AdminNotice::noticeDismissState('no_encoder', $repo));

        $out = $this->captureRender($transformer);

        $this->assertStringNotContainsString('no_encoder', $out);
    }

    /**
     * #90: imgproxy-down notice renders when an endpoint is configured and
     * the cached health is 'down'.
     */
    public function test_render_imgproxy_down_notice_when_endpoint_set_and_health_down(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        update_option(ImgproxyHealthCache::OPTION, 'down');

        $out = $this->captureRender();

        $this->assertStringContainsString('imgproxy_down', $out);
        $this->assertStringContainsString('endpoint is currently unreachable', $out);
        $this->assertStringContainsString('notice-warning', $out);
    }

    /**
     * #90: imgproxy-down notice must NOT render when health is 'up'.
     */
    public function test_imgproxy_down_notice_not_rendered_when_health_up(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        update_option(ImgproxyHealthCache::OPTION, 'up');

        $out = $this->captureRender();

        $this->assertStringNotContainsString('imgproxy_down', $out);
    }

    /**
     * #90: imgproxy-down notice must NOT render when LocalBackend is active
     * (no imgproxy endpoint) regardless of health.
     */
    public function test_imgproxy_down_notice_not_rendered_when_no_endpoint(): void
    {
        $this->localBackendActive();
        update_option(ImgproxyHealthCache::OPTION, 'down');

        $out = $this->captureRender();

        $this->assertStringNotContainsString('imgproxy_down', $out);
    }

    /**
     * #90: imgproxy-down dismissal auto-clears when health returns to 'up'
     * so a later outage re-notifies (non-permanent dismiss keyed on live
     * 'down' state).
     */
    public function test_imgproxy_down_notice_auto_clears_on_recovery_and_renotifies(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        update_option(ImgproxyHealthCache::OPTION, 'down');

        $repo = new OptionSettingsRepository();
        $repo->dismissNotice('imgproxy_down', AdminNotice::noticeDismissState('imgproxy_down', $repo));

        // Dismissed while down → hidden.
        $out = $this->captureRender();
        $this->assertStringNotContainsString('imgproxy_down', $out);

        // Health recovers → the next render clears the imgproxy_down dismissal.
        update_option(ImgproxyHealthCache::OPTION, 'up');
        $this->captureRender();

        // New outage → notice re-appears.
        update_option(ImgproxyHealthCache::OPTION, 'down');
        $out = $this->captureRender();
        $this->assertStringContainsString('imgproxy_down', $out);
    }

    // ─── helper: capture render() output ───────────────────────────────

    private function captureRender(?ImageTransformer $transformer = null): string
    {
        $notice = new AdminNotice(null, $transformer);
        ob_start();
        $notice->render();
        return (string) ob_get_clean();
    }
}
