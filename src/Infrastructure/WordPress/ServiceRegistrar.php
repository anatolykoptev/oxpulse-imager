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
use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Application\Diagnostics\DiagnosticLoggerInterface;
use OXPulse\Imager\Application\Health\HealthCheckService;
use OXPulse\Imager\Application\Prewarm\AsyncPrewarmService;
use OXPulse\Imager\Application\Prewarm\PrewarmJobStore;
use OXPulse\Imager\Application\Prewarm\PrewarmService;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Infrastructure\Http\WordPressHealthClient;
use OXPulse\Imager\Integration\WordPress\Admin\AdminBarDiagnostics;
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
use OXPulse\Imager\Integration\WordPress\Delivery\SrcsetRewriter;
use OXPulse\Imager\Integration\WordPress\Compatibility\RankMathCompatibility;
use OXPulse\Imager\Integration\WordPress\Performance\OptimizationDetectiveIntegration;
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
        self::registerTextDomain($plugin);
        self::registerHealthGate($plugin);
        self::registerAdminSettings($plugin);
        self::registerCli($plugin);
        self::registerPerformanceIntegration($plugin);
        self::registerAsyncPrewarmCron($plugin);
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

    private static function registerTextDomain(Plugin $plugin): void
    {
        add_action('init', static function () use ($plugin): void {
            load_plugin_textdomain(
                'oxpulse-imager',
                false,
                dirname($plugin->basename()) . '/languages'
            );
        });
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

        $logger = self::diagnosticLogger();
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing, $logger);

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

        $contentRewriter = new ContentImgTagRewriter($rewriter, $delivery, $lqipBuilder);
        $srcsetRewriter = new SrcsetRewriter($rewriter);
        $attachmentRewriter = new AttachmentImageSrcRewriter($rewriter);
        $attachmentUrlRewriter = new AttachmentUrlRewriter($rewriter);
        $avatarRewriter = new AvatarRewriter($rewriter);

        add_filter('wp_content_img_tag', [$contentRewriter, 'rewrite'], 10, 3);
        add_filter('wp_calculate_image_srcset', [$srcsetRewriter, 'rewrite'], 10, 5);
        add_filter('wp_get_attachment_image_src', [$attachmentRewriter, 'rewrite'], 10, 4);
        add_filter('wp_get_attachment_url', [$attachmentUrlRewriter, 'rewrite'], 10, 2);
        add_filter('get_avatar', [$avatarRewriter, 'rewrite'], 10, 5);

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

        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing);
        $syncService = new PrewarmService($rewriter, new \OXPulse\Imager\Infrastructure\Http\WordPressPrewarmClient());
        $asyncService = new AsyncPrewarmService($syncService, new PrewarmJobStore());
        $asyncService->registerCronHandler();
    }

    private static function deliveryEnabled(): bool
    {
        return (bool) get_option(OXPULSE_IMAGER_OPTION_PREFIX . 'enabled', false);
    }
}
