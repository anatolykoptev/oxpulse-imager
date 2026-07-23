<?php
/**
 * AsyncPrewarmService tests.
 *
 * Tests job creation, batch processing, and job state transitions
 * using the transient stubs in bootstrap.php.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Application\Prewarm\AsyncPrewarmService;
use OXPulse\Imager\Application\Prewarm\PrewarmJobStore;
use OXPulse\Imager\Application\Prewarm\PrewarmService;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use PHPUnit\Framework\TestCase;

class AsyncPrewarmServiceTest extends TestCase
{
    private PrewarmJobStore $store;
    private AsyncPrewarmService $asyncService;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__oxpulse_transients'] = [];
        $GLOBALS['__oxpulse_scheduled_events'] = [];

        $delivery = new DeliveryConfig(
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

        $signing = new SigningConfig(
            hex2bin('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'),
            hex2bin('f6e5d4c3b2a1f6e5d4c3b2a1f6e5d4c3')
        );

        $rewriter = new UrlRewriter(new SourcePolicy(), $delivery, $signing);

        // Use a mock HTTP client that returns 200 for everything.
        $httpClient = new class implements \OXPulse\Imager\Application\Prewarm\PrewarmHttpClient {
            public function headBatch(array $imgproxyUrls, int $timeoutSeconds): array
            {
                return array_map(
                    fn () => ['status' => 200, 'error' => null, 'elapsed_ms' => 50],
                    $imgproxyUrls
                );
            }
        };

        $syncService = new PrewarmService($rewriter, $httpClient);
        $this->store = new PrewarmJobStore();
        $this->asyncService = new AsyncPrewarmService($syncService, $this->store);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__oxpulse_transients']);
        unset($GLOBALS['__oxpulse_scheduled_events']);
        parent::tearDown();
    }

    public function test_createJob_stores_pending_job_and_schedules_cron(): void
    {
        $jobId = $this->asyncService->createJob(
            ['https://example.com/uploads/photo.jpg'],
            [800]
        );

        $this->assertNotEmpty($jobId);

        $job = $this->store->get($jobId);
        $this->assertNotNull($job);
        $this->assertSame('pending', $job['status']);
        $this->assertCount(1, $job['urls']);
        $this->assertSame(0, $job['offset']);

        // Cron event should have been scheduled.
        $this->assertCount(1, $GLOBALS['__oxpulse_scheduled_events']);
        $this->assertSame(AsyncPrewarmService::CRON_HOOK, $GLOBALS['__oxpulse_scheduled_events'][0]['hook']);
        $this->assertSame($jobId, $GLOBALS['__oxpulse_scheduled_events'][0]['args'][0]);
    }

    public function test_processNextBatch_processes_small_job_to_completion(): void
    {
        $urls = array_map(
            fn ($i) => "https://example.com/uploads/photo{$i}.jpg",
            range(1, 10)
        );

        $jobId = $this->asyncService->createJob($urls, [0]);
        $this->asyncService->processNextBatch($jobId);

        $job = $this->store->get($jobId);
        $this->assertSame('complete', $job['status']);
        $this->assertSame(10, $job['total']);
        $this->assertSame(10, $job['warmed']);
        $this->assertSame(10, $job['offset']);
    }

    public function test_processNextBatch_processes_large_job_in_batches(): void
    {
        // Create 120 URLs — should take 3 batches (50 + 50 + 20).
        $urls = array_map(
            fn ($i) => "https://example.com/uploads/photo{$i}.jpg",
            range(1, 120)
        );

        $jobId = $this->asyncService->createJob($urls, [0]);

        // First batch.
        $this->asyncService->processNextBatch($jobId);
        $job = $this->store->get($jobId);
        $this->assertSame('running', $job['status']);
        $this->assertSame(50, $job['offset']);
        $this->assertSame(50, $job['warmed']);

        // Second batch.
        $this->asyncService->processNextBatch($jobId);
        $job = $this->store->get($jobId);
        $this->assertSame('running', $job['status']);
        $this->assertSame(100, $job['offset']);
        $this->assertSame(100, $job['warmed']);

        // Third batch.
        $this->asyncService->processNextBatch($jobId);
        $job = $this->store->get($jobId);
        $this->assertSame('complete', $job['status']);
        $this->assertSame(120, $job['offset']);
        $this->assertSame(120, $job['warmed']);
    }

    public function test_processNextBatch_schedules_next_batch_when_more_remain(): void
    {
        $urls = array_map(
            fn ($i) => "https://example.com/uploads/photo{$i}.jpg",
            range(1, 60)
        );

        $jobId = $this->asyncService->createJob($urls, [0]);

        // Clear the initial schedule event.
        $GLOBALS['__oxpulse_scheduled_events'] = [];

        $this->asyncService->processNextBatch($jobId);

        // Should have scheduled the next batch.
        $this->assertCount(1, $GLOBALS['__oxpulse_scheduled_events']);
        $this->assertSame($jobId, $GLOBALS['__oxpulse_scheduled_events'][0]['args'][0]);
    }

    public function test_processNextBatch_does_not_schedule_when_complete(): void
    {
        $jobId = $this->asyncService->createJob(
            ['https://example.com/uploads/photo.jpg'],
            [0]
        );

        $GLOBALS['__oxpulse_scheduled_events'] = [];

        $this->asyncService->processNextBatch($jobId);

        // Job is complete — no next batch should be scheduled.
        $this->assertCount(0, $GLOBALS['__oxpulse_scheduled_events']);
    }

    public function test_processNextBatch_returns_on_nonexistent_job(): void
    {
        // Should not throw.
        $this->asyncService->processNextBatch('nonexistent-job-id');
        $this->assertTrue(true);
    }

    public function test_processNextBatch_skips_already_complete_job(): void
    {
        $jobId = $this->asyncService->createJob(
            ['https://example.com/uploads/photo.jpg'],
            [0]
        );

        // Process to completion.
        $this->asyncService->processNextBatch($jobId);
        $job = $this->store->get($jobId);
        $this->assertSame('complete', $job['status']);

        // Process again — should be a no-op.
        $warmedBefore = $job['warmed'];
        $this->asyncService->processNextBatch($jobId);
        $job = $this->store->get($jobId);
        $this->assertSame($warmedBefore, $job['warmed']);
    }

    public function test_getJob_returns_null_for_nonexistent(): void
    {
        $this->assertNull($this->asyncService->getJob('nonexistent'));
    }

    public function test_job_id_is_uuid_format(): void
    {
        $jobId = $this->asyncService->createJob(
            ['https://example.com/uploads/photo.jpg'],
            [0]
        );

        // UUID v4 format: 8-4-4-4-12 hex chars.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $jobId
        );
    }

    // ─── #92: DISABLE_WP_CRON detection ──────────────────────────────

    public function test_isWpCronDisabled_returns_false_when_not_defined(): void
    {
        $this->assertFalse(AsyncPrewarmService::isWpCronDisabled());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_isWpCronDisabled_returns_true_when_disabled_true(): void
    {
        define('DISABLE_WP_CRON', true);
        $this->assertTrue(AsyncPrewarmService::isWpCronDisabled());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_isWpCronDisabled_returns_true_when_disabled_truthy_string(): void
    {
        // Some hosts write `define('DISABLE_WP_CRON', 'true');` — a
        // truthy string. WP core treats this as disabled; so must we.
        define('DISABLE_WP_CRON', 'true');
        $this->assertTrue(AsyncPrewarmService::isWpCronDisabled());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_isWpCronDisabled_returns_false_when_defined_false(): void
    {
        // `define('DISABLE_WP_CRON', false)` is NOT disabled — the
        // operator explicitly left cron enabled.
        define('DISABLE_WP_CRON', false);
        $this->assertFalse(AsyncPrewarmService::isWpCronDisabled());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_createJob_does_not_schedule_cron_when_disabled(): void
    {
        define('DISABLE_WP_CRON', true);

        // Re-build the service in this isolated process (setUp ran
        // before the define, but the check is in createJob, not the
        // constructor, so the existing instance is fine).
        $jobId = $this->asyncService->createJob(
            ['https://example.com/uploads/photo.jpg'],
            [0]
        );

        // Job record still created (harmless)...
        $job = $this->store->get($jobId);
        $this->assertNotNull($job);
        $this->assertSame('pending', $job['status']);

        // ...but NO cron event scheduled — a dead event that would
        // never fire is the silent no-op #92 fixes.
        $this->assertCount(0, $GLOBALS['__oxpulse_scheduled_events'] ?? []);
    }
}
