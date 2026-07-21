<?php
/**
 * WordPressDiagnosticLogger tests.
 *
 * Tests the logger's accumulation, summary, flush, and recent-entries
 * logic using the transient stubs in bootstrap.php.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Domain\Diagnostics\LogEntry;
use OXPulse\Imager\Infrastructure\WordPress\WordPressDiagnosticLogger;
use PHPUnit\Framework\TestCase;

class WordPressDiagnosticLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_transients'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
        unset($GLOBALS['__oxpulse_transients']);
        parent::tearDown();
    }

    public function test_log_is_silent_when_level_off(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_diagnostic_level'] = 'off';
        $logger = new WordPressDiagnosticLogger();

        $logger->log(LogEntry::rewritten('content', 'https://example.com/img.jpg', 800));

        $this->assertSame(0, $logger->getSummary()['total']);
        $this->assertCount(0, $logger->getEntries());
    }

    public function test_log_accumulates_when_level_basic(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_diagnostic_level'] = 'basic';
        $logger = new WordPressDiagnosticLogger();

        $logger->log(LogEntry::rewritten('content', 'https://example.com/img.jpg', 800));
        $logger->log(LogEntry::preserved('srcset', 'https://evil.com/img.jpg', 400, 'source_not_allowed'));

        $summary = $logger->getSummary();
        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['rewritten']);
        $this->assertSame(1, $summary['preserved']);
    }

    public function test_log_accumulates_when_level_verbose(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_diagnostic_level'] = 'verbose';
        $logger = new WordPressDiagnosticLogger();

        $logger->log(LogEntry::rewritten('content', 'https://example.com/img.jpg', 800));
        $logger->log(LogEntry::preserved('srcset', 'https://evil.com/img.jpg', 400, 'source_not_allowed'));

        $entries = $logger->getEntries();
        $this->assertCount(2, $entries);
        $this->assertTrue($entries[0]->rewritten);
        $this->assertFalse($entries[1]->rewritten);
        $this->assertSame('source_not_allowed', $entries[1]->reason);
    }

    public function test_flush_writes_to_error_log_basic(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_diagnostic_level'] = 'basic';
        $logger = new WordPressDiagnosticLogger();

        $logger->log(LogEntry::rewritten('content', 'https://example.com/img.jpg', 800));
        $logger->log(LogEntry::preserved('srcset', 'https://evil.com/img.jpg', 400, 'source_not_allowed'));

        // Capture error_log output.
        $captured = [];
        set_error_handler(function () use (&$captured) {});
        // Use a custom handler via namespace — error_log is hard to
        // capture in PHP. We verify via the transient side-effect
        // instead (storeRecentEntries is called in flush).
        $logger->flush();
        restore_error_handler();

        // The recent entries transient should now have 2 entries.
        $recent = $logger->getRecentEntries();
        $this->assertCount(2, $recent);
    }

    public function test_flush_writes_to_error_log_verbose(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_diagnostic_level'] = 'verbose';
        $logger = new WordPressDiagnosticLogger();

        $logger->log(LogEntry::rewritten('content', 'https://example.com/img.jpg', 800));
        $logger->flush();

        $recent = $logger->getRecentEntries();
        $this->assertCount(1, $recent);
        $this->assertTrue($recent[0]['rewritten']);
        $this->assertSame('content', $recent[0]['context']);
    }

    public function test_flush_is_noop_when_level_off(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_diagnostic_level'] = 'off';
        $logger = new WordPressDiagnosticLogger();

        $logger->log(LogEntry::rewritten('content', 'https://example.com/img.jpg', 800));
        $logger->flush();

        // No entries should be stored.
        $recent = $logger->getRecentEntries();
        $this->assertCount(0, $recent);
    }

    public function test_flush_is_noop_when_no_entries(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_diagnostic_level'] = 'verbose';
        $logger = new WordPressDiagnosticLogger();

        $logger->flush();

        $recent = $logger->getRecentEntries();
        $this->assertCount(0, $recent);
    }

    public function test_recent_entries_merge_with_existing(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_diagnostic_level'] = 'verbose';
        $logger = new WordPressDiagnosticLogger();

        // Pre-populate the transient with one entry.
        $existing = [
            ['context' => 'content', 'rewritten' => true, 'reason' => 'rewritten', 'sourceUrl' => 'https://example.com/old.jpg', 'width' => 0, 'timestamp' => 1000.0],
        ];
        $GLOBALS['__oxpulse_transients'][WordPressDiagnosticLogger::RECENT_ENTRIES_TRANSIENT] = $existing;

        $logger->log(LogEntry::rewritten('srcset', 'https://example.com/new.jpg', 800));
        $logger->flush();

        $recent = $logger->getRecentEntries();
        // Should have 2 entries (1 existing + 1 new), most recent first.
        $this->assertCount(2, $recent);
        $this->assertSame('srcset', $recent[0]['context']);
        $this->assertSame('content', $recent[1]['context']);
    }

    public function test_recent_entries_capped_at_max(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_diagnostic_level'] = 'verbose';
        $logger = new WordPressDiagnosticLogger();

        // Add more than RECENT_ENTRIES_MAX * 2 entries.
        for ($i = 0; $i < WordPressDiagnosticLogger::RECENT_ENTRIES_MAX * 2 + 10; $i++) {
            $logger->log(LogEntry::rewritten('content', "https://example.com/img{$i}.jpg", 800));
        }
        $logger->flush();

        $recent = $logger->getRecentEntries();
        // getRecentEntries returns at most RECENT_ENTRIES_MAX.
        $this->assertLessThanOrEqual(WordPressDiagnosticLogger::RECENT_ENTRIES_MAX, count($recent));
    }

    public function test_get_summary_empty_when_no_entries(): void
    {
        $GLOBALS['__oxpulse_options']['oxpulse_imager_diagnostic_level'] = 'verbose';
        $logger = new WordPressDiagnosticLogger();

        $summary = $logger->getSummary();
        $this->assertSame(0, $summary['total']);
        $this->assertSame(0, $summary['rewritten']);
        $this->assertSame(0, $summary['preserved']);
    }
}
