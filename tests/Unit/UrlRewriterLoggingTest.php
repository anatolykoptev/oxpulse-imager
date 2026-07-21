<?php
/**
 * UrlRewriter diagnostic logging tests.
 *
 * Verifies that UrlRewriter records log entries when a logger is
 * attached, and that the entries have the correct context/reason.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Application\Diagnostics\DiagnosticLoggerInterface;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Diagnostics\LogEntry;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use PHPUnit\Framework\TestCase;

class UrlRewriterLoggingTest extends TestCase
{
    private DeliveryConfig $delivery;
    private SigningConfig $signing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->delivery = new DeliveryConfig(
            enabled: true,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: ['https://example.com/uploads/'],
            outputFormat: 'auto',
            defaultQuality: 80,
            devHttpOverride: false,
            lqipEnabled: false,
            lqipBlur: 1,
            dprEnabled: false,
            dprVariants: [1, 2, 3],
            watermark: null,
            formatQuality: [],
        );
        $this->signing = new SigningConfig(
            hex2bin('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'),
            hex2bin('f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3')
        );
    }

    public function test_rewrite_logs_rewritten_entry(): void
    {
        $logger = new RecordingLogger();
        $rewriter = new UrlRewriter(new SourcePolicy(), $this->delivery, $this->signing, $logger);

        $rewriter->rewrite('https://example.com/uploads/photo.jpg', 800, 0, 'content');

        $this->assertCount(1, $logger->entries);
        $this->assertTrue($logger->entries[0]->rewritten);
        $this->assertSame('content', $logger->entries[0]->context);
        $this->assertSame('rewritten', $logger->entries[0]->reason);
        $this->assertSame(800, $logger->entries[0]->width);
    }

    public function test_rewrite_logs_preserved_entry_when_unauthorized(): void
    {
        $logger = new RecordingLogger();
        $rewriter = new UrlRewriter(new SourcePolicy(), $this->delivery, $this->signing, $logger);

        $rewriter->rewrite('https://evil.com/uploads/photo.jpg', 800, 0, 'content');

        $this->assertCount(1, $logger->entries);
        $this->assertFalse($logger->entries[0]->rewritten);
        $this->assertSame('source_not_in_allowlist', $logger->entries[0]->reason);
    }

    public function test_rewrite_logs_preserved_entry_when_delivery_disabled(): void
    {
        $logger = new RecordingLogger();
        $delivery = new DeliveryConfig(
            enabled: false,
            endpoint: 'https://imgproxy.example.com',
            allowedSources: ['https://example.com/uploads/'],
            outputFormat: 'auto',
            defaultQuality: 80,
            devHttpOverride: false,
            lqipEnabled: false,
            lqipBlur: 1,
            dprEnabled: false,
            dprVariants: [1, 2, 3],
            watermark: null,
            formatQuality: [],
        );
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $this->signing, $logger);

        $rewriter->rewrite('https://example.com/uploads/photo.jpg', 800, 0, 'content');

        $this->assertCount(1, $logger->entries);
        $this->assertFalse($logger->entries[0]->rewritten);
        $this->assertSame('delivery_disabled', $logger->entries[0]->reason);
    }

    public function test_rewrite_does_not_log_when_no_logger(): void
    {
        // No logger = no logging. This is the default — logging is opt-in.
        $rewriter = new UrlRewriter(new SourcePolicy(), $this->delivery, $this->signing);

        $result = $rewriter->rewrite('https://example.com/uploads/photo.jpg', 800, 0, 'content');

        $this->assertTrue($result->rewritten);
        // No exception, no side effects — just works.
    }

    public function test_rewrite_logs_preserved_entry_when_no_endpoint(): void
    {
        $logger = new RecordingLogger();
        $delivery = new DeliveryConfig(
            enabled: true,
            endpoint: '',
            allowedSources: ['https://example.com/uploads/'],
            outputFormat: 'auto',
            defaultQuality: 80,
            devHttpOverride: false,
            lqipEnabled: false,
            lqipBlur: 1,
            dprEnabled: false,
            dprVariants: [1, 2, 3],
            watermark: null,
            formatQuality: [],
        );
        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $this->signing, $logger);

        $rewriter->rewrite('https://example.com/uploads/photo.jpg', 800, 0, 'content');

        $this->assertCount(1, $logger->entries);
        $this->assertSame('no_endpoint', $logger->entries[0]->reason);
    }

    public function test_rewrite_logs_preserved_entry_when_no_signing(): void
    {
        $logger = new RecordingLogger();
        $rewriter = new UrlRewriter(new SourcePolicy(), $this->delivery, null, $logger);

        $rewriter->rewrite('https://example.com/uploads/photo.jpg', 800, 0, 'content');

        $this->assertCount(1, $logger->entries);
        $this->assertSame('no_signing_config', $logger->entries[0]->reason);
    }
}

/**
 * Simple recording logger for tests — captures all entries.
 */
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

    public function flush(): void
    {
        // No-op for tests.
    }
}
