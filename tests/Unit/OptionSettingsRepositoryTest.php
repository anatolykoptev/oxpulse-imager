<?php
/**
 * OptionSettingsRepository tests.
 *
 * Verifies secret/non-secret separation, secret status indicators, and
 * that secrets are never returned in bulk delivery config reads.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use PHPUnit\Framework\TestCase;

class OptionSettingsRepositoryTest extends TestCase
{
    private OptionSettingsRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $this->repository = new OptionSettingsRepository();
    }

    public function test_load_delivery_config_returns_disabled_defaults(): void
    {
        $config = $this->repository->loadDeliveryConfig();

        $this->assertFalse($config->enabled);
        $this->assertSame('', $config->endpoint);
        $this->assertSame([], $config->allowedSources);
        $this->assertSame('auto', $config->outputFormat);
        $this->assertSame(80, $config->defaultQuality);
        $this->assertFalse($config->devHttpOverride);
    }

    public function test_save_delivery_settings_persists_non_secret_fields(): void
    {
        $this->repository->saveDeliverySettings([
            'enabled' => true,
            'endpoint' => 'https://imgproxy.example.com',
            'allowed_sources' => ['https://example.com/uploads/'],
            'output_format' => 'avif',
            'default_quality' => 75,
            'dev_http_override' => false,
        ]);

        $config = $this->repository->loadDeliveryConfig();

        $this->assertTrue($config->enabled);
        $this->assertSame('https://imgproxy.example.com', $config->endpoint);
        $this->assertSame(['https://example.com/uploads/'], $config->allowedSources);
        $this->assertSame('avif', $config->outputFormat);
        $this->assertSame(75, $config->defaultQuality);
    }

    public function test_save_secrets_persists_to_dedicated_options(): void
    {
        $key = bin2hex(random_bytes(16));
        $salt = bin2hex(random_bytes(16));

        $this->repository->saveSecrets($key, $salt);

        $this->assertSame($key, get_option(OptionSettingsRepository::OPTION_KEY, ''));
        $this->assertSame($salt, get_option(OptionSettingsRepository::OPTION_SALT, ''));
    }

    public function test_load_delivery_config_does_not_expose_secrets(): void
    {
        $this->repository->saveSecrets(bin2hex(random_bytes(16)), bin2hex(random_bytes(16)));

        $config = $this->repository->loadDeliveryConfig();

        // DeliveryConfig is a readonly value object with declared properties.
        // Assert no property leaks key/salt by reflecting its public props.
        $reflection = new \ReflectionClass($config);
        $props = array_map(fn($p) => $p->getName(), $reflection->getProperties(\ReflectionProperty::IS_PUBLIC));
        $this->assertNotContains('key', $props);
        $this->assertNotContains('salt', $props);
    }

    public function test_has_secrets_reflects_configuration_state(): void
    {
        $this->assertFalse($this->repository->hasSecrets());

        $this->repository->saveSecrets(bin2hex(random_bytes(16)), bin2hex(random_bytes(16)));
        $this->assertTrue($this->repository->hasSecrets());
    }

    public function test_secret_status_reports_empty_partial_configured(): void
    {
        $this->assertSame('empty', $this->repository->secretStatus());

        update_option(OptionSettingsRepository::OPTION_KEY, bin2hex(random_bytes(16)));
        $this->assertSame('partial', $this->repository->secretStatus());

        update_option(OptionSettingsRepository::OPTION_SALT, bin2hex(random_bytes(16)));
        $this->assertSame('configured', $this->repository->secretStatus());
    }

    public function test_load_signing_config_returns_null_when_secrets_missing(): void
    {
        $this->assertNull($this->repository->loadSigningConfig());
    }

    public function test_load_signing_config_returns_config_when_secrets_present(): void
    {
        $key = bin2hex(random_bytes(16));
        $salt = bin2hex(random_bytes(16));

        $this->repository->saveSecrets($key, $salt);

        $config = $this->repository->loadSigningConfig();
        $this->assertNotNull($config);
        $this->assertSame($key, bin2hex($config->key));
        $this->assertSame($salt, bin2hex($config->salt));
    }

    public function test_load_signing_config_returns_null_for_invalid_hex(): void
    {
        // Non-hex values should not throw; loadSigningConfig swallows the
        // invalid-argument exception and returns null.
        update_option(OptionSettingsRepository::OPTION_KEY, 'not-hex');
        update_option(OptionSettingsRepository::OPTION_SALT, 'also-not-hex');

        $this->assertNull($this->repository->loadSigningConfig());
    }

    public function test_has_partial_secrets_detects_one_sided_state(): void
    {
        $this->assertFalse($this->repository->hasPartialSecrets());

        update_option(OptionSettingsRepository::OPTION_KEY, bin2hex(random_bytes(16)));
        $this->assertTrue($this->repository->hasPartialSecrets());

        update_option(OptionSettingsRepository::OPTION_SALT, bin2hex(random_bytes(16)));
        $this->assertFalse($this->repository->hasPartialSecrets());
    }

    public function test_load_allowed_sources_filters_non_string_and_empty(): void
    {
        update_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES, [
            'https://valid.example.com/',
            '',
            123,
            'https://another.example.com/',
        ]);

        $config = $this->repository->loadDeliveryConfig();

        $this->assertSame(
            ['https://valid.example.com/', 'https://another.example.com/'],
            $config->allowedSources
        );
    }

    public function test_load_allowed_sources_handles_non_array_option(): void
    {
        update_option(OptionSettingsRepository::OPTION_ALLOWED_SOURCES, 'not-an-array');

        $config = $this->repository->loadDeliveryConfig();

        $this->assertSame([], $config->allowedSources);
    }
}
