<?php
/**
 * Admin bar diagnostics item.
 *
 * Adds an "OXPulse: X rewritten, Y preserved" item to the WordPress
 * admin bar on frontend pages. Shows the in-memory counts from the
 * current request's DiagnosticLogger. Only visible to users with the
 * OXPULSE_IMAGER_CAPABILITY.
 *
 * @package OXPulse\Imager\Integration\WordPress\Admin
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Admin;

use OXPulse\Imager\Application\Diagnostics\DiagnosticLoggerInterface;

final class AdminBarDiagnostics
{
    private DiagnosticLoggerInterface $logger;

    public function __construct(DiagnosticLoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function register(): void
    {
        add_action('admin_bar_menu', [$this, 'addAdminBarItem'], 100);
    }

    /**
     * Add the diagnostics item to the admin bar.
     *
     * @param \WP_Admin_Bar $adminBar
     */
    public function addAdminBarItem($adminBar): void
    {
        if (!function_exists('current_user_can') || !current_user_can(OXPULSE_IMAGER_CAPABILITY)) {
            return;
        }

        // Only show on frontend pages, not in wp-admin.
        if (is_admin()) {
            return;
        }

        $summary = $this->logger->getSummary();

        // Don't show the item if no rewrites were attempted on this page.
        if ($summary['total'] === 0) {
            return;
        }

        $text = sprintf(
            'OXPulse: %d rewritten, %d preserved',
            $summary['rewritten'],
            $summary['preserved']
        );

        $adminBar->add_node([
            'id'    => 'oxpulse-diagnostics',
            'title' => '<span style="color: #50c878;">●</span> ' . esc_html($text),
            'href'  => admin_url('admin.php?page=oxpulse-imager#diagnostics'),
            'meta'  => [
                'title' => __('OXPulse Imager diagnostics — click to view details', 'oxpulse-imager'),
            ],
        ]);
    }
}
