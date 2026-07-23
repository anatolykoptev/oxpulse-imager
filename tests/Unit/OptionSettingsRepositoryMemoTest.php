<?php
/**
 * OptionSettingsRepository in-request memoization tests (#91).
 *
 * Verifies the per-instance option memo collapses repeated get_option
 * reads, that a save busts the memo so a same-request read returns the
 * new value, and that every load* method returns values identical to a
 * direct get_option assembly (no behavior change).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use PHPUnit\Framework\TestCase;

class OptionSettingsRepositoryMemoTest extends TestCase
{
    private OptionSettingsRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_get_option_calls'] = 0;
        $this->repository = new OptionSettingsRepository();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options'], $GLOBALS['__oxpulse_get_option_calls']);
        parent::tearDown();
    }

    /**
     * Count get_option invocations since the last reset by reading the
     * bootstrap stub's call counter, then reset it for the next span.
     */
    private function getOptionCalls(): int
    {
        $n = (int) ($GLOBALS['__oxpulse_get_option_calls'] ?? 0);
        $GLOBALS['__oxpulse_get_option_calls'] = 0;
        return $n;
    }

    // ── Memoization: repeated reads hit the cache, not get_option ──

    public function test_load_delivery_config_memoizes_repeated_reads(): void
    {
        $first = $this->repository->loadDeliveryConfig();
        $firstCalls = $this->getOptionCalls();
        $this->assertGreaterThan(0, $firstCalls, 'first load should read options');

        // Second call on the same instance must serve entirely from
        // the memo — zero get_option invocations.
        $second = $this->repository->loadDeliveryConfig();
        $secondCalls = $this->getOptionCalls();

        $this->assertSame(0, $secondCalls, 'second load must not call get_option');
        $this->assertEquals($first, $second, 'memoized load must be value-identical');
    }

    public function test_load_signing_config_memoizes_repeated_reads(): void
    {
        update_option(OptionSettingsRepository::OPTION_KEY, bin2hex(random_bytes(16)));
        update_option(OptionSettingsRepository::OPTION_SALT, bin2hex(random_bytes(16)));

        $first = $this->repository->loadSigningConfig();
        $firstCalls = $this->getOptionCalls();
        $this->assertGreaterThan(0, $firstCalls);

        $second = $this->repository->loadSigningConfig();
        $secondCalls = $this->getOptionCalls();

        $this->assertSame(0, $secondCalls);
        $this->assertEquals($first, $second);
    }

    public function test_delivery_then_signing_share_the_memo(): void
    {
        // loadDeliveryConfig + loadSigningConfig read disjoint option
        // sets. A second pass over both must hit zero get_option calls
        // because the instance memo already holds every key.
        $this->repository->loadDeliveryConfig();
        $this->repository->loadSigningConfig();
        $this->getOptionCalls(); // discard first-pass count

        $this->repository->loadDeliveryConfig();
        $this->repository->loadSigningConfig();
        $secondPassCalls = $this->getOptionCalls();

        $this->assertSame(0, $secondPassCalls, 'second pass over both loads must be fully memoized');
    }

    public function test_fresh_instance_has_independent_memo(): void
    {
        $this->repository->loadDeliveryConfig();
        $this->getOptionCalls();

        // A new instance does not inherit the first instance's memo —
        // it must re-read from the option store.
        $fresh = new OptionSettingsRepository();
        $fresh->loadDeliveryConfig();
        $freshCalls = $this->getOptionCalls();

        $this->assertGreaterThan(0, $freshCalls, 'a new instance must re-read options');
    }

    // ── Save busts the memo: same-request read returns the new value ──

    public function test_save_delivery_settings_busts_memo_for_same_request_read(): void
    {
        $config = $this->repository->loadDeliveryConfig();
        $this->assertFalse($config->enabled);
        $this->assertSame('', $config->endpoint);

        $this->repository->saveDeliverySettings([
            'enabled' => true,
            'endpoint' => 'https://imgproxy.example.com',
            'default_quality' => 72,
        ]);

        // Same instance, same request — the memo was busted on save,
        // so the read returns the freshly persisted values.
        $reread = $this->repository->loadDeliveryConfig();
        $this->assertTrue($reread->enabled);
        $this->assertSame('https://imgproxy.example.com', $reread->endpoint);
        $this->assertSame(72, $reread->defaultQuality);
    }

    public function test_save_secrets_busts_memo_for_same_request_read(): void
    {
        $this->assertNull($this->repository->loadSigningConfig());

        $key = bin2hex(random_bytes(16));
        $salt = bin2hex(random_bytes(16));
        $this->repository->saveSecrets($key, $salt);

        $config = $this->repository->loadSigningConfig();
        $this->assertNotNull($config);
        $this->assertSame($key, bin2hex($config->key));
        $this->assertSame($salt, bin2hex($config->salt));
    }

    public function test_save_rewrite_capability_busts_memo(): void
    {
        $this->assertSame('unknown', $this->repository->loadRewriteCapability());

        $this->repository->saveRewriteCapability('yes');

        $this->assertSame('yes', $this->repository->loadRewriteCapability());
    }

    public function test_invalidate_rewrite_capability_busts_memo(): void
    {
        $this->repository->saveRewriteCapability('yes');
        $this->assertSame('yes', $this->repository->loadRewriteCapability());

        $this->repository->invalidateRewriteCapability();

        $this->assertSame('unknown', $this->repository->loadRewriteCapability());
        $this->assertSame(0, $this->repository->loadRewriteCapabilityCheckedAt());
    }

    public function test_refresh_clears_the_memo_explicitly(): void
    {
        $this->repository->loadDeliveryConfig();
        $this->getOptionCalls();

        $this->repository->refresh();
        $this->repository->loadDeliveryConfig();
        $calls = $this->getOptionCalls();

        $this->assertGreaterThan(0, $calls, 'refresh() must force a re-read');
    }

    // ── Parity: memoized loads are value-identical to direct assembly ──

    public function test_load_delivery_config_matches_direct_assembly(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENABLED, true);
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.test');
        update_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES, ['https://src.test/']);
        update_option(OptionSettingsRepository::OPTION_OUTPUT_FORMAT, 'avif');
        update_option(OptionSettingsRepository::OPTION_DEFAULT_QUALITY, 77);
        update_option(OptionSettingsRepository::OPTION_DEV_HTTP, true);
        update_option(OptionSettingsRepository::OPTION_LQIP_ENABLED, true);
        update_option(OptionSettingsRepository::OPTION_LQIP_BLUR, 2.5);
        update_option(OptionSettingsRepository::OPTION_DPR_ENABLED, true);
        update_option(OptionSettingsRepository::OPTION_DPR_VARIANTS, [1, 2, 3]);
        update_option(OptionSettingsRepository::OPTION_FORMAT_QUALITY, ['avif' => 65, 'webp' => 75]);
        update_option(OptionSettingsRepository::OPTION_WATERMARK, [
            'enabled' => true,
            'opacity' => 0.8,
            'position' => 'ce',
            'x_offset' => 10,
            'y_offset' => 20,
            'scale' => 0.3,
        ]);
        update_option(OptionSettingsRepository::OPTION_SOURCE_MODE, 'local');
        update_option(OptionSettingsRepository::OPTION_LOCAL_BASE_PATH, '/var/www/uploads');
        update_option(OptionSettingsRepository::OPTION_BUFFER_REWRITING_ENABLED, true);
        update_option(OptionSettingsRepository::OPTION_PICTURE_ENABLED, true);
        update_option(OptionSettingsRepository::OPTION_RANKMATH_COMPATIBILITY, false);
        update_option(OptionSettingsRepository::OPTION_SAVE_DATA_QUALITY_REDUCTION, 25);
        update_option(OptionSettingsRepository::OPTION_SIZE_QUALITY_TIERS, [400 => 75, 800 => ['avif' => 65]]);

        $config = $this->repository->loadDeliveryConfig();

        // Direct assembly (the pre-memoization read path).
        $this->assertTrue($config->enabled);
        $this->assertSame('https://imgproxy.test', $config->endpoint);
        $this->assertSame(['https://src.test/'], $config->allowedSources);
        $this->assertSame('avif', $config->outputFormat);
        $this->assertSame(77, $config->defaultQuality);
        $this->assertTrue($config->devHttpOverride);
        $this->assertTrue($config->lqipEnabled);
        $this->assertSame(2.5, $config->lqipBlur);
        $this->assertTrue($config->dprEnabled);
        $this->assertSame([1, 2, 3], $config->dprVariants);
        $this->assertSame(['avif' => 65, 'webp' => 75], $config->formatQuality);
        $this->assertNotNull($config->watermark);
        $this->assertSame(0.8, $config->watermark->opacity);
        $this->assertSame('ce', $config->watermark->position);
        $this->assertSame(10, $config->watermark->xOffset);
        $this->assertSame(20, $config->watermark->yOffset);
        $this->assertSame(0.3, $config->watermark->scale);
        $this->assertSame('local', $config->sourceMode);
        $this->assertSame('/var/www/uploads', $config->localBasePath);
        $this->assertTrue($config->bufferRewritingEnabled);
        $this->assertTrue($config->pictureEnabled);
        $this->assertFalse($config->rankMathCompatibility);
        $this->assertSame(25, $config->saveDataQualityReduction);
        $this->assertSame([400 => 75, 800 => ['avif' => 65]], $config->sizeQualityTiers);
    }

    public function test_load_delivery_config_defaults_match_direct_assembly(): void
    {
        // No options set — every value comes from the memoized default.
        $config = $this->repository->loadDeliveryConfig();

        $this->assertFalse($config->enabled);
        $this->assertSame('', $config->endpoint);
        $this->assertSame([], $config->allowedSources);
        $this->assertSame('auto', $config->outputFormat);
        $this->assertSame(80, $config->defaultQuality);
        $this->assertFalse($config->devHttpOverride);
        $this->assertFalse($config->lqipEnabled);
        $this->assertSame(1.0, $config->lqipBlur);
        $this->assertFalse($config->dprEnabled);
        $this->assertSame([], $config->dprVariants);
        $this->assertSame([], $config->formatQuality);
        $this->assertNull($config->watermark);
        $this->assertSame('http', $config->sourceMode);
        $this->assertSame('', $config->localBasePath);
        $this->assertFalse($config->bufferRewritingEnabled);
        $this->assertFalse($config->pictureEnabled);
        $this->assertTrue($config->rankMathCompatibility);
        $this->assertSame(15, $config->saveDataQualityReduction);
        $this->assertSame([], $config->sizeQualityTiers);
    }
}
