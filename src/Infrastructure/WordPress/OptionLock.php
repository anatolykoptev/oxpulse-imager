<?php
/**
 * Compare-and-delete for option-backed mutexes.
 *
 * The plugin keeps two mutexes in the options table (the config seed lock
 * and the per-job prewarm lock). Both need "delete this row only while it
 * still holds the value I observed/wrote": core delete_option() cannot
 * condition on the value, and a delete that is not conditional lets a
 * slow holder or a second stealer remove somebody else's fresh lock.
 *
 * The raw DELETE bypasses core's options object cache, so the entry (and
 * the `notoptions` marker) is dropped explicitly. Without that, a
 * persistent cache (Redis, Memcached) keeps serving the stale lock to the
 * following add_option(), which consults get_option() and fails forever.
 *
 * @package OXPulse\Imager\Infrastructure\WordPress
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Infrastructure\WordPress;

final class OptionLock
{
    /**
     * Delete the option row only when option_value still equals $expected.
     *
     * @return bool True when this call deleted exactly one row.
     */
    public static function deleteIfValue(string $option, string $expected): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic compare-and-delete mutex; delete_option() cannot condition on the value, and a DELETE is not cacheable.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                $option,
                $expected
            )
        );

        wp_cache_delete($option, 'options');
        wp_cache_delete('notoptions', 'options');

        return (int) $deleted === 1;
    }
}
