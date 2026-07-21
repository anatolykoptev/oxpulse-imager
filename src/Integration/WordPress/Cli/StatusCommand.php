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

        $this->log('OXPulse Imager status');
        $this->log('======================');
        $this->log('Delivery enabled: ' . ($delivery->enabled ? 'yes' : 'no'));
        $this->log('Endpoint: ' . ($delivery->endpoint ?: '(not configured)'));
        $this->log('Output format: ' . $delivery->outputFormat);
        $this->log('Default quality: ' . $delivery->defaultQuality);
        $this->log('Allowed sources: ' . count($delivery->allowedSources));
        foreach ($delivery->allowedSources as $source) {
            $this->log('  - ' . $source);
        }

        $this->log('Signing: ' . ($signing !== null ? 'configured (key+salt set)' : 'NOT configured'));
        $this->log('LQIP: ' . ($delivery->lqipEnabled ? 'enabled (blur=' . $delivery->lqipBlur . ')' : 'disabled'));
        $this->log('DPR srcset: ' . ($delivery->dprEnabled ? 'enabled (' . implode(',', $delivery->dprVariants) . ')' : 'disabled'));
        $this->log('Watermark: ' . ($delivery->watermark !== null ? 'enabled' : 'disabled'));

        $diagnosticLevel = (string) get_option(
            \OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository::OPTION_DIAGNOSTIC_LEVEL,
            'off'
        );
        $this->log('Diagnostic level: ' . $diagnosticLevel);

        if (isset($assoc_args['no-health'])) {
            $this->log('Health check: (skipped via --no-health)');
            return;
        }

        if ($delivery->endpoint === '') {
            $this->warning('Health check: no endpoint configured.');
            return;
        }

        $this->log('');
        $this->log('Health check...');
        $health = new HealthCheckService(new WordPressHealthClient());
        $result = $health->checkEndpoint($delivery->endpoint);
        if ($result->ok) {
            $this->success('imgproxy reachable: ' . $result->message);
        } else {
            $this->warning('imgproxy health: ' . $result->status . ' — ' . $result->message);
        }

        if (count($delivery->allowedSources) > 0) {
            $sampleImage = rtrim($delivery->allowedSources[0], '/') . '/oxpulse-avif-test.jpg';
            $this->log('AVIF check (sample: ' . $sampleImage . ')...');
            $avif = $health->checkAvifSupport($delivery->endpoint, $sampleImage);
            if ($avif->ok) {
                $this->success('AVIF supported: ' . $avif->message);
            } else {
                $this->warning('AVIF: ' . $avif->message);
            }
        }
    }
}
