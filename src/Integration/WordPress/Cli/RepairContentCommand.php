<?php
/**
 * `wp oxpulse repair-content` — restore baked proxied URLs (#135).
 *
 * Finds posts whose post_content contains this site's proxied image
 * URLs and restores the origin URLs via BakedUrlRestorer. Dry-run by
 * default; `--commit` writes (one revision per touched post via
 * wp_update_post). URLs that do not decode cleanly are reported and
 * left untouched.
 *
 * @package OXPulse\Imager\Integration\WordPress\Cli
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Integration\WordPress\Cli;

use OXPulse\Imager\Application\Repair\BakedUrlRestorer;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;

final class RepairContentCommand extends AbstractCommand
{
    /**
     * Restore origin image URLs baked into post content.
     *
     * ## OPTIONS
     *
     * [--commit]
     * : Write the restored content. Without this flag the command
     *   reports what WOULD change and touches nothing.
     *
     * ## EXAMPLES
     *
     *     wp oxpulse repair-content
     *     wp oxpulse repair-content --commit
     *
     * @param array $args Positional args (unused).
     * @param array $assocArgs Associative args.
     */
    public function repair(array $args, array $assocArgs): void
    {
        $commit = isset($assocArgs['commit']);

        $delivery = $this->repository->loadDeliveryConfig();
        $endpoint = OptionSettingsRepository::resolveEndpoint($delivery->endpoint);
        if ($endpoint === '') {
            $this->error('No delivery endpoint configured — nothing to repair against.');
            return;
        }

        $restorer = new BakedUrlRestorer($endpoint, home_url());

        $endpointPath = rtrim((string) (parse_url($endpoint, PHP_URL_PATH) ?: $endpoint), '/');

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- targeted repair sweep, LIKE on content.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_content LIKE %s
               AND post_type NOT IN ('revision')
               AND post_status NOT IN ('trash', 'auto-draft')
             ORDER BY ID
             LIMIT 1000",
            '%' . $wpdb->esc_like($endpointPath . '/') . '%'
        ));

        if ($ids === []) {
            $this->success('No posts contain proxied URLs. Nothing to do.');
            return;
        }

        $touched = 0;
        $totalReplaced = 0;
        $totalSkipped = 0;

        foreach ($ids as $id) {
            $post = get_post((int) $id);
            if ($post === null) {
                continue;
            }

            $outcome = $restorer->restore($post->post_content);

            if ($outcome['replaced'] === 0 && $outcome['skipped'] === []) {
                continue;
            }

            $this->log(sprintf(
                '#%d "%s": %d restored, %d skipped%s',
                $post->ID,
                mb_substr($post->post_title, 0, 50),
                $outcome['replaced'],
                count($outcome['skipped']),
                $commit ? '' : ' (dry-run)'
            ));
            foreach ($outcome['skipped'] as $skippedUrl) {
                $this->log('    SKIPPED (not decodable): ' . mb_substr($skippedUrl, 0, 120));
            }

            $totalReplaced += $outcome['replaced'];
            $totalSkipped += count($outcome['skipped']);

            if ($commit && $outcome['replaced'] > 0) {
                // wp_update_post expects slashed data (it unslashes).
                $result = wp_update_post(wp_slash([
                    'ID' => $post->ID,
                    'post_content' => $outcome['content'],
                ]), true);
                if (is_wp_error($result)) {
                    $this->error(sprintf('#%d update failed: %s', $post->ID, $result->get_error_message()));
                    continue;
                }
                $touched++;
            }
        }

        $mode = $commit ? sprintf('%d posts updated', $touched) : 'dry-run, nothing written';
        $this->success(sprintf(
            '%d URLs restorable, %d skipped across %d posts — %s.',
            $totalReplaced,
            $totalSkipped,
            count($ids),
            $mode
        ));
    }
}
