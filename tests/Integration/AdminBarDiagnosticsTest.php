<?php
/**
 * AdminBarDiagnostics tests.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Integration;

use OXPulse\Imager\Application\Diagnostics\DiagnosticLoggerInterface;
use OXPulse\Imager\Domain\Diagnostics\LogEntry;
use OXPulse\Imager\Integration\WordPress\Admin\AdminBarDiagnostics;
use PHPUnit\Framework\TestCase;

class AdminBarDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_current_user_can'] = [OXPULSE_IMAGER_CAPABILITY => true];
        $GLOBALS['__oxpulse_is_admin'] = false;
    }

    public function test_addAdminBarItem_adds_node_when_rewrites_exist(): void
    {
        $logger = new RecordingLogger();
        $logger->log(LogEntry::rewritten('content', 'https://example.com/img.jpg', 800));
        $logger->log(LogEntry::preserved('srcset', 'https://evil.com/img.jpg', 400, 'source_not_allowed'));

        $adminBar = new AdminBarDiagnostics($logger);

        $stubBar = new class {
            public array $nodes = [];
            public function add_node(array $args): void
            {
                $this->nodes[] = $args;
            }
        };

        $adminBar->addAdminBarItem($stubBar);

        $this->assertCount(1, $stubBar->nodes);
        $this->assertSame('oxpulse-diagnostics', $stubBar->nodes[0]['id']);
        $this->assertStringContainsString('1 rewritten', $stubBar->nodes[0]['title']);
        $this->assertStringContainsString('1 preserved', $stubBar->nodes[0]['title']);
    }

    public function test_addAdminBarItem_skips_when_no_rewrites(): void
    {
        $logger = new RecordingLogger();
        $adminBar = new AdminBarDiagnostics($logger);

        $stubBar = new class {
            public array $nodes = [];
            public function add_node(array $args): void
            {
                $this->nodes[] = $args;
            }
        };

        $adminBar->addAdminBarItem($stubBar);

        $this->assertCount(0, $stubBar->nodes);
    }

    public function test_addAdminBarItem_skips_when_no_capability(): void
    {
        $GLOBALS['__oxpulse_current_user_can'] = [];
        $logger = new RecordingLogger();
        $logger->log(LogEntry::rewritten('content', 'https://example.com/img.jpg', 800));
        $adminBar = new AdminBarDiagnostics($logger);

        $stubBar = new class {
            public array $nodes = [];
            public function add_node(array $args): void
            {
                $this->nodes[] = $args;
            }
        };

        $adminBar->addAdminBarItem($stubBar);

        $this->assertCount(0, $stubBar->nodes);
    }

    public function test_register_hooks_admin_bar_menu(): void
    {
        $logger = new RecordingLogger();
        $adminBar = new AdminBarDiagnostics($logger);
        $adminBar->register();

        $found = false;
        foreach ($GLOBALS['__oxpulse_actions'] ?? [] as $action) {
            if ($action['hook'] === 'admin_bar_menu') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }
}

class RecordingLogger implements DiagnosticLoggerInterface
{
    public array $entries = [];

    public function log(LogEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function getEntries(): array
    {
        return $this->entries;
    }

    public function getSummary(): array
    {
        $rewritten = count(array_filter($this->entries, fn ($e) => $e->rewritten));
        return [
            'rewritten' => $rewritten,
            'preserved' => count($this->entries) - $rewritten,
            'total' => count($this->entries),
        ];
    }

    public function flush(): void {}
}
