<?php
/**
 * WP-CLI service provider.
 *
 * Registers all `wp oxpulse` subcommands when WP-CLI is available.
 * Called from ServiceRegistrar only when `class_exists('\WP_CLI')` —
 * so the CLI classes are never loaded on a regular request.
 *
 * @package OXPulse\Imager\Integration\WordPress\Cli
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Cli;

final class CliServiceProvider
{
    public static function register(): void
    {
        if (!class_exists('\WP_CLI') || !method_exists('\WP_CLI', 'add_command')) {
            return;
        }

        \WP_CLI::add_command('oxpulse status', [new StatusCommand(), 'status']);
        \WP_CLI::add_command('oxpulse info', [new InfoCommand(), 'info']);
        \WP_CLI::add_command('oxpulse warm', [new WarmCommand(), 'warm']);
        \WP_CLI::add_command('oxpulse flush', [new FlushCommand(), 'flush']);
    }
}
