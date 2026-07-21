<?php
/**
 * Optimization Detective integration.
 *
 * Registers a tag visitor with the Optimization Detective framework
 * to add performance optimizations for imgproxy-rewritten images:
 *
 * 1. Always: adds a <link rel="preconnect"> to the imgproxy endpoint
 *    via OD's link collection (or wp_head if OD is not active). This
 *    is the imgproxy-specific value — Image Prioritizer doesn't know
 *    your image CDN domain.
 *
 * 2. If Image Prioritizer is NOT active: registers a minimal tag
 *    visitor that tracks IMG tags with imgproxy URLs and adds preload
 *    links for LCP elements. When Image Prioritizer IS active, it
 *    already handles IMG preloading — our visitor would duplicate
 *    that work, so we skip it.
 *
 * @package OXPulse\Imager\Integration\WordPress\Performance
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 * @see https://github.com/WordPress/performance/tree/trunk/plugins/optimization-detective
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Performance;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;

final class OptimizationDetectiveIntegration
{
    private OptionSettingsRepository $repository;

    public function __construct(?OptionSettingsRepository $repository = null)
    {
        $this->repository = $repository ?? new OptionSettingsRepository();
    }

    public function register(): void
    {
        // Always: add preconnect to imgproxy endpoint via wp_head.
        // This works with or without Optimization Detective.
        add_action('wp_head', [$this, 'addPreconnect'], 1);

        // If Optimization Detective is active, register our tag visitor.
        if (class_exists('\OD_Tag_Visitor_Registry') && did_action('od_register_tag_visitors')) {
            // Late registration — OD already fired. Register directly.
            $this->registerTagVisitor(new \OD_Tag_Visitor_Registry());
        } else {
            add_action('od_register_tag_visitors', [$this, 'registerTagVisitor']);
        }
    }

    /**
     * Add <link rel="preconnect"> to the imgproxy endpoint in <head>.
     * Fires on every frontend page load. Only outputs if delivery is
     * enabled and the endpoint is configured.
     */
    public function addPreconnect(): void
    {
        $delivery = $this->repository->loadDeliveryConfig();
        if (!$delivery->enabled || $delivery->endpoint === '') {
            return;
        }

        $host = wp_parse_url($delivery->endpoint, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return;
        }

        $protocol = wp_parse_url($delivery->endpoint, PHP_URL_SCHEME) ?: 'https';
        $href = $protocol . '://' . $host;

        // crossorigin="anonymous" because imgproxy responses don't need
        // credentials, and anonymous CORS allows the connection to be
        // shared with subsequent image fetches.
        printf(
            '<link rel="preconnect" href="%s" crossorigin="anonymous" />' . "\n",
            esc_url($href)
        );
    }

    /**
     * Register our tag visitor with OD's tag visitor registry.
     *
     * Only registers the IMG-tracking visitor if Image Prioritizer is
     * NOT active — Image Prioritizer already handles IMG preload links
     * and fetchpriority, and running both would duplicate preload links
     * (which is worse than none — double fetches).
     *
     * @param \OD_Tag_Visitor_Registry $registry
     */
    public function registerTagVisitor($registry): void
    {
        // If Image Prioritizer is active, let it handle IMG tags.
        if (class_exists('\Image_Prioritizer_Img_Tag_Visitor')) {
            return;
        }

        $registry->register('oxpulse-imgproxy-img', $this);
    }

    /**
     * Tag visitor callback — invoked for every open tag in the document.
     *
     * Only processes IMG tags whose src is an imgproxy URL. Adds a
     * preload link if the IMG is the LCP element for any viewport group.
     *
     * @param \OD_Tag_Visitor_Context $context
     * @return bool Whether the tag should be tracked in URL Metrics.
     */
    public function __invoke($context): bool
    {
        $processor = $context->processor;
        if ($processor->get_tag() !== 'IMG') {
            return false;
        }

        $src = $processor->get_attribute('src');
        if (!is_string($src) || $src === '') {
            return false;
        }

        // Only track IMG tags whose src is an imgproxy URL (i.e. was
        // rewritten by OXPulse). This avoids tracking images we don't
        // manage.
        $delivery = $this->repository->loadDeliveryConfig();
        if (!$delivery->enabled || $delivery->endpoint === '') {
            return false;
        }

        if (!$this->isImgproxyUrl($src, $delivery->endpoint)) {
            return false;
        }

        $xpath = $processor->get_xpath();

        // Add preload link if this IMG is the LCP element for any
        // viewport group.
        if (method_exists($context->url_metric_group_collection, 'get_lcp_element')) {
            $this->addPreloadLinkForLcp($context, $xpath, $processor);
        }

        return true;
    }

    /**
     * Add a preload link for the LCP element if this IMG is it.
     *
     * @param \OD_Tag_Visitor_Context $context
     * @param string $xpath
     * @param \OD_HTML_Tag_Processor $processor
     */
    private function addPreloadLinkForLcp($context, string $xpath, $processor): void
    {
        $collection = $context->url_metric_group_collection;
        $linkCollection = $context->link_collection ?? null;
        if ($linkCollection === null || !method_exists($linkCollection, 'add_link')) {
            return;
        }

        // Iterate viewport groups and add preload links for groups
        // where this IMG is the LCP element.
        foreach ($collection->get_groups() as $group) {
            $lcpElement = $group->get_lcp_element();
            if ($lcpElement === null) {
                continue;
            }
            if ($lcpElement->get_xpath() !== $xpath) {
                continue;
            }

            // This IMG is the LCP element for this viewport group.
            // Add a preload link scoped to the group's media query.
            $minViewport = $group->get_minimum_viewport_width();
            $maxViewport = $group->get_maximum_viewport_width();

            $media = $this->buildMediaQuery($minViewport, $maxViewport);

            $attributes = [
                'rel'         => 'preload',
                'as'          => 'image',
                'media'       => $media,
                'href'        => $processor->get_attribute('src'),
                'imagesrcset' => $processor->get_attribute('srcset'),
                'imagesizes'  => $processor->get_attribute('sizes'),
            ];

            // Remove falsy attributes.
            $attributes = array_filter($attributes, fn ($v) => is_string($v) && $v !== '');

            $linkCollection->add_link($attributes);
        }
    }

    /**
     * Build a CSS media query for a viewport width range.
     *
     * @param int $min Minimum viewport width (px).
     * @param int $max Maximum viewport width (px), 0 = no upper bound.
     * @return string CSS media query, e.g. "(min-width: 481px) and (max-width: 960px)".
     */
    private function buildMediaQuery(int $min, int $max): string
    {
        $parts = [];
        if ($min > 0) {
            $parts[] = sprintf('(min-width: %dpx)', $min);
        }
        if ($max > 0) {
            $parts[] = sprintf('(max-width: %dpx)', $max);
        }
        return count($parts) > 0 ? implode(' and ', $parts) : 'all';
    }

    /**
     * Check if a URL is an imgproxy URL (starts with the configured endpoint).
     */
    private function isImgproxyUrl(string $url, string $endpoint): bool
    {
        $endpoint = rtrim($endpoint, '/') . '/';
        return str_starts_with($url, $endpoint);
    }
}
