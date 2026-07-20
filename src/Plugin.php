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

        self::$instance->registerAutoloader();
        self::$instance->registerServices();

        return self::$instance;
    }

    /**
     * PSR-4 autoloader for the OXPulse\Imager namespace.
     *
     * WordPress plugins cannot rely on Composer's autoloader at runtime,
     * so we register a minimal PSR-4 loader for the src/ directory. This
     * keeps class loading lazy and avoids a long require_once list.
     */
    private function registerAutoloader(): void
    {
        $srcDir = dirname($this->file) . '/src';
        $namespacePrefix = 'OXPulse\\Imager\\';

        spl_autoload_register(static function (string $class) use ($srcDir, $namespacePrefix): void {
            if (!str_starts_with($class, $namespacePrefix)) {
                return;
            }

            $relative = substr($class, strlen($namespacePrefix));
            $relative = str_replace('\\', '/', $relative);
            $path = $srcDir . '/' . $relative . '.php';

            if (is_file($path)) {
                require_once $path;
            }
        });
    }

    private function registerServices(): void
    {
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
