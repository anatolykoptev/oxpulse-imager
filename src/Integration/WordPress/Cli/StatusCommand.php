<?php
/**
 * WP-CLI `wp oxpulse status` command.
 *
 * Prints config + health + counts in one shot. Verifies the imgproxy
 * endpoint is reachable and reports AVIF support.
 *
 * @package OXPulse\Imager\Integration\WordPress\Cli
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Cli;

use OXPulse\Imager\Application\Health\HealthCheckService;
use OXPulse\Imager\Infrastructure\Http\WordPressHealthClient;

final class StatusCommand extends AbstractCommand
{
    /**
     * Print OXPulse Imager status: config, signing, health, AVIF.
     *
     * ## OPTIONS
     *
     * [--no-health]
     * : Skip the live health check (faster, no HTTP).
     *
     * ## EXAMPLES
     *
     *     wp oxpulse status
     *     wp oxpulse status --no-health
     *
     * @param array $args       Positional args (unused).
     * @param array $assoc_args Associative args.
     */
    public function status(array $args, array $assoc_args): void
    {
        $delivery = $this->repository->loadDeliveryConfig();
        $signing = $this->repository->loadSigningConfig();

        $this->log(__('OXPulse Imager status', 'oxpulse-imager'));
        $this->log('======================');
        $this->log(sprintf(__('Delivery enabled: %s', 'oxpulse-imager'), $delivery->enabled ? __('yes', 'oxpulse-imager') : __('no', 'oxpulse-imager')));
        $this->log(sprintf(__('Endpoint: %s', 'oxpulse-imager'), $delivery->endpoint ?: __('(not configured)', 'oxpulse-imager')));
        $this->log(sprintf(__('Output format: %s', 'oxpulse-imager'), $delivery->outputFormat));
        $this->log(sprintf(__('Default quality: %s', 'oxpulse-imager'), $delivery->defaultQuality));
        $this->log(sprintf(__('Allowed sources: %d', 'oxpulse-imager'), count($delivery->allowedSources)));
        foreach ($delivery->allowedSources as $source) {
            $this->log(sprintf(__('  - %s', 'oxpulse-imager'), $source));
        }

        $this->log(sprintf(__('Signing: %s', 'oxpulse-imager'), $signing !== null ? __('configured (key+salt set)', 'oxpulse-imager') : __('NOT configured', 'oxpulse-imager')));
        $this->log(sprintf(__('LQIP: %s', 'oxpulse-imager'), $delivery->lqipEnabled ? sprintf(__('enabled (blur=%s)', 'oxpulse-imager'), $delivery->lqipBlur) : __('disabled', 'oxpulse-imager')));
        $this->log(sprintf(__('DPR srcset: %s', 'oxpulse-imager'), $delivery->dprEnabled ? sprintf(__('enabled (%s)', 'oxpulse-imager'), implode(',', $delivery->dprVariants)) : __('disabled', 'oxpulse-imager')));
        $this->log(sprintf(__('Watermark: %s', 'oxpulse-imager'), $delivery->watermark !== null ? __('enabled', 'oxpulse-imager') : __('disabled', 'oxpulse-imager')));

        $diagnosticLevel = (string) get_option(
            \OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository::OPTION_DIAGNOSTIC_LEVEL,
            'off'
        );
        $this->log(sprintf(__('Diagnostic level: %s', 'oxpulse-imager'), $diagnosticLevel));

        if (isset($assoc_args['no-health'])) {
            $this->log(__('Health check: (skipped via --no-health)', 'oxpulse-imager'));
            return;
        }

        if ($delivery->endpoint === '') {
            $this->warning(__('Health check: no endpoint configured.', 'oxpulse-imager'));
            return;
        }

        $this->log('');
        $this->log(__('Health check...', 'oxpulse-imager'));
        $health = new HealthCheckService(new WordPressHealthClient());
        $result = $health->checkEndpoint($delivery->endpoint);
        if ($result->ok) {
            $this->success(sprintf(__('imgproxy reachable: %s', 'oxpulse-imager'), $result->message));
        } else {
            $this->warning(sprintf(__('imgproxy health: %s — %s', 'oxpulse-imager'), $result->status, $result->message));
        }

        if (count($delivery->allowedSources) > 0) {
            $sampleImage = rtrim($delivery->allowedSources[0], '/') . '/oxpulse-avif-test.jpg';
            $this->log(sprintf(__('AVIF check (sample: %s)...', 'oxpulse-imager'), $sampleImage));
            $avif = $health->checkAvifSupport($delivery->endpoint, $sampleImage);
            if ($avif->ok) {
                $this->success(sprintf(__('AVIF supported: %s', 'oxpulse-imager'), $avif->message));
            } else {
                $this->warning(sprintf(__('AVIF: %s', 'oxpulse-imager'), $avif->message));
            }
        }
    }
}
