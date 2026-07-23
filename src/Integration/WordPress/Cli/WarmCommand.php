<?php
/**
 * WP-CLI `wp oxpulse warm` command.
 *
 * Pre-warms imgproxy cache for a single attachment (--attachment=<id>),
 * all attachments (--all), or a list of URLs passed as positional args.
 *
 * @package OXPulse\Imager\Integration\WordPress\Cli
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Cli;

use OXPulse\Imager\Application\Delivery\UrlRewriterFactory;
use OXPulse\Imager\Application\Prewarm\PrewarmService;
use OXPulse\Imager\Domain\Prewarm\PrewarmRequest;
use OXPulse\Imager\Infrastructure\Http\WordPressPrewarmClient;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;

final class WarmCommand extends AbstractCommand
{
    /**
     * Pre-warm imgproxy cache.
     *
     * ## OPTIONS
     *
     * [<urls>...]
     * : One or more source image URLs to warm.
     *
     * [--attachment=<id>]
     * : Warm the original + all registered sizes for a single attachment ID.
     *
     * [--all]
     * : Warm all attachments in the media library. Use with caution on
     * large libraries — batches of 50 URLs at a time.
     *
     * [--widths=<w1,w2,...>]
     * : Comma-separated target widths in px. Default: 0 (no resize).
     *
     * ## EXAMPLES
     *
     *     wp oxpulse warm https://example.com/wp-content/uploads/photo.jpg
     *     wp oxpulse warm --attachment=42
     *     wp oxpulse warm --all --widths=400,800,1200
     *
     * @param array $args       Positional args: source URLs.
     * @param array $assoc_args Associative args.
     */
    public function warm(array $args, array $assoc_args): void
    {
        $delivery = $this->repository->loadDeliveryConfig();
        $signing = $this->repository->loadSigningConfig();

        if (!$delivery->enabled) {
            $this->error(__('Delivery is disabled. Enable it in Settings > OXPulse Imager first.', 'oxpulse-imager'));
        }
        if ($delivery->endpoint === '') {
            $this->error(__('No imgproxy endpoint configured.', 'oxpulse-imager'));
        }
        if ($signing === null) {
            $this->error(__('No signing secrets configured.', 'oxpulse-imager'));
        }

        // #82: resolve relative endpoint to absolute + route through the
        // health-gated factory so a cached-Down imgproxy falls through
        // to LocalBackend / passthrough — Warm does not pre-warm a dead
        // endpoint. Mirrors the front-end render path (ServiceRegistrar).
        $delivery = $delivery->withEndpoint(
            OptionSettingsRepository::resolveEndpoint($delivery->endpoint)
        );

        $widths = $this->parseWidths($assoc_args['widths'] ?? '');
        $urls = $this->resolveUrls($args, $assoc_args);

        if (count($urls) === 0) {
            $this->error(__('No URLs to warm. Provide URLs as args, or use --attachment=<id> / --all.', 'oxpulse-imager'));
        }

        $this->log(sprintf(
            /* translators: 1: number of URLs to warm, 2: comma-separated target widths */
            __('Warming %1$d URL(s) at widths: %2$s', 'oxpulse-imager'),
            count($urls),
            implode(',', $widths)
        ));
        $this->log(sprintf(
            /* translators: %s: imgproxy endpoint URL. */
            __('Endpoint: %s', 'oxpulse-imager'),
            $delivery->endpoint
        ));
        $this->log('');

        // Process in batches of 50 (PrewarmRequest::MAX_URLS_PER_BATCH).
        $batches = array_chunk($urls, PrewarmRequest::MAX_URLS_PER_BATCH);
        $totals = ['warmed' => 0, 'skipped' => 0, 'failed' => 0, 'total' => 0];

        foreach ($batches as $batchIdx => $batch) {
            if (count($batches) > 1) {
                $this->log(sprintf(
                    /* translators: 1: current batch, 2: total batches, 3: URLs in this batch */
                    __('Batch %1$d/%2$d (%3$d URLs)...', 'oxpulse-imager'),
                    $batchIdx + 1,
                    count($batches),
                    count($batch)
                ));
            }

            $rewriter = UrlRewriterFactory::fromConfig($delivery, $signing);
            $service = new PrewarmService($rewriter, new WordPressPrewarmClient());
            $result = $service->warm(new PrewarmRequest($batch, $widths));

            foreach ($result->items as $item) {
                $status = $item->status;
                $url = $item->sourceUrl;
                $msg = $item->message;
                $this->log(sprintf(
                    /* translators: 1: status label, 2: source URL, 3: detail message */
                    __('  [%1$s] %2$s — %3$s', 'oxpulse-imager'),
                    $status,
                    $url,
                    $msg
                ));
            }

            $totals['warmed'] += $result->warmedCount();
            $totals['skipped'] += $result->skippedCount();
            $totals['failed'] += $result->failedCount();
            $totals['total'] += $result->total();
        }

        $this->log('');
        $this->log(sprintf(
            /* translators: 1: total, 2: warmed, 3: skipped, 4: failed */
            __('Done. Total: %1$d, Warmed: %2$d, Skipped: %3$d, Failed: %4$d', 'oxpulse-imager'),
            $totals['total'],
            $totals['warmed'],
            $totals['skipped'],
            $totals['failed']
        ));

        if ($totals['failed'] > 0) {
            $this->warning(sprintf(
                /* translators: %d: number of URLs that failed to warm. */
                __('%d URL(s) failed to warm.', 'oxpulse-imager'),
                $totals['failed']
            ));
        } else {
            $this->success(sprintf(
                /* translators: %d: number of URLs successfully warmed. */
                __('%d URL(s) warmed.', 'oxpulse-imager'),
                $totals['warmed']
            ));
        }
    }

    /**
     * Parse comma-separated widths into a sorted, deduped int array.
     *
     * @return array<int,int>
     */
    private function parseWidths(string $widthsArg): array
    {
        if ($widthsArg === '') {
            return PrewarmRequest::DEFAULT_WIDTHS;
        }
        $widths = array_filter(
            array_map(fn ($w) => (int) trim($w), explode(',', $widthsArg)),
            fn ($w) => $w >= 0 && $w <= 10000
        );
        $widths = array_values(array_unique($widths));
        sort($widths);
        return count($widths) > 0 ? $widths : PrewarmRequest::DEFAULT_WIDTHS;
    }

    /**
     * Resolve URLs from positional args, --attachment, or --all.
     *
     * @param array $args       Positional args.
     * @param array $assoc_args Associative args.
     * @return array<int,string>
     */
    private function resolveUrls(array $args, array $assoc_args): array
    {
        // Explicit URLs win.
        if (count($args) > 0) {
            return array_values(array_filter(
                array_map(fn ($u) => is_string($u) ? trim($u) : '', $args),
                fn ($u) => $u !== ''
            ));
        }

        // Single attachment.
        if (isset($assoc_args['attachment'])) {
            $id = (int) $assoc_args['attachment'];
            return $this->urlsForAttachment($id);
        }

        // All attachments.
        if (isset($assoc_args['all'])) {
            return $this->urlsForAllAttachments();
        }

        return [];
    }

    /**
     * Get the original + all registered size URLs for an attachment.
     *
     * @return array<int,string>
     */
    private function urlsForAttachment(int $id): array
    {
        if (!function_exists('wp_get_attachment_url') || !function_exists('wp_get_attachment_metadata')) {
            $this->error(__('WordPress functions not available (wp_get_attachment_url).', 'oxpulse-imager'));
        }

        $urls = [];
        $original = (string) wp_get_attachment_url($id);
        if ($original !== '') {
            $urls[] = $original;
        }

        $meta = wp_get_attachment_metadata($id);
        if (is_array($meta) && isset($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $sizeName => $sizeData) {
                if (!isset($sizeData['file'])) {
                    continue;
                }
                // Build the URL by replacing the original filename with the size's file.
                $dir = dirname($original);
                $urls[] = $dir . '/' . $sizeData['file'];
            }
        }

        return $urls;
    }

    /**
     * Get all attachment URLs in the media library.
     *
     * @return array<int,string>
     */
    private function urlsForAllAttachments(): array
    {
        if (!function_exists('get_posts')) {
            $this->error(__('WordPress functions not available (get_posts).', 'oxpulse-imager'));
        }

        $this->log(__('Enumerating media library...', 'oxpulse-imager'));

        $attachments = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ]);

        if (!is_array($attachments) || count($attachments) === 0) {
            return [];
        }

        $this->log(sprintf(
            /* translators: %d: number of attachments found in the media library. */
            __('Found %d attachment(s).', 'oxpulse-imager'),
            count($attachments)
        ));

        $urls = [];
        foreach ($attachments as $id) {
            foreach ($this->urlsForAttachment((int) $id) as $url) {
                $urls[] = $url;
            }
        }
        return $urls;
    }
}
