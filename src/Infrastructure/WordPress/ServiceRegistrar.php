<?php
/**
 * Service registrar.
 *
 * Registers WordPress hooks only in appropriate contexts. Keeps side
 * effects at hook-registration time and makes dependencies injectable
 * for tests. The plugin remains a frontend no-op while delivery is
 * disabled.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\WordPress;

use OXPulse\Imager\Application\Delivery\DeliveryBackendFactory;
use OXPulse\Imager\Application\Delivery\LqipPlaceholderBuilder;
use OXPulse\Imager\Application\Delivery\PictureElementWrapper;
use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Application\Diagnostics\DiagnosticLoggerInterface;
use OXPulse\Imager\Application\Health\HealthCheckService;
use OXPulse\Imager\Application\Prewarm\AsyncPrewarmService;
use OXPulse\Imager\Application\Prewarm\PrewarmJobStore;
use OXPulse\Imager\Application\Prewarm\PrewarmService;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Infrastructure\Http\WordPressHealthClient;
use OXPulse\Imager\Infrastructure\Local\CapabilityTester;
use OXPulse\Imager\Infrastructure\Local\LocalDeliveryInstaller;
use OXPulse\Imager\Integration\WordPress\Admin\AdminBarDiagnostics;
use OXPulse\Imager\Integration\WordPress\Admin\AdminNotice;
use OXPulse\Imager\Integration\WordPress\Admin\CapabilityRestController;
use OXPulse\Imager\Integration\WordPress\Admin\DiagnosticsRestController;
use OXPulse\Imager\Integration\WordPress\Admin\HealthRestController;
use OXPulse\Imager\Integration\WordPress\Admin\OptionsRestController;
use OXPulse\Imager\Integration\WordPress\Admin\PrewarmRestController;
use OXPulse\Imager\Integration\WordPress\Admin\SettingsPage;
use OXPulse\Imager\Integration\WordPress\Admin\StatusRestController;
use OXPulse\Imager\Integration\WordPress\Cli\CliServiceProvider;
use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentImageSrcRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentUrlRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\AvatarRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\BufferRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\ContentImgTagRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\ImageDownsizeRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\IntermediateSizeRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\SrcsetRewriter;
use OXPulse\Imager\Integration\WordPress\Compatibility\RankMathCompatibility;
use OXPulse\Imager\Integration\WordPress\Performance\CachePurger;
use OXPulse\Imager\Integration\WordPress\Performance\OptimizationDetectiveIntegration;
use OXPulse\Imager\Infrastructure\Local\CacheInvalidator;
use OXPulse\Imager\Plugin;

final class ServiceRegistrar
{
    /**
     * Shared diagnostic logger instance. Created once and passed to
     * both the frontend UrlRewriter (for recording entries) and the
     * admin bar + REST controller (for reading the summary).
     */
    private static ?WordPressDiagnosticLogger $diagnosticLogger = null;

    /**
     * Shared frontend UrlRewriter instance. Set when
     * registerDeliveryAdapters() runs (on plugins_loaded when delivery
     * is enabled + not admin). Accessed by the public
     * oxpulse_thumb_url() helper so sibling mu-plugins (e.g. piter-api)
     * can generate signed imgproxy URLs without duplicating the
     * signing + path-building logic.
     *
     * Null when delivery is disabled, not yet initialized, or in admin
     * context. Callers must handle null (fail-safe: return the
     * original URL).
     */
    private static ?UrlRewriter $rewriter = null;

    public static function register(Plugin $plugin): void
    {
        self::registerHealthGate($plugin);
        self::registerAdminSettings($plugin);
        self::registerCli($plugin);
        self::registerPerformanceIntegration($plugin);
        self::registerAsyncPrewarmCron($plugin);
        self::registerLocalCacheInvalidation($plugin);
        self::registerLocalDeliverySettingsSync($plugin);
        self::maybeReprobeOnVersionUpdate();
    }

    /**
     * Get the shared diagnostic logger instance (lazy-initialized).
     */
    public static function diagnosticLogger(): WordPressDiagnosticLogger
    {
        if (self::$diagnosticLogger === null) {
            self::$diagnosticLogger = new WordPressDiagnosticLogger();
        }
        return self::$diagnosticLogger;
    }

    /**
     * Get the shared frontend UrlRewriter instance, or null when delivery
     * is disabled / not yet initialized / admin context.
     *
     * Used by the public oxpulse_thumb_url() global helper so sibling
     * mu-plugins can generate signed imgproxy URLs without duplicating
     * the signing + path-building logic. Callers must handle null
     * (fail-safe: return the original URL).
     */
    public static function getRewriter(): ?UrlRewriter
    {
        return self::$rewriter;
    }

    /**
     * Frontend delivery gate. While delivery is disabled (default state),
     * no URL-rewriting hooks are registered. When enabled, wires the
     * three rewriting adapters (content img tags, srcset, attachment
     * image src) only on frontend requests.
     */
    private static function registerHealthGate(Plugin $plugin): void
    {
        add_action('plugins_loaded', static function (): void {
            if (!self::deliveryEnabled()) {
                return;
            }

            // Never rewrite in admin context — admin must see original
            // URLs for media library, regeneration, and debugging.
            if (is_admin()) {
                return;
            }

            self::registerDeliveryAdapters();
        });
    }

    /**
     * Register the frontend delivery adapters. Each adapter
     * delegates to the shared UrlRewriter which enforces source policy,
     * signing availability, and fail-safe preservation.
     *
     * Five filters covered:
     * - wp_content_img_tag: <img> tags in post content (src + srcset)
     * - wp_calculate_image_srcset: responsive srcset arrays
     * - wp_get_attachment_image_src: [url, w, h, is_intermediate] arrays
     * - wp_get_attachment_url: raw attachment URLs (image extensions only)
     * - get_avatar: avatar <img> tags (Gravatar + custom)
     */
    private static function registerDeliveryAdapters(): void
    {
        $repository = new OptionSettingsRepository();
        $delivery = $repository->loadDeliveryConfig();
        $signing = $repository->loadSigningConfig();

        // Resolve a relative endpoint (e.g. '/imgproxy') to an absolute
        // URL against home_url() so all filtered image URLs are absolute
        // — required by wp_get_attachment_url, JSON-LD, og:image, feeds.
        $delivery = $delivery->withEndpoint(
            OptionSettingsRepository::resolveEndpoint($delivery->endpoint)
        );

        $logger = self::diagnosticLogger();

        // Dispatch 3: select the delivery backend via the factory seam.
        // imgproxy endpoint non-empty → ImgproxyBackend (byte-identical
        // to the pre-seam lazy path, verified by DeliveryBackendSeamTest);
        // empty → LocalBackend (on-disk WebP delivery). The selected
        // backend is injected into UrlRewriter so the rewrite path
        // actually exercises it (previously the backend was left null
        // and UrlRewriter always lazily constructed an ImgproxyBackend,
        // so LocalBackend was never reached at runtime).
        $backend = DeliveryBackendFactory::select($delivery, $signing);
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing, $logger, $backend);

        // Expose the rewriter for the public oxpulse_thumb_url() helper.
        // Sibling mu-plugins (e.g. piter-api) call oxpulse_thumb_url()
        // which delegates to ServiceRegistrar::getRewriter().
        self::$rewriter = $rewriter;

        // Schedule the logger flush at shutdown — writes the
        // accumulated entries to error_log once per request.
        add_action('shutdown', [$logger, 'flush']);

        // Admin bar item — shows rewrite counts for the current page.
        $adminBar = new AdminBarDiagnostics($logger);
        $adminBar->register();

        // Phase 5.1: LQIP placeholder builder (only when enabled).
        $lqipBuilder = $delivery->lqipEnabled ? new LqipPlaceholderBuilder($rewriter) : null;

        // Phase 1: <picture> element wrapper (default OFF via
        // pictureEnabled). Always constructed and injected — the
        // pictureEnabled flag + the oxpulse_picture_enabled filter
        // gate the actual wrapping at rewrite time inside
        // ContentImgTagRewriter::rewrite(), mirroring the
        // bufferRewritingEnabled / oxpulse_buffer_rewrite_enabled shape.
        $pictureWrapper = new PictureElementWrapper($rewriter);

        $contentRewriter = new ContentImgTagRewriter($rewriter, $delivery, $lqipBuilder, $pictureWrapper);
        $srcsetRewriter = new SrcsetRewriter($rewriter);
        $attachmentRewriter = new AttachmentImageSrcRewriter($rewriter);
        $attachmentUrlRewriter = new AttachmentUrlRewriter($rewriter);
        $avatarRewriter = new AvatarRewriter($rewriter);
        $intermediateRewriter = new IntermediateSizeRewriter($rewriter);

        add_filter('wp_content_img_tag', [$contentRewriter, 'rewrite'], 10, 3);
        add_filter('wp_calculate_image_srcset', [$srcsetRewriter, 'rewrite'], 10, 5);
        add_filter('wp_get_attachment_image_src', [$attachmentRewriter, 'rewrite'], 10, 4);
        add_filter('wp_get_attachment_url', [$attachmentUrlRewriter, 'rewrite'], 10, 2);
        add_filter('get_avatar', [$avatarRewriter, 'rewrite'], 10, 5);

        // CRITICAL: image_get_intermediate_size must run BEFORE WordPress
        // core builds the URL via path_join(dirname(wp_get_attachment_url()),
        // $file). Without this, the intermediate URL is built from the
        // already-rewritten imgproxy URL (encoded source segment) and
        // path_join replaces the encoded segment with the intermediate
        // filename basename → imgproxy 403 "Invalid signature".
        // Priority 1 (early) so we rebuild $data['url'] before any other
        // filter inspects it. Recursion guard inside the handler prevents
        // re-entry via wp_get_attachment_url.
        add_filter('image_get_intermediate_size', [$intermediateRewriter, 'rewrite'], 1, 3);

        // Ф5: image_downsize at priority 99 (late, to override earlier
        // filters). Catches plugins/themes that call image_downsize()
        // directly, bypassing wp_get_attachment_image_src. Recursion
        // guard inside the handler prevents infinite loops via
        // wp_get_attachment_url (which is also hooked above).
        $downsizeRewriter = new ImageDownsizeRewriter($rewriter);
        add_filter('image_downsize', [$downsizeRewriter, 'rewrite'], 99, 3);

        // Ф6: get_site_icon_url — favicon/site icon optimization. The
        // site icon is served through get_site_icon_url() which bypasses
        // the 5 main filters. Pass $size as both width and height
        // (favicons are square). UrlRewriter handles fail-safe
        // preservation (returns original URL when not allowed/disabled).
        add_filter('get_site_icon_url', static function (string $url, int $size, int $blog_id) use ($rewriter): string {
            if ($url === '') {
                return $url;
            }
            $result = $rewriter->rewrite($url, $size, $size, 'site_icon');
            return $result->url;
        }, 10, 3);

        // Ф2: Buffer rewriting for theme-hardcoded <img> tags (e.g. Foxiz).
        // Registered AFTER the 5 filters above so wp_content_img_tag etc.
        // run first; the buffer regex only matches /wp-content/ URLs that
        // the filters missed (imgproxy URLs don't contain /wp-content/, so
        // already-rewritten tags are not double-rewritten).
        if ($delivery->bufferRewritingEnabled) {
            $bufferRewriter = new BufferRewriter($rewriter, $delivery);
            $bufferRewriter->register();
        }

        // Ф3: RankMath og:image compatibility. Restores direct attachment
        // URLs in OpenGraph/Twitter meta tags so RankMath's filetype
        // validation doesn't drop them. Default true — safe to register
        // unconditionally (the filter is a no-op when RankMath isn't active).
        if ($delivery->rankMathCompatibility) {
            $rankMath = new RankMathCompatibility();
            $rankMath->register();
        }

        // #43 Phase 2: the auto-register-FallbackRewriter-on-fallbackNeeded
        // block is REMOVED. After Phase 2, LocalBackend emits ?k= URLs
        // DIRECTLY through the collision-safe wp_content_img_tag filter
        // (and the other 5 filters above), so the post-hoc output-buffer
        // rewrite of clean URLs is no longer needed for the content path.
        // BufferRewriter (gated on bufferRewritingEnabled, default OFF)
        // remains as the explicit opt-in for THEME-HARDCODED <img> tags
        // (Foxiz) — its original purpose.
        //
        // #43 Phase 3: FallbackRewriter itself is now REMOVED. The
        // idempotency guard in UrlRewriter (isAlreadyRewritten) handles
        // the cache-URL detection that FallbackRewriter's regex did, and
        // LocalBackend emits ?k= URLs directly. The HtaccessGenerator
        // still emits rewrite rules for clean cache URLs (Apache), but
        // the PHP-side buffer rewriter for nginx is no longer needed.
    }

    /**
     * Register the admin settings page (React SPA shell) + REST
     * controllers backing it (options, health, prewarm). Only wired
     * when is_admin() is true so the frontend never loads admin
     * dependencies.
     */
    private static function registerAdminSettings(Plugin $plugin): void
    {
        if (!is_admin()) {
            return;
        }

        $repository = new OptionSettingsRepository();
        $validator = new SettingsValidator();

        // React SPA shell — enqueues the admin bundle, mounts into
        // #oxpulse-admin-root.
        $page = new SettingsPage();
        $page->register();

        // "Settings" action link on the Plugins list page — jumps
        // directly to Settings > OXPulse Imager. Standard WP pattern;
        // only added for this plugin's row, not all plugins.
        $pluginFile = $plugin->file();
        add_filter('plugin_action_links_' . plugin_basename($pluginFile), static function (array $links): array {
            $settingsUrl = admin_url('options-general.php?page=' . SettingsPage::PAGE_SLUG);
            $settingsLink = sprintf(
                '<a href="%s">%s</a>',
                esc_url($settingsUrl),
                esc_html__('Settings', 'oxpulse-imager')
            );
            array_unshift($links, $settingsLink);
            return $links;
        });

        // REST: GET|POST /oxpulse/v1/options — settings read/write.
        $optionsRest = new OptionsRestController($repository, $validator);
        $optionsRest->register();

        // REST: POST /oxpulse/v1/health + /avif-check — health checks.
        $healthCheck = new HealthCheckService(new WordPressHealthClient());
        $healthRest = new HealthRestController($healthCheck);
        $healthRest->register();

        // REST: POST /oxpulse/v1/prewarm — bulk cache pre-warm.
        $prewarmRest = new PrewarmRestController($repository);
        $prewarmRest->register();

        // REST: GET /oxpulse/v1/status + /info — status + URL preview.
        $statusRest = new StatusRestController($repository, $healthCheck);
        $statusRest->register();

        // REST: GET/DELETE /oxpulse/v1/diagnostics — recent log entries.
        $diagnosticsRest = new DiagnosticsRestController(self::diagnosticLogger());
        $diagnosticsRest->register();

        // #43 Phase 5: REST: POST /oxpulse/v1/capability/reprobe + /dismiss
        // — the admin "Re-test capability" + notice-dismiss buttons.
        $capabilityRest = new CapabilityRestController();
        $capabilityRest->register();

        // #43 Phase 5: admin notice — tells the operator when the host
        // is on the ?k= PHP fallback (nginx / AllowOverride None /
        // LiteSpeed / probe inconclusive) with the nginx snippet +
        // perf quantification + Re-test button + co-install notice.
        $adminNotice = new AdminNotice($repository);
        $adminNotice->register();
    }

    /**
     * Register WP-CLI commands. Only fires when WP_CLI is available
     * (i.e. when running `wp` from the CLI). CliServiceProvider itself
     * also guards on `class_exists('\WP_CLI')` so this is safe to call
     * unconditionally.
     */
    private static function registerCli(Plugin $plugin): void
    {
        CliServiceProvider::register();
    }

    /**
     * Register Optimization Detective integration + preconnect link.
     * The preconnect to the imgproxy endpoint is always added (via
     * wp_head); the OD tag visitor is only registered when OD is
     * active and Image Prioritizer is NOT (to avoid duplicate preload
     * links).
     */
    private static function registerPerformanceIntegration(Plugin $plugin): void
    {
        $odIntegration = new OptimizationDetectiveIntegration();
        $odIntegration->register();
    }

    /**
     * Register the async pre-warm cron handler. The cron hook
     * (oxpulse_prewarm_process_batch) fires when a job is scheduled
     * and processes one batch of URLs per tick.
     */
    private static function registerAsyncPrewarmCron(Plugin $plugin): void
    {
        // Build the same service stack the REST controller uses.
        $repository = new OptionSettingsRepository();
        $delivery = $repository->loadDeliveryConfig();
        $signing = $repository->loadSigningConfig();

        if (!$delivery->enabled || $delivery->endpoint === '' || $signing === null) {
            // Still register the handler — jobs will be created when
            // config is set, and the handler checks job state at
            // processing time.
        }

        // Resolve relative endpoint to absolute (same as frontend delivery).
        $delivery = $delivery->withEndpoint(
            OptionSettingsRepository::resolveEndpoint($delivery->endpoint)
        );

        // Dispatch 3: use the factory seam (consistent with the frontend
        // delivery path). Prewarming is only meaningful for ImgproxyBackend
        // (LocalBackend fills its cache on first miss, no daemon to warm),
        // but the factory keeps the selection logic in one place.
        $backend = DeliveryBackendFactory::select($delivery, $signing);
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing, null, $backend);
        $syncService = new PrewarmService($rewriter, new \OXPulse\Imager\Infrastructure\Http\WordPressPrewarmClient());
        $asyncService = new AsyncPrewarmService($syncService, new PrewarmJobStore());
        $asyncService->registerCronHandler();
    }

    private static function deliveryEnabled(): bool
    {
        return (bool) get_option(OXPULSE_IMAGER_OPTION_PREFIX . 'enabled', false);
    }

    /**
     * Register local cache invalidation hooks (Phase 6).
     *
     * When LocalBackend is the active delivery backend (no imgproxy
     * endpoint configured), wire the attachment metadata-update / delete
     * / clean-post-cache hooks to the CacheInvalidator so that editing
     * or deleting an attachment purges its cached WebP variants.
     *
     * When ImgproxyBackend is active, the local cache is not used and
     * these hooks are not wired (imgproxy manages its own cache).
     */
    private static function registerLocalCacheInvalidation(Plugin $plugin): void
    {
        add_action('plugins_loaded', static function (): void {
            if (!self::deliveryEnabled()) {
                return;
            }

            // Only wire invalidation when LocalBackend is active (no
            // imgproxy endpoint). When imgproxy is configured, it
            // manages its own cache — the local cache dir is not used.
            // #43 Phase 2 fold-in: shared isLocalBackendActive() predicate.
            $repository = new OptionSettingsRepository();
            $delivery = $repository->loadDeliveryConfig();
            if (!$delivery->isLocalBackendActive()) {
                return;
            }

            $cacheDir = self::resolveLocalCacheDir();
            if ($cacheDir === null) {
                return;
            }

            $invalidator = new CacheInvalidator($cacheDir);

            // Invalidate on attachment metadata update (regeneration,
            // re-upload, edit).
            add_action('wp_update_attachment_metadata', static function (array $metadata, int $postId) use ($invalidator): array {
                $invalidator->invalidateAttachment($postId);
                return $metadata;
            }, 10, 2);

            // Invalidate on attachment deletion.
            add_action('delete_attachment', static function (int $postId) use ($invalidator): void {
                $invalidator->invalidateAttachment($postId);
            }, 10, 1);

            // Invalidate on post cache clean (covers various media
            // editing flows that call clean_post_cache).
            add_action('clean_post_cache', static function (int $postId) use ($invalidator): void {
                // Only act on attachments.
                if (function_exists('get_post_type') && get_post_type($postId) === 'attachment') {
                    $invalidator->invalidateAttachment($postId);
                }
            }, 10, 1);
        });
    }

    /**
     * Resolve the local cache directory path.
     */
    private static function resolveLocalCacheDir(): ?string
    {
        if (defined('WP_CONTENT_DIR')) {
            return WP_CONTENT_DIR . '/cache/oxpulse';
        }

        return null;
    }

    /**
     * Install the LocalBackend delivery endpoint + cache .htaccess.
     *
     * Called from the activation hook AND on settings-save (via the
     * updated_option hook registered below). No-op when ImgproxyBackend
     * is active (endpoint configured) — imgproxy manages its own cache.
     * Also no-op when signing secrets are not yet saved.
     */
    public static function installLocalDelivery(): void
    {
        $installer = self::buildLocalDeliveryInstaller();
        if ($installer === null) {
            return;
        }

        $repository = new OptionSettingsRepository();
        $delivery = $repository->loadDeliveryConfig();
        $delivery = $delivery->withEndpoint(
            OptionSettingsRepository::resolveEndpoint($delivery->endpoint)
        );
        $signing = $repository->loadSigningConfig();

        $installer->install($delivery, $signing);
    }

    /**
     * Uninstall the LocalBackend delivery endpoint + cache .htaccess.
     *
     * Called from the deactivation hook. Removes the generated
     * oxpulse-img.php + cache .htaccess so they don't go stale.
     */
    public static function uninstallLocalDelivery(): void
    {
        $installer = self::buildLocalDeliveryInstaller();
        if ($installer === null) {
            return;
        }
        $installer->uninstall();
    }

    /**
     * #43 Phase 1 review (BLOCKER wire): write-time rewrite-capability
     * probe trigger. Runs the live LocalRewriteProbe (a 3s HTTP
     * round-trip + filesystem writes) ONLY in admin/activation context
     * — never from the front-end read path (rewriteAvailable()).
     *
     * Fires when LocalBackend is active (endpoint empty). Called from:
     *   - plugin activation (oxpulse_imager_activate()),
     *   - settings-save when OPTION_ENDPOINT becomes empty (the
     *     updated_option hook in registerLocalDeliverySettingsSync()),
     *   - once-per-version re-probe (maybeReprobeOnVersionUpdate()).
     *
     * Guarded so it does NOT run on every admin page load — only on
     * activation + the relevant settings-save + the version-mismatch
     * edge. Mirrors how installLocalDelivery is already triggered.
     *
     * Test seam: accepts an injected CapabilityTester (with a stub
     * probe) so tests can verify the trigger without a real HTTP
     * round-trip. Production callers pass null → a real tester is built.
     */
    public static function recheckRewriteCapability(?CapabilityTester $tester = null): void
    {
        $repository = new OptionSettingsRepository();
        $delivery = $repository->loadDeliveryConfig();

        // Only probe when LocalBackend is active (no imgproxy endpoint).
        // Fold-in 2: use the shared isLocalBackendActive() predicate
        // (same idiom as LocalDeliveryInstaller::install()).
        if (!$delivery->isLocalBackendActive()) {
            return;
        }

        $tester = $tester ?? new CapabilityTester();
        $tester->recheck();
        // #43 Phase 2 fold-in: marker action replaces the removed
        // $recheckCallCount test counter. Tests assert via
        // did_action('oxpulse_recheck_rewrite_capability') — no
        // prod-code test state.
        do_action('oxpulse_recheck_rewrite_capability');
    }

    /**
     * #43 Phase 1 review (MAJOR): OPTION_PROBE_VERSION-gated re-probe
     * on plugin update. When the stored probe version does not match
     * the current plugin version AND LocalBackend is active, re-run the
     * probe once and stamp the new version. This lets a stale/unknown
     * result get re-checked after an upgrade (e.g. a host whose
     * loopback was previously blocked may now succeed).
     *
     * Admin-only (the probe is a 3s HTTP round-trip, acceptable in
     * admin/activation context, never on the front-end). Runs at most
     * once per plugin version — the version guard prevents repeat
     * probes on every admin load.
     */
    private static function maybeReprobeOnVersionUpdate(): void
    {
        if (!is_admin()) {
            return;
        }
        if (!defined('OXPULSE_IMAGER_VERSION')) {
            return;
        }

        $repository = new OptionSettingsRepository();
        $storedVersion = $repository->loadProbeVersion();
        if ($storedVersion === OXPULSE_IMAGER_VERSION) {
            return;
        }

        // Re-probe (writes a definitive result or leaves the prior one
        // intact on 'unknown'), then stamp the version so this fires
        // at most once per version.
        self::recheckRewriteCapability();
        $repository->saveProbeVersion(OXPULSE_IMAGER_VERSION);
    }

    /**
     * Build the LocalDeliveryInstaller from the WordPress environment.
     *
     * Returns null when the required paths (WP_CONTENT_DIR, uploads
     * base, plugin src/ dir) cannot be resolved — the installer is
     * a no-op in that case (e.g. during unit tests without a real WP
     * environment).
     */
    private static function buildLocalDeliveryInstaller(): ?LocalDeliveryInstaller
    {
        if (!defined('WP_CONTENT_DIR')) {
            return null;
        }

        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        $uploadsBasedir = $uploads['basedir'] ?? '';
        $uploadsBaseurl = $uploads['baseurl'] ?? '';
        if ($uploadsBasedir === '' || $uploadsBaseurl === '') {
            return null;
        }

        $cacheDir = WP_CONTENT_DIR . '/cache/oxpulse';
        $cacheBaseUrl = rtrim((string) home_url(), '/') . '/wp-content/cache/oxpulse';
        // FIX #45: thread the plugin src/ dir (NOT vendor/autoload.php)
        // — the generated endpoint bakes a self-contained PSR-4 autoloader
        // pointing at src/. vendor/ is export-ignored from the release
        // ZIP, so a vendor path would 500 on every wordpress.org install.
        $srcDir = OXPULSE_IMAGER_DIR . 'src';

        return new LocalDeliveryInstaller(
            wpContentDir: WP_CONTENT_DIR,
            uploadsBasedir: $uploadsBasedir,
            uploadsBaseurl: $uploadsBaseurl,
            cacheDir: $cacheDir,
            cacheBaseUrl: $cacheBaseUrl,
            srcDir: $srcDir,
        );
    }

    /**
     * Register the settings-save hook that re-installs the LocalBackend
     * delivery endpoint when the endpoint / signing key / signing salt
     * options change. Keeps the baked endpoint file in sync with the
     * configured secrets (the endpoint bakes the signing key as a
     * constant at write time, so it must be regenerated when the key
     * rotates).
     */
    private static function registerLocalDeliverySettingsSync(Plugin $plugin): void
    {
        $reinstall = static function (): void {
            self::installLocalDelivery();
        };
        // #43 Phase 1 review (BLOCKER wire): re-probe rewrite capability
        // when LocalBackend becomes active (endpoint emptied). The probe
        // is a 3s HTTP round-trip — acceptable in this settings-save
        // context, never on the front-end read path. Fires only when
        // OPTION_ENDPOINT actually changes (updated_option fires on
        // change, not on every load).
        $recheckCapability = static function (): void {
            self::recheckRewriteCapability();
        };
        // #43 Phase 4 (plan B.3 / D.4 #5): purge cache-plugin page
        // caches when delivery-relevant options change. Cached pages
        // have BAKED the old image URLs (clean vs ?k=); the purge
        // forces regeneration with the new URLs. Each purge is guarded
        // + try/caught inside CachePurger — never fatals.
        $purgeCaches = static function (): void {
            (new CachePurger())->purge();
        };

        add_action('updated_option', static function (string $option) use ($reinstall, $recheckCapability, $purgeCaches): void {
            if (in_array($option, OptionSettingsRepository::DELIVERY_OPTION_KEYS, true)) {
                $reinstall();
            }
            if ($option === OptionSettingsRepository::OPTION_ENDPOINT) {
                $recheckCapability();
            }
            // Purge page caches on delivery-relevant option changes
            // (the delivery keys + OPTION_REWRITE_CAPABILITY, whose flip
            // changes the URL format). Mirrors the capability-recheck
            // gating — no broad updated_option listener. Derived from the
            // single DELIVERY_OPTION_KEYS source so the two gates can't drift.
            $purgeWatched = array_merge(
                OptionSettingsRepository::DELIVERY_OPTION_KEYS,
                [OptionSettingsRepository::OPTION_REWRITE_CAPABILITY],
            );
            if (in_array($option, $purgeWatched, true)) {
                $purgeCaches();
            }
        });
    }
}
