<?php
/**
 * OptionsRestController unit tests.
 *
 * Verifies the REST GET /oxpulse/v1/options response assembly, in
 * particular the Pro-gating of the `picture_enabled` READ value:
 * under free the GET must return pictureEnabled=false regardless of
 * the stored option (mirrors the cache_max_mb GET-gating via
 * loadCacheMaxMb() which returns the default under free). Under Pro
 * the stored value is returned unchanged.
 *
 * The backend oxpulse_picture_enabled filter at PHP_INT_MAX is the
 * real runtime gate; this test guards the READ/GET value the SPA
 * renders the toggle from, so a downgraded (trial-expired) site does
 * not show the toggle ON (greyed) while the feature is functionally
 * OFF — a false "Pro active" render.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Infrastructure\WordPress\SettingsValidator;
use OXPulse\Imager\Integration\WordPress\Admin\OptionsRestController;
use PHPUnit\Framework\TestCase;

class OptionsRestControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_fs_stub'] = null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_filters'],
            $GLOBALS['__oxpulse_actions'],
            $GLOBALS['__oxpulse_fs_stub']
        );
    }

    private function controller(): OptionsRestController
    {
        return new OptionsRestController(
            new OptionSettingsRepository(),
            new SettingsValidator()
        );
    }

    // ─── picture_enabled GET gating (mirrors cache_max_mb) ───────────

    /**
     * Free tier + stored picture_enabled=true → GET must return
     * pictureEnabled=false. Without the gate the SPA renders the
     * toggle ON (greyed) under free — a false "Pro active" render
     * after a trial expiry.
     */
    public function test_free_get_returns_picture_enabled_false_even_when_stored_true(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        update_option(OptionSettingsRepository::OPTION_PICTURE_ENABLED, true);

        $response = $this->controller()->handleGet();
        $data = $response->get_data();

        $this->assertArrayHasKey('pictureEnabled', $data);
        $this->assertFalse(
            $data['pictureEnabled'],
            'Free GET must return pictureEnabled=false even when the stored option is true (mirror cache_max_mb gating)',
        );
    }

    /**
     * Pro tier + stored picture_enabled=true → GET returns the stored
     * value (true). The gate only suppresses under free; Pro sees the
     * real toggle state.
     */
    public function test_pro_get_returns_stored_picture_enabled_true(): void
    {
        add_filter('oxpulse_is_pro', '__return_true');
        update_option(OptionSettingsRepository::OPTION_PICTURE_ENABLED, true);

        $response = $this->controller()->handleGet();
        $data = $response->get_data();

        $this->assertTrue(
            $data['pictureEnabled'],
            'Pro GET must return the stored picture_enabled value (true)',
        );
    }

    /**
     * Pro tier + stored picture_enabled=false → GET returns false.
     * Guards the non-true branch so the gate does not invert Pro logic.
     */
    public function test_pro_get_returns_stored_picture_enabled_false(): void
    {
        add_filter('oxpulse_is_pro', '__return_true');
        update_option(OptionSettingsRepository::OPTION_PICTURE_ENABLED, false);

        $response = $this->controller()->handleGet();
        $data = $response->get_data();

        $this->assertFalse(
            $data['pictureEnabled'],
            'Pro GET must return the stored picture_enabled value (false)',
        );
    }

    /**
     * Free tier + no stored option → GET returns false (default). The
     * gate and the default agree; guards the absent-option path.
     */
    public function test_free_get_returns_picture_enabled_false_when_option_absent(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');

        $response = $this->controller()->handleGet();
        $data = $response->get_data();

        $this->assertFalse($data['pictureEnabled']);
    }

    /**
     * Sibling invariant: cache_max_mb GET-gating via loadCacheMaxMb()
     * still returns the default under free. Guards the reference
     * pattern the picture gate mirrors, so a regression in the
     * reference is caught alongside the picture gate.
     */
    public function test_free_get_returns_default_cache_max_mb(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        update_option(OptionSettingsRepository::OPTION_CACHE_MAX_MB, 4096);

        $response = $this->controller()->handleGet();
        $data = $response->get_data();

        $this->assertSame(
            OptionSettingsRepository::DEFAULT_CACHE_MAX_MB,
            $data['cacheMaxMb'],
            'Free GET must return the default cache cap, ignoring the stored option',
        );
    }

    // ─── #71: picture_enabled survives unrelated saves ───────────────

    /**
     * What the SPA does on Save: GET the options, change one field,
     * POST the WHOLE object back (useOptionsStore::saveOptions).
     */
    private function spaRoundTrip(OptionsRestController $c, array $changes): void
    {
        $payload = $c->handleGet()->get_data();
        unset($payload['secretStatus']);
        $c->handleUpdate(new \WP_REST_Request(array_merge($payload, $changes)));
    }

    public function test_default_picture_enabled_is_off(): void
    {
        add_filter('oxpulse_is_pro', '__return_true');

        $this->assertFalse($this->controller()->handleGet()->get_data()['pictureEnabled']);
        $this->assertFalse((new OptionSettingsRepository())->loadDeliveryConfig()->pictureEnabled);
    }

    public function test_pro_unrelated_save_leaves_picture_enabled_unchanged_then_explicit_toggle_changes_it(): void
    {
        add_filter('oxpulse_is_pro', '__return_true');
        update_option(OptionSettingsRepository::OPTION_PICTURE_ENABLED, true);
        $c = $this->controller();

        $this->spaRoundTrip($c, ['lqipEnabled' => true]);
        $this->assertTrue(get_option(OptionSettingsRepository::OPTION_PICTURE_ENABLED), 'unrelated save must not touch the flag');
        $this->assertTrue((bool) get_option(OptionSettingsRepository::OPTION_LQIP_ENABLED), 'the unrelated change did persist');

        $this->spaRoundTrip($c, ['pictureEnabled' => false]);
        $this->assertFalse(get_option(OptionSettingsRepository::OPTION_PICTURE_ENABLED), 'explicit toggle off must persist');

        $this->spaRoundTrip($c, ['pictureEnabled' => true]);
        $this->assertTrue(get_option(OptionSettingsRepository::OPTION_PICTURE_ENABLED), 'explicit toggle on must persist');
    }

    public function test_free_unrelated_save_does_not_clobber_a_stored_picture_flag(): void
    {
        // Free GET reports false, so the SPA round-trips false. Without
        // the controller guard that false overwrites the stored true.
        add_filter('oxpulse_is_pro', '__return_false');
        update_option(OptionSettingsRepository::OPTION_PICTURE_ENABLED, true);

        $this->spaRoundTrip($this->controller(), ['lqipEnabled' => true]);

        $this->assertTrue(
            get_option(OptionSettingsRepository::OPTION_PICTURE_ENABLED),
            'a free-tier save must not overwrite the stored picture flag with the gated false',
        );
    }

    public function test_free_tier_cannot_write_picture_flag_explicitly(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');

        $this->controller()->handleUpdate(new \WP_REST_Request(['pictureEnabled' => true]));

        $this->assertNull(get_option(OptionSettingsRepository::OPTION_PICTURE_ENABLED, null));
    }

    // ─── #104: loose options persist through the repository ──────────

    public function test_post_persists_onboarded_diagnostic_level_and_remove_on_uninstall(): void
    {
        $this->controller()->handleUpdate(new \WP_REST_Request([
            'onboarded' => true,
            'diagnosticLevel' => 'basic',
            'removeOnUninstall' => true,
        ]));

        $this->assertTrue(get_option(OptionSettingsRepository::OPTION_ONBOARDED));
        $this->assertSame('basic', get_option(OptionSettingsRepository::OPTION_DIAGNOSTIC_LEVEL));
        $this->assertTrue(get_option(OptionSettingsRepository::OPTION_REMOVE_ON_UNINSTALL));
    }

    // ─── cache_max_mb: same free-tier clobber as picture_enabled ─────

    public function test_free_unrelated_save_does_not_clobber_a_stored_cache_cap(): void
    {
        add_filter('oxpulse_is_pro', '__return_false');
        update_option(OptionSettingsRepository::OPTION_CACHE_MAX_MB, 2048);

        $this->spaRoundTrip($this->controller(), ['lqipEnabled' => true]);

        $this->assertSame(2048, get_option(OptionSettingsRepository::OPTION_CACHE_MAX_MB));
    }

    public function test_pro_cache_cap_round_trips_and_changes_explicitly(): void
    {
        add_filter('oxpulse_is_pro', '__return_true');
        update_option(OptionSettingsRepository::OPTION_CACHE_MAX_MB, 2048);
        $c = $this->controller();

        $this->spaRoundTrip($c, ['lqipEnabled' => true]);
        $this->assertSame(2048, get_option(OptionSettingsRepository::OPTION_CACHE_MAX_MB));

        $this->spaRoundTrip($c, ['cacheMaxMb' => 1024]);
        $this->assertSame(1024, get_option(OptionSettingsRepository::OPTION_CACHE_MAX_MB));
    }
}
