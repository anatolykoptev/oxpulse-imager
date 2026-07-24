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
}
