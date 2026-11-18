<?php
/**
 * ServiceRegistrar async-prewarm cron wiring tests.
 *
 * The bootstrap path must stay cheap: registerAsyncPrewarmCron()
 * registers ONLY a lightweight callback — delivery/signing config
 * reads and the rewriter/backend construction are deferred until the
 * cron hook actually fires.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Prewarm\AsyncPrewarmService;
use OXPulse\Imager\Application\Prewarm\PrewarmJobStore;
use OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar;
use PHPUnit\Framework\TestCase;

class ServiceRegistrarAsyncPrewarmCronTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__oxpulse_options'] = [];
        $GLOBALS['__oxpulse_actions'] = [];
        $GLOBALS['__oxpulse_filters'] = [];
        $GLOBALS['__oxpulse_transients'] = [];
        $GLOBALS['__oxpulse_did_action'] = [];
        $GLOBALS['__oxpulse_scheduled_events'] = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__oxpulse_options'],
            $GLOBALS['__oxpulse_actions'],
            $GLOBALS['__oxpulse_filters'],
            $GLOBALS['__oxpulse_transients'],
            $GLOBALS['__oxpulse_did_action'],
            $GLOBALS['__oxpulse_scheduled_events']
        );
    }

    private function buildPluginStub(): \OXPulse\Imager\Plugin
    {
        $ref = new \ReflectionClass(\OXPulse\Imager\Plugin::class);
        return $ref->newInstanceWithoutConstructor();
    }

    private function invokePrivate(string $method, array $args = []): void
    {
        $ref = new \ReflectionMethod(ServiceRegistrar::class, $method);
        $ref->setAccessible(true);
        $ref->invoke(null, ...$args);
    }

    /**
     * B1a: registration performs zero get_option reads — the delivery
     * and signing config are loaded inside the cron callback, not at
     * bootstrap — and no rewriter is constructed (it cannot be built
     * without those reads).
     */
    public function test_registration_does_no_option_reads(): void
    {
        $plugin = $this->buildPluginStub();

        $GLOBALS['__oxpulse_get_option_calls'] = 0;
        $this->invokePrivate('registerAsyncPrewarmCron', [$plugin]);

        $this->assertSame(
            0,
            $GLOBALS['__oxpulse_get_option_calls'],
            'registering the cron callback must not read delivery/signing config at bootstrap',
        );
        $this->assertNotFalse(
            has_action(AsyncPrewarmService::CRON_HOOK),
            'the cron hook must have a registered callback',
        );
    }

    /**
     * B1b: firing the hook lazily builds the stack and processes an
     * existing job.
     */
    public function test_cron_hook_processes_existing_job_lazily(): void
    {
        $plugin = $this->buildPluginStub();
        $this->invokePrivate('registerAsyncPrewarmCron', [$plugin]);

        $store = new PrewarmJobStore();
        $store->create('job-b1', [], []);

        do_action(AsyncPrewarmService::CRON_HOOK, 'job-b1');

        $job = $store->get('job-b1');
        $this->assertSame(
            'complete',
            $job['status'],
            'firing the cron hook must build the stack lazily and process the job',
        );
    }
}
