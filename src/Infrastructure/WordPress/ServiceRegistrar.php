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
use OXPulse\Imager\Application\Health\HealthCheckService;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Infrastructure\Http\WordPressHealthClient;
use OXPulse\Imager\Integration\WordPress\Admin\OptionsRestController;
use OXPulse\Imager\Integration\WordPress\Admin\SettingsController;
use OXPulse\Imager\Integration\WordPress\Admin\SettingsPage;
use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentImageSrcRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\AttachmentUrlRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\AvatarRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\ContentImgTagRewriter;
use OXPulse\Imager\Integration\WordPress\Delivery\SrcsetRewriter;
use OXPulse\Imager\Plugin;

final class ServiceRegistrar
{
    public static function register(Plugin $plugin): void
    {
        self::registerTextDomain($plugin);
        self::registerHealthGate($plugin);
        self::registerAdminSettings($plugin);
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

        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing);

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
    }

    /**
     * Register the admin settings page (React SPA shell) + REST
     * controller backing it. Only wired when is_admin() is true so
     * the frontend never loads admin dependencies.
     *
     * The REST controller is registered on every admin request (it
     * hooks rest_api_init which only fires on REST requests anyway),
     * so the SPA can talk to it regardless of which admin page is
     * currently displayed.
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

        // REST controller backing the SPA — GET|POST /oxpulse/v1/options.
        $restController = new OptionsRestController($repository, $validator);
        $restController->register();

        // Legacy admin-post handlers for health check + AVIF test
        // (still used until Phase 5.3 moves them to REST endpoints).
        $healthClient = new WordPressHealthClient();
        $healthCheck = new HealthCheckService($healthClient);
        $controller = new SettingsController($repository, $validator, $healthCheck);
        $controller->register();
    }

    private static function deliveryEnabled(): bool
    {
        return (bool) get_option(OXPULSE_IMAGER_OPTION_PREFIX . 'enabled', false);
    }
}
