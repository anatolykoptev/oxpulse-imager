<?php
/**
 * #137: imgproxy up/down flips are recorded by ImgproxyHealthCache::write()
 * and surfaced (bounded) on the settings screen.
 *
 * @package OXPulse\Imager\Tests\Unit
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyFlapLog;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use OXPulse\Imager\Infrastructure\WordPress\OptionSettingsRepository;
use OXPulse\Imager\Integration\WordPress\Admin\SettingsPage;
use PHPUnit\Framework\TestCase;

class ImgproxyFlapLogTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_options']);
    }

    public function test_health_write_flip_is_recorded(): void
    {
        $cache = new ImgproxyHealthCache();

        $cache->write('down');
        $summary = (new ImgproxyFlapLog())->summary();
        $this->assertSame(1, $summary['total'], 'up -> down must be recorded as one flip');
        $this->assertSame('down', $summary['state']);
        $this->assertGreaterThan(0, $summary['last']);

        $cache->write('down');
        $this->assertSame(1, (new ImgproxyFlapLog())->summary()['total'], 'down -> down is not a flip');

        $cache->write('up');
        $summary = (new ImgproxyFlapLog())->summary();
        $this->assertSame(2, $summary['total']);
        $this->assertSame('up', $summary['state']);
    }

    public function test_first_ever_up_is_not_a_flip(): void
    {
        (new ImgproxyHealthCache())->write('up');
        $this->assertSame(0, (new ImgproxyFlapLog())->summary()['total']);
    }

    public function test_history_stays_bounded(): void
    {
        $log = new ImgproxyFlapLog();
        $now = 1_800_000_000;
        for ($i = 0; $i < 200; $i++) {
            $log->record($i % 2 === 0 ? 'down' : 'up', $now + $i);
        }

        $raw = get_option(ImgproxyFlapLog::OPTION);
        $this->assertCount(ImgproxyFlapLog::MAX_EVENTS, $raw['events'], 'stored event list must be capped');

        $summary = $log->summary($now + 200);
        $this->assertSame(200, $summary['total'], 'the total keeps counting');
        $this->assertSame(ImgproxyFlapLog::MAX_EVENTS, $summary['recent']);
        $this->assertTrue($summary['recentCapped']);
    }

    public function test_recent_count_ignores_flips_outside_the_window(): void
    {
        $log = new ImgproxyFlapLog();
        $log->record('down', 1_000);
        $log->record('up', 1_000 + ImgproxyFlapLog::WINDOW + 10);
        $summary = $log->summary(1_000 + ImgproxyFlapLog::WINDOW + 20);
        $this->assertSame(1, $summary['recent']);
        $this->assertSame(2, $summary['total']);
    }

    public function test_garbage_option_reads_as_empty(): void
    {
        update_option(ImgproxyFlapLog::OPTION, 'garbage');
        $this->assertSame(0, (new ImgproxyFlapLog())->summary()['total']);
        update_option(ImgproxyFlapLog::OPTION, ['total' => 'x', 'events' => ['a', [], 5]]);
        $summary = (new ImgproxyFlapLog())->summary(10);
        $this->assertSame(0, $summary['total']);
        $this->assertSame(1, $summary['recent'], 'only the numeric event survives');
    }

    public function test_status_screen_shows_flaps_with_count_and_last_flip_time(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        $log = new ImgproxyFlapLog();
        $log->record('down', 1_800_000_000);
        $log->record('up', 1_800_000_600);

        $line = (new SettingsPage(null, null, null, null, $log))->buildFlapLine(1_800_001_000);

        $this->assertStringContainsString('2 in the last 24h', $line);
        $this->assertStringContainsString('2 in total', $line);
        $this->assertStringContainsString('flip to up', $line);
        $this->assertStringContainsString(gmdate('Y-m-d H:i', 1_800_000_600), $line);
    }

    public function test_status_screen_flap_line_stays_bounded_and_marks_a_capped_window(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        $log = new ImgproxyFlapLog();
        for ($i = 0; $i < 500; $i++) {
            $log->record($i % 2 === 0 ? 'down' : 'up', 1_800_000_000 + $i);
        }

        $line = (new SettingsPage(null, null, null, null, $log))->buildFlapLine(1_800_000_600);

        $this->assertStringContainsString('20+ in the last 24h', $line);
        $this->assertStringContainsString('500 in total', $line);
        $this->assertLessThan(200, strlen($line));
    }

    public function test_status_screen_is_silent_without_flaps_or_on_local_backend(): void
    {
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, 'https://imgproxy.example.com');
        $this->assertSame('', (new SettingsPage())->buildFlapLine());

        $log = new ImgproxyFlapLog();
        $log->record('down', 1_800_000_000);
        update_option(OptionSettingsRepository::OPTION_ENDPOINT, '');
        $this->assertSame('', (new SettingsPage(null, null, null, null, $log))->buildFlapLine());
    }
}
