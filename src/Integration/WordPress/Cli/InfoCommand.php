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

use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Source\SourcePolicy;

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
            $this->error('Usage: wp oxpulse info <url> [--width=<n>]');
        }

        $sourceUrl = (string) $args[0];
        $width = isset($assoc_args['width']) ? (int) $assoc_args['width'] : 0;

        $delivery = $this->repository->loadDeliveryConfig();
        $signing = $this->repository->loadSigningConfig();

        $this->log('Source URL: ' . $sourceUrl);
        $this->log('Target width: ' . ($width > 0 ? "{$width}px" : 'no resize'));
        $this->log('Delivery enabled: ' . ($delivery->enabled ? 'yes' : 'no'));

        if (!$delivery->enabled) {
            $this->warning('Delivery is disabled — the source URL would NOT be rewritten on the frontend.');
            return;
        }

        if ($delivery->endpoint === '') {
            $this->warning('No endpoint configured — the source URL would NOT be rewritten.');
            return;
        }

        if ($signing === null) {
            $this->warning('No signing secrets configured — the source URL would NOT be rewritten.');
            return;
        }

        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing);
        $result = $rewriter->rewrite($sourceUrl, $width, 0, 'cli');

        $this->log('');
        $this->log('Result: ' . ($result->rewritten ? 'REWRITTEN' : 'PRESERVED'));
        $this->log('Reason: ' . $result->reason);

        if ($result->rewritten) {
            $this->log('');
            $this->log('imgproxy URL:');
            $this->log('  ' . $result->url);
        }
    }
}
