<?php
/**
 * WP-CLI `wp oxpulse info <url>` command.
 *
 * Shows the signed imgproxy URL that would be generated for a given
 * source URL, without dispatching a request. Useful for debugging
 * rewrite logic + verifying source authorization.
 *
 * @package OXPulse\Imager\Integration\WordPress\Cli
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Cli;

use OXPulse\Imager\Application\Delivery\UrlRewriterFactory;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;

final class InfoCommand extends AbstractCommand
{
    /**
     * Show the imgproxy URL that would be generated for a source URL.
     *
     * ## OPTIONS
     *
     * <url>
     * : The source image URL to preview.
     *
     * [--width=<n>]
     * : Target width in px (0 = no resize). Default 0.
     *
     * ## EXAMPLES
     *
     *     wp oxpulse info https://example.com/wp-content/uploads/photo.jpg
     *     wp oxpulse info https://example.com/wp-content/uploads/photo.jpg --width=800
     *
     * @param array $args       Positional args: [0] = source URL.
     * @param array $assoc_args Associative args.
     */
    public function info(array $args, array $assoc_args): void
    {
        if (!isset($args[0])) {
            $this->error(__('Usage: wp oxpulse info <url> [--width=<n>]', 'oxpulse-imager'));
        }

        $sourceUrl = (string) $args[0];
        $width = isset($assoc_args['width']) ? (int) $assoc_args['width'] : 0;

        $delivery = $this->repository->loadDeliveryConfig();
        $signing = $this->repository->loadSigningConfig();

        $this->log(sprintf(
            /* translators: %s: the source image URL being inspected. */
            __('Source URL: %s', 'oxpulse-imager'),
            $sourceUrl
        ));
        $this->log(sprintf(
            /* translators: %s: target width in pixels or "no resize". */
            __('Target width: %s', 'oxpulse-imager'),
            $width > 0 ? "{$width}px" : __('no resize', 'oxpulse-imager')
        ));
        $this->log(sprintf(
            /* translators: %s: whether delivery is enabled (yes/no). */
            __('Delivery enabled: %s', 'oxpulse-imager'),
            $delivery->enabled ? __('yes', 'oxpulse-imager') : __('no', 'oxpulse-imager')
        ));

        if (!$delivery->enabled) {
            $this->warning(__('Delivery is disabled — the source URL would NOT be rewritten on the frontend.', 'oxpulse-imager'));
            return;
        }

        if ($delivery->endpoint === '') {
            $this->warning(__('No endpoint configured — the source URL would NOT be rewritten.', 'oxpulse-imager'));
            return;
        }

        if ($signing === null) {
            $this->warning(__('No signing secrets configured — the source URL would NOT be rewritten.', 'oxpulse-imager'));
            return;
        }

        // #82: route through the health-gated factory so a cached-Down
        // imgproxy falls through to LocalBackend / passthrough — same
        // health-gate the front-end render path applies. Resolve the
        // relative endpoint to absolute first (mirrors ServiceRegistrar).
        $delivery = $delivery->withEndpoint(
            OptionSettingsRepository::resolveEndpoint($delivery->endpoint)
        );
        $rewriter = UrlRewriterFactory::fromConfig($delivery, $signing);
        $result = $rewriter->rewrite($sourceUrl, $width, 0, 'cli');

        $this->log('');
        $this->log(sprintf(
            /* translators: %s: rewrite result (REWRITTEN/PRESERVED). */
            __('Result: %s', 'oxpulse-imager'),
            $result->rewritten ? __('REWRITTEN', 'oxpulse-imager') : __('PRESERVED', 'oxpulse-imager')
        ));
        $this->log(sprintf(
            /* translators: %s: reason the URL was rewritten or preserved. */
            __('Reason: %s', 'oxpulse-imager'),
            $result->reason
        ));

        if ($result->rewritten) {
            $this->log('');
            $this->log(__('imgproxy URL:', 'oxpulse-imager'));
            $this->log('  ' . $result->url);
        }
    }
}
