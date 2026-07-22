<?php
/**
 * WP-CLI command base.
 *
 * Provides shared helpers for all `wp oxpulse` subcommands: config
 * loading, output formatting, error handling. Each subcommand extends
 * this class and is registered by CliServiceProvider.
 *
 * @package OXPulse\Imager\Integration\WordPress\Cli
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Cli;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;

abstract class AbstractCommand
{
    protected OptionSettingsRepository $repository;

    public function __construct(?OptionSettingsRepository $repository = null)
    {
        $this->repository = $repository ?? new OptionSettingsRepository();
    }

    /**
     * Print a line to stdout. Uses WP_CLI::log() when available, falls
     * back to echo for testability outside WP-CLI.
     */
    protected function log(string $message): void
    {
        if (class_exists('\WP_CLI') && method_exists('\WP_CLI', 'log')) {
            \WP_CLI::log($message);
        } else {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI context, not HTML.
            echo $message . "\n";
        }
    }

    /**
     * Print a success line. Uses WP_CLI::success() when available.
     */
    protected function success(string $message): void
    {
        if (class_exists('\WP_CLI') && method_exists('\WP_CLI', 'success')) {
            \WP_CLI::success($message);
        } else {
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI context, not HTML.
            echo sprintf(
                /* translators: %s: the success message. */
                __('Success: %s', 'oxpulse-imager'),
                $message
            ) . "\n";
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * Print a warning line. Uses WP_CLI::warning() when available.
     */
    protected function warning(string $message): void
    {
        if (class_exists('\WP_CLI') && method_exists('\WP_CLI', 'warning')) {
            \WP_CLI::warning($message);
        } else {
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI context, not HTML.
            echo sprintf(
                /* translators: %s: the warning message. */
                __('Warning: %s', 'oxpulse-imager'),
                $message
            ) . "\n";
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * Halt with an error. Uses WP_CLI::error() when available (which
     * exits with code 1), otherwise throws.
     *
     * @return never
     */
    protected function error(string $message): void
    {
        if (class_exists('\WP_CLI') && method_exists('\WP_CLI', 'error')) {
            \WP_CLI::error($message);
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI/exception context, not HTML.
        throw new \RuntimeException($message);
    }
}
