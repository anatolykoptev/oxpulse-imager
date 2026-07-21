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

use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Application\Prewarm\PrewarmService;
use OXPulse\Imager\Domain\Prewarm\PrewarmRequest;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use OXPulse\Imager\Infrastructure\Http\WordPressPrewarmClient;

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
            $this->error('Delivery is disabled. Enable it in Settings > OXPulse Imager first.');
        }
        if ($delivery->endpoint === '') {
            $this->error('No imgproxy endpoint configured.');
        }
        if ($signing === null) {
            $this->error('No signing secrets configured.');
        }

        $widths = $this->parseWidths($assoc_args['widths'] ?? '');
        $urls = $this->resolveUrls($args, $assoc_args);

        if (count($urls) === 0) {
            $this->error('No URLs to warm. Provide URLs as args, or use --attachment=<id> / --all.');
        }

        $this->log('Warming ' . count($urls) . ' URL(s) at widths: ' . implode(',', $widths));
        $this->log('Endpoint: ' . $delivery->endpoint);
        $this->log('');

        // Process in batches of 50 (PrewarmRequest::MAX_URLS_PER_BATCH).
        $batches = array_chunk($urls, PrewarmRequest::MAX_URLS_PER_BATCH);
        $totals = ['warmed' => 0, 'skipped' => 0, 'failed' => 0, 'total' => 0];

        foreach ($batches as $batchIdx => $batch) {
            if (count($batches) > 1) {
                $this->log(sprintf(
                    'Batch %d/%d (%d URLs)...',
                    $batchIdx + 1,
                    count($batches),
                    count($batch)
                ));
            }

            $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing);
            $service = new PrewarmService($rewriter, new WordPressPrewarmClient());
            $result = $service->warm(new PrewarmRequest($batch, $widths));

            foreach ($result->items as $item) {
                $status = $item->status;
                $url = $item->sourceUrl;
                $msg = $item->message;
                $this->log(sprintf('  [%s] %s — %s', $status, $url, $msg));
            }

            $totals['warmed'] += $result->warmedCount();
            $totals['skipped'] += $result->skippedCount();
            $totals['failed'] += $result->failedCount();
            $totals['total'] += $result->total();
        }

        $this->log('');
        $this->log(sprintf(
            'Done. Total: %d, Warmed: %d, Skipped: %d, Failed: %d',
            $totals['total'],
            $totals['warmed'],
            $totals['skipped'],
            $totals['failed']
        ));

        if ($totals['failed'] > 0) {
            $this->warning($totals['failed'] . ' URL(s) failed to warm.');
        } else {
            $this->success($totals['warmed'] . ' URL(s) warmed.');
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
            $this->error('WordPress functions not available (wp_get_attachment_url).');
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
            $this->error('WordPress functions not available (get_posts).');
        }

        $this->log('Enumerating media library...');

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

        $this->log('Found ' . count($attachments) . ' attachment(s).');

        $urls = [];
        foreach ($attachments as $id) {
            foreach ($this->urlsForAttachment((int) $id) as $url) {
                $urls[] = $url;
            }
        }
        return $urls;
    }
}
