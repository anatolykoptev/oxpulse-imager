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

use OXPulse\Imager\Application\Delivery\LqipPlaceholderBuilder;
use OXPulse\Imager\Application\Delivery\PictureElementWrapper;
use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Application\Delivery\UrlRewriterFactory;
use OXPulse\Imager\Application\Diagnostics\DiagnosticLoggerInterface;
use OXPulse\Imager\Application\Health\HealthCheckService;
use OXPulse\Imager\Application\Prewarm\AsyncPrewarmService;
use OXPulse\Imager\Application\Prewarm\PrewarmJobStore;
use OXPulse\Imager\Application\Prewarm\PrewarmService;
use OXPulse\Imager\Infrastructure\Http\WordPressHealthClient;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackendProvider;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use OXPulse\Imager\Infrastructure\Imgproxy\SocialJpegCapabilityCache;
use OXPulse\Imager\Infrastructure\Imgproxy\SocialJpegCapabilityProbe;
use OXPulse\Imager\Infrastructure\Local\CapabilityTester;
use OXPulse\Imager\Infrastructure\Local\CacheJanitor;
use OXPulse\Imager\Infrastructure\Local\HttpRequester;
use OXPulse\Imager\Infrastructure\Local\LocalDeliveryInstaller;
use OXPulse\Imager\Infrastructure\License\FreemiusLicenseGate;
use OXPulse\Imager\Infrastructure\Local\WpRemoteHttpRequester;
use OXPulse\Imager\Domain\License\LicenseGate;
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

    /**
     * Shared LicenseGate instance. #89: the single seam through which
     * the plugin asks "is this a paying (Pro) customer?". Lazy-
     * initialized to the FreemiusLicenseGate (backed by the Freemius
     * SDK + grandfather flag). OpenLicenseGate remains available as
     * the inert everything-unlocked default for tests/QA; the swap
     * happens here only, so no feature code is aware of the provider.
     */
    private static ?LicenseGate $licenseGate = null;

    public static function register(Plugin $plugin): void
    {
        self::registerHealthGate($plugin);
        self::registerAdminSettings($plugin);
        self::registerCli($plugin);
        self::registerPerformanceIntegration($plugin);
        self::registerAsyncPrewarmCron($plugin);
        self::registerImgproxyHealthCron($plugin);
        self::registerCacheCleanupCron($plugin);
        self::registerLocalCacheInvalidation($plugin);
        self::registerLocalDeliverySettingsSync($plugin);
        self::registerImgproxyDeliveryGate();
        self::registerPictureGate();
        self::maybeReprobeOnVersionUpdate();
        self::maybeMigrateAutoload();
        self::maybeGrandfatherPreFreemiusInstalls();
        self::maybeRebakeAvifOnLicenseChange();
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
     * Get the shared LicenseGate instance (lazy-initialized).
     *
     * #89: the single seam through which any feature asks "is this a
     * Pro customer?". Defaults to FreemiusLicenseGate (backed by the
     * Freemius SDK + grandfather flag — reflects the real license
     * state while preserving the oxpulse_is_pro filter contract).
     * OpenLicenseGate remains available as the inert everything-unlocked
     * default for tests/QA. The swap is local to this accessor so no
     * feature code is aware of the provider. Exposed publicly via the
     * oxpulse_license_gate() global helper.
     */
    public static function licenseGate(): LicenseGate
    {
        if (self::$licenseGate === null) {
            self::$licenseGate = new FreemiusLicenseGate();
        }
        return self::$licenseGate;
    }

    /**
     * Centralized Pro-entitlement check — the single call every feature
     * gate consults at use-time. Delegates to licenseGate()->isPro() so
     * the provider (Freemius + grandfather + oxpulse_is_pro filter) is
     * resolved once and every gate stays consistent + testable via the
     * oxpulse_is_pro filter (add_filter('oxpulse_is_pro',
     * '__return_true'/'__return_false')).
     *
     * Read at the point of use (not cached at bootstrap) so a license
     * activation takes effect on the next isPro() call without a reload.
     */
    public static function isPro(): bool
    {
        return self::licenseGate()->isPro();
    }

    /**
     * Gate 2 (ProFeatures::IMGPROXY_DELIVERY): under free, the imgproxy
     * delivery backend is NOT selectable. Registered at bootstrap via
     * the documented oxpulse_delivery_backends extension point so the
     * strip applies to EVERY factory call site (frontend delivery,
     * prewarm cron, WP-CLI, REST) without scattering isPro() checks.
     *
     * When !isPro(), the callback removes every ImgproxyBackendProvider
     * from the provider list; the registry then falls through to
     * LocalBackend (WebP) or Passthrough (preserve original URL) —
     * delivery never breaks. When isPro(), the list passes through
     * unchanged (imgproxy selectable as today).
     */
    private static function registerImgproxyDeliveryGate(): void
    {
        add_filter('oxpulse_delivery_backends', static function (array $providers): array {
            if (self::isPro()) {
                return $providers;
            }
            return array_values(array_filter(
                $providers,
                static fn($p): bool => !$p instanceof ImgproxyBackendProvider,
            ));
        }, 10, 1);
    }

    /**
     * Gate 3 (ProFeatures::PICTURE_ELEMENT): under free, <picture>
     * wrapping is forced OFF regardless of the stored option or any
     * other oxpulse_picture_enabled filter callback — Pro is a
     * prerequisite; a free user who flips the option or adds
     * add_filter('oxpulse_picture_enabled','__return_true') still gets
     * no <picture>. Registered at PHP_INT_MAX priority so it runs LAST
     * in the apply_filters chain and wins over any earlier force-on.
     *
     * When isPro(), the filter passes the value through unchanged (the
     * option + oxpulse_picture_enabled filter control wrapping as today).
     * Free-fallback: no picture wrapping (unchanged default behavior).
     */
    private static function registerPictureGate(): void
    {
        add_filter('oxpulse_picture_enabled', static function ($enabled): bool {
            return self::isPro() ? (bool) $enabled : false;
        }, PHP_INT_MAX, 1);
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
        $rewriter = UrlRewriterFactory::fromConfig($delivery, $signing, $logger);

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
            // Phase 1b: pass the SAME $pictureWrapper instance constructed
            // above for ContentImgTagRewriter so theme-hardcoded <img> tags
            // caught by the buffer also get <picture> wrapping (default-off
            // via pictureEnabled + the oxpulse_picture_enabled filter).
            $bufferRewriter = new BufferRewriter($rewriter, $delivery, $pictureWrapper);
            $bufferRewriter->register();
        }

        // Ф3: RankMath og:image compatibility. Restores direct attachment
        // URLs in OpenGraph/Twitter meta tags so RankMath's filetype
        // validation doesn't drop them. When the restored direct URL is
        // NOT social-safe (.webp/.avif on webp-original installs), the
        // injected rewriter routes it through the active backend to an
        // explicit-jpeg, .jpg-terminated URL; the backend answers
        // honestly (null → degrade to the direct URL, never broken).
        // Default true — safe to register unconditionally (the filter is
        // a no-op when RankMath isn't active).
        if ($delivery->rankMathCompatibility) {
            $rankMath = new RankMathCompatibility($rewriter);
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
        // #92: inject the shared diagnostic logger so a blocked async
        // pre-warm (DISABLE_WP_CRON) is logged through the same channel
        // as rewrite decisions.
        $prewarmRest = new PrewarmRestController($repository, self::diagnosticLogger());
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
        $rewriter = UrlRewriterFactory::fromConfig($delivery, $signing);
        $syncService = new PrewarmService($rewriter, new \OXPulse\Imager\Infrastructure\Http\WordPressPrewarmClient());
        $asyncService = new AsyncPrewarmService($syncService, new PrewarmJobStore());
        $asyncService->registerCronHandler();
    }

    /**
     * #81: Register the periodic imgproxy health re-probe cron.
     *
     * WP-cron is traffic-triggered, so the persistent option
     * (ImgproxyHealthCache) is what GUARANTEES safety — a definitive
     * 'down' never self-expires to optimistic 'up'. The cron only
     * bounds recovery/re-detection latency: a recovered imgproxy is
     * re-promoted (down→up) and a newly-dead one is detected (up→down)
     * WITHOUT waiting for a settings-save.
     *
     * The callback delegates to recheckImgproxyHealth(), which is
     * self-guarding via isLocalBackendActive() — a no-op when no
     * imgproxy endpoint is configured. Registered at plugin bootstrap
     * (every load) so WP-cron can fire it; the EVENT is scheduled on
     * activation and cleared on deactivation.
     *
     * #84: register_activation_hook does NOT fire on a plugin UPDATE,
     * so an already-active install that upgrades in place (auto-update
     * / ZIP replace) never gets the recurring event scheduled → the
     * recovery cron is inert until a manual settings-save, a
     * version-gated reprobe, or a deactivate→reactivate. The init
     * guard-schedule below is the standard self-healing recurring-cron
     * idiom: any active install converges on its first request after
     * upgrade. The wp_next_scheduled guard makes this idempotent with
     * the activation-hook schedule (no double-schedule, no timestamp
     * shift) and re-heals a vanished cron.
     */
    private static function registerImgproxyHealthCron(Plugin $plugin): void
    {
        add_action('oxpulse_imgproxy_health_recheck', static function (): void {
            self::recheckImgproxyHealth();
            self::recheckSocialJpegCapability();
        });

        add_action('init', static function (): void {
            if (!wp_next_scheduled('oxpulse_imgproxy_health_recheck')) {
                wp_schedule_event(time(), 'hourly', 'oxpulse_imgproxy_health_recheck');
            }
        });
    }

    /**
     * #93: Register the periodic LocalBackend cache LRU eviction cron.
     *
     * The LocalBackend on-disk cache grows unbounded (one file per
     * transform variant); this recurring cron bounds it by evicting
     * least-recently-used files when the total exceeds the cache_max_mb
     * cap. Wired EXACTLY like the #81 imgproxy-health cron:
     *   - add_action at bootstrap so WP-cron can fire the callback,
     *   - activation wp_schedule_event (guarded) in oxpulse-imager.php,
     *   - init self-heal guard-schedule so an in-place upgrade converges
     *     without a deactivate→reactivate (#84 idiom),
     *   - deactivation wp_clear_scheduled_hook in oxpulse-imager.php.
     *
     * The cleanup is a no-op when LocalBackend isn't the active tier
     * (imgproxy sites have no local cache) — guarded inside
     * runCacheCleanup() via isLocalBackendActive(), mirroring how
     * recheckImgproxyHealth() is self-guarding.
     *
     * Recurrence is 'twicedaily' (the cache fills gradually; hourly
     * would over-sweep, and eviction is bounded per run anyway).
     */
    private static function registerCacheCleanupCron(Plugin $plugin): void
    {
        add_action('oxpulse_cache_cleanup', static function (): void {
            self::runCacheCleanup();
        });

        add_action('init', static function (): void {
            if (!wp_next_scheduled('oxpulse_cache_cleanup')) {
                wp_schedule_event(time(), 'twicedaily', 'oxpulse_cache_cleanup');
            }
        });
    }

    /**
     * #93: Run one LocalBackend cache LRU eviction pass.
     *
     * Self-guarding via isLocalBackendActive(): a no-op when
     * ImgproxyBackend is active (endpoint configured — imgproxy manages
     * its own cache, the local cache dir is not used). When LocalBackend
     * is active, resolves the cache dir + the cache_max_mb cap (option +
     * oxpulse_cache_max_mb filter) and delegates to CacheJanitor::run().
     *
     * Test seam: accepts an injected CacheJanitor so tests can point at
     * a temp cache dir. Production callers pass null → a real janitor is
     * built against the resolved cache dir.
     */
    public static function runCacheCleanup(?CacheJanitor $janitor = null): void
    {
        $repository = new OptionSettingsRepository();
        $delivery = $repository->loadDeliveryConfig();

        // No-op when ImgproxyBackend is active — imgproxy sites have no
        // local cache to bound. Mirrors the isLocalBackendActive() guard
        // in registerLocalCacheInvalidation() + recheckImgproxyHealth().
        if (!$delivery->isLocalBackendActive()) {
            return;
        }

        if ($janitor === null) {
            $cacheDir = self::resolveLocalCacheDir();
            if ($cacheDir === null) {
                return;
            }
            $janitor = new CacheJanitor($cacheDir);
        }

        $janitor->run($repository->loadCacheMaxMb());

        // Marker action for test assertions + observability (mirrors
        // oxpulse_recheck_imgproxy_health).
        do_action('oxpulse_cache_cleanup_ran');
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

        // #87: LocalBackend is not supported on WordPress Multisite — one
        // shared oxpulse-img.php baked with a single blog's per-site values
        // breaks every other blog. Clear any stale endpoint + cache
        // .htaccess left from a pre-multisite conversion, then return
        // without generating. This also makes the settings-save re-install
        // hook (registerLocalDeliverySettingsSync) a no-op on multisite.
        if (function_exists('is_multisite') && is_multisite()) {
            $installer->uninstall();
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
     * Write-time imgproxy health probe trigger — the COMPLEMENT of
     * recheckRewriteCapability(). Runs the live ImgproxyBackendProvider
     * health probe (a bounded 2s HEAD, redirection=0) ONLY in
     * admin/activation context — never from the front-end read path
     * (health() reads the cache only).
     *
     * Fires when ImgproxyBackend is active (endpoint non-empty). Called
     * from:
     *   - plugin activation (oxpulse_imager_activate()),
     *   - settings-save when OPTION_ENDPOINT becomes non-empty (the
     *     updated_option hook in registerLocalDeliverySettingsSync()),
     *   - once-per-version re-probe (maybeReprobeOnVersionUpdate()).
     *
     * The probe and the rewrite-capability probe are complementary:
     * exactly one fires on a given endpoint change (imgproxy active =
     * endpoint set → imgproxy probe; local active = endpoint empty →
     * rewrite-capability probe).
     *
     * Test seam: accepts injected HttpRequester + ImgproxyHealthCache
     * so tests can verify the trigger without a real HTTP round-trip.
     * Production callers pass null → real deps are built.
     */
    public static function recheckImgproxyHealth(
        ?HttpRequester $requester = null,
        ?ImgproxyHealthCache $cache = null,
    ): void {
        $repository = new OptionSettingsRepository();
        $delivery = $repository->loadDeliveryConfig();

        // Only probe when ImgproxyBackend is active (endpoint non-empty).
        // The inverse guard of recheckRewriteCapability() — exactly one
        // of the two probes fires for a given endpoint state.
        if ($delivery->isLocalBackendActive()) {
            return;
        }

        // Resolve relative endpoint to absolute (same as the frontend
        // delivery path) so the probe hits the real URL, not '/imgproxy'.
        $delivery = $delivery->withEndpoint(
            OptionSettingsRepository::resolveEndpoint($delivery->endpoint)
        );

        $requester = $requester ?? new WpRemoteHttpRequester();
        $cache = $cache ?? new ImgproxyHealthCache();
        $provider = new ImgproxyBackendProvider($requester, $cache);
        $provider->recheck($delivery);

        // Marker action for test assertions (mirrors
        // oxpulse_recheck_rewrite_capability).
        do_action('oxpulse_recheck_imgproxy_health');
    }

    /**
     * Write-time social-jpeg capability probe trigger.
     *
     * Mirrors recheckImgproxyHealth(): self-guarding via
     * isLocalBackendActive() (no-op when LocalBackend is active —
     * endpoint empty). Resolves a relative endpoint to absolute (same
     * as the frontend delivery path + recheckImgproxyHealth). Constructs
     * a SocialJpegCapabilityProbe with the endpoint-resolved
     * DeliveryConfig + signing config + injected requester/cache/
     * sourceProvider, and calls run() which writes 'ok'/'no' to the
     * SocialJpegCapabilityCache.
     *
     * The probe issues a single getImage() to the EXACT production .jpg
     * URL — bounded 5s timeout, redirection = 0, sslverify = true
     * (enforced by the HttpRequester impl). NEVER called on the
     * front-end render path — wired to the same triggers as
     * recheckImgproxyHealth (activation, settings-save, hourly cron,
     * version-gated re-probe).
     *
     * Test seam: accepts injected HttpRequester + SocialJpegCapabilityCache
     * + callable sourceProvider so tests can verify the trigger without
     * a real HTTP round-trip. Production callers pass null → real deps
     * are built. The default sourceProvider queries the newest image
     * attachment via get_posts + AttachmentOriginResolver (bypasses the
     * rewrite filter chain).
     */
    public static function recheckSocialJpegCapability(
        ?HttpRequester $requester = null,
        ?SocialJpegCapabilityCache $cache = null,
        ?callable $sourceProvider = null,
    ): void {
        $repository = new OptionSettingsRepository();
        $delivery = $repository->loadDeliveryConfig();

        // Only probe when ImgproxyBackend is active (endpoint non-empty).
        // Self-guarding — mirrors recheckImgproxyHealth().
        if ($delivery->isLocalBackendActive()) {
            return;
        }

        // Resolve relative endpoint to absolute (same as the frontend
        // delivery path + recheckImgproxyHealth).
        $delivery = $delivery->withEndpoint(
            OptionSettingsRepository::resolveEndpoint($delivery->endpoint)
        );

        // Signing config is required to generate the .jpg probe URL.
        // Without it, imgproxy URLs can't be signed → stay conservative
        // (cache stays false → degrade to webp).
        $signing = $repository->loadSigningConfig();
        if ($signing === null) {
            return;
        }

        $requester = $requester ?? new WpRemoteHttpRequester();
        $cache = $cache ?? new SocialJpegCapabilityCache();

        // Default sourceProvider: newest image attachment, bypassing
        // the rewrite filter chain via AttachmentOriginResolver.
        if ($sourceProvider === null) {
            $sourceProvider = static function (): ?string {
                $posts = get_posts([
                    'post_type' => 'attachment',
                    'post_mime_type' => 'image',
                    'posts_per_page' => 1,
                    'orderby' => 'date',
                    'order' => 'DESC',
                ]);
                if (empty($posts)) {
                    return null;
                }
                return \OXPulse\Imager\Integration\WordPress\Delivery\AttachmentOriginResolver::resolveOriginalUrl((int) $posts[0]->ID);
            };
        }

        $probe = new SocialJpegCapabilityProbe($delivery, $signing, $requester, $cache, $sourceProvider);
        $probe->run();

        // Marker action for test assertions + observability.
        do_action('oxpulse_recheck_social_jpeg');
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
        // at most once per version. Both probes are called — each is
        // self-guarding: recheckRewriteCapability() is a no-op when
        // imgproxy is active, recheckImgproxyHealth() is a no-op when
        // LocalBackend is active. Exactly one fires for the current
        // endpoint state.
        self::recheckRewriteCapability();
        self::recheckImgproxyHealth();
        self::recheckSocialJpegCapability();
        $repository->saveProbeVersion(OXPULSE_IMAGER_VERSION);
    }

    /**
     * #91: one-time, forward-only upgrade that flips the hot
     * render-path options to autoload=yes on EXISTING installs.
     *
     * Fresh installs get autoload=yes directly in the activation hook.
     * But installs activated before #91 stored enabled / endpoint /
     * allowed_sources / diagnostic_level with autoload=no (the old
     * activation default), so each of those options required a
     * separate SELECT on every request on sites without a persistent
     * object cache. wp_set_options_autoload (WP 6.4+; plugin requires
     * 6.7) flips the autoload flag in a single UPDATE without touching
     * the stored values — no data migration, no dual source of truth.
     *
     * Gated on OPTION_SCHEMA_VERSION < 2 so it runs at most once per
     * install, then stamps schema_version=2. Admin-only (the flip is
     * a one-time housekeeping write, never on the front-end read path).
     * Idempotent: a re-run after schema_version=2 is a no-op.
     */
    private static function maybeMigrateAutoload(): void
    {
        if (!is_admin()) {
            return;
        }
        if (!function_exists('wp_set_options_autoload')) {
            return;
        }

        $repository = new OptionSettingsRepository();
        $schemaVersion = (int) get_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION, 1);
        if ($schemaVersion >= 2) {
            return;
        }

        wp_set_options_autoload(OptionSettingsRepository::AUTOLOAD_OPTION_KEYS, true);
        update_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION, 2);
    }

    /**
     * Grandfather pre-Freemius installs on upgrade.
     *
     * register_activation_hook does NOT fire on plugin UPDATE, so this
     * detector runs on every admin load (idempotent — returns early
     * once the grandfathered flag is set or the born_version sentinel
     * is present). An install that existed BEFORE Freemius (has prior-
     * install markers: schema_version/onboarded present) AND lacks the
     * oxpulse_born_version sentinel (set only on fresh activation on
     * the Freemius version) gets oxpulse_grandfathered=1 so no
     * previously-working feature is lost once Phase-B gating lands.
     *
     * A FRESH install on this version has born_version set by the
     * activation hook → not grandfathered. The flag is stored
     * autoload=no (not on the hot render path). Admin-only (one-time
     * housekeeping write, never on the front-end read path). Mirrors
     * the maybeMigrateAutoload / maybeReprobeOnVersionUpdate pattern.
     */
    private static function maybeGrandfatherPreFreemiusInstalls(): void
    {
        if (!is_admin()) {
            return;
        }

        // Already grandfathered → idempotent no-op.
        if (get_option('oxpulse_grandfathered', null) !== null) {
            return;
        }

        // Fresh install on the Freemius version: the activation hook
        // set born_version. This install was never pre-Freemius.
        if (get_option('oxpulse_born_version', null) !== null) {
            return;
        }

        // Prior-install markers: an install activated on a pre-Freemius
        // version has schema_version and/or onboarded in the DB (set by
        // the old activation hook). If neither exists, this is not an
        // upgrade from a pre-Freemius version — don't grandfather.
        $hasPriorMarkers = get_option(OptionSettingsRepository::OPTION_SCHEMA_VERSION, null) !== null
            || get_option(OptionSettingsRepository::OPTION_ONBOARDED, null) !== null;
        if (!$hasPriorMarkers) {
            return;
        }

        update_option('oxpulse_grandfathered', 1, false);
    }

    /**
     * Self-heal the baked OXPULSE_AVIF_ALLOWED constant on a Freemius
     * license change (FIX 2 MAJOR).
     *
     * The baked constant only regenerates on activation / settings-save
     * / version bump — NOT on a license change. So Pro→free keeps
     * serving AVIF (leak) and free→Pro withholds AVIF (lag) until the
     * next settings save. This guard closes that gap: on an idempotent
     * admin-time check, compare current isPro() to a stored
     * oxpulse_avif_baked_pro option; if they differ AND the site is a
     * LocalBackend site (endpoint===''), call installLocalDelivery()
     * to regenerate the endpoint with the correct value, then update
     * the stored option. For an imgproxy site (no local endpoint) the
     * regeneration is skipped (no baked endpoint exists) but the
     * stored flag is still updated so a later switch-to-local bakes
     * the correct value.
     *
     * Idempotent: when stored === isPro() it's a no-op. Admin-only
     * (write-time housekeeping, never on the front-end read path).
     * Mirrors the maybeGrandfatherPreFreemiusInstalls /
     * maybeReprobeOnVersionUpdate idiom.
     */
    private static function maybeRebakeAvifOnLicenseChange(): void
    {
        if (!is_admin()) {
            return;
        }

        $currentPro = self::isPro();
        $bakedPro = get_option('oxpulse_avif_baked_pro', null);

        // First run on a fresh install (no stored flag yet): seed it
        // from the current isPro() state without regenerating. The
        // activation hook already baked the endpoint with the correct
        // value, so there's nothing to drift from.
        if ($bakedPro === null) {
            update_option('oxpulse_avif_baked_pro', $currentPro, false);
            return;
        }

        $bakedProBool = (bool) $bakedPro;
        if ($bakedProBool === $currentPro) {
            return; // No drift — idempotent no-op.
        }

        // Drift detected. For a LocalBackend site (endpoint===''),
        // regenerate the endpoint so the baked constant reflects the
        // new license state. For an imgproxy site there's no baked
        // endpoint to regenerate — skip the install call. The stored
        // flag is updated in both cases so a later switch-to-local
        // bakes the correct value.
        $endpoint = get_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        if ($endpoint === '') {
            self::installLocalDelivery();
            do_action('oxpulse_rebaked_avif');
        }

        update_option('oxpulse_avif_baked_pro', $currentPro, false);
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
        // Delivery backend registry: re-probe imgproxy health when
        // ImgproxyBackend becomes active (endpoint set). The COMPLEMENT
        // of $recheckCapability — exactly one fires for a given endpoint
        // state (imgproxy active = endpoint set → imgproxy probe; local
        // active = endpoint empty → rewrite-capability probe). Both are
        // self-guarding via isLocalBackendActive().
        $recheckImgproxy = static function (): void {
            self::recheckImgproxyHealth();
        };
        // Social-jpeg capability probe: same trigger as imgproxy health.
        // Self-guarding via isLocalBackendActive() — fires only when
        // imgproxy is active (endpoint set).
        $recheckSocialJpeg = static function (): void {
            self::recheckSocialJpegCapability();
        };
        // #43 Phase 4 (plan B.3 / D.4 #5): purge cache-plugin page
        // caches when delivery-relevant options change. Cached pages
        // have BAKED the old image URLs (clean vs ?k=); the purge
        // forces regeneration with the new URLs. Each purge is guarded
        // + try/caught inside CachePurger — never fatals.
        $purgeCaches = static function (): void {
            (new CachePurger())->purge();
        };

        add_action('updated_option', static function (string $option) use ($reinstall, $recheckCapability, $recheckImgproxy, $recheckSocialJpeg, $purgeCaches): void {
            if (in_array($option, OptionSettingsRepository::DELIVERY_OPTION_KEYS, true)) {
                $reinstall();
            }
            if ($option === OptionSettingsRepository::OPTION_ENDPOINT) {
                // Both probes are self-guarding — exactly one fires
                // for the new endpoint state (endpoint set → imgproxy
                // probe; endpoint empty → rewrite-capability probe).
                $recheckCapability();
                $recheckImgproxy();
                $recheckSocialJpeg();
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
