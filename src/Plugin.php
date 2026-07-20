<?php
/**
 * Main plugin service provider.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager;

use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;

final class Plugin
{
    private static ?self $instance = null;

    private string $file;

    private function __construct(string $file)
    {
        $this->file = $file;
    }

    public static function load(string $file): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        self::$instance = new self($file);

        self::$instance->registerServices();

        return self::$instance;
    }

    private function registerServices(): void
    {
        require_once dirname($this->file) . '/src/Infrastructure/WordPress/ServiceRegistrar.php';

        ServiceRegistrar::register($this);
    }

    public function file(): string
    {
        return $this->file;
    }

    public function dir(): string
    {
        return plugin_dir_path($this->file);
    }

    public function url(): string
    {
        return plugin_dir_url($this->file);
    }

    public function basename(): string
    {
        return plugin_basename($this->file);
    }
}
