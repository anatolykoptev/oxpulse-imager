<?php
/**
 * ServiceRegistrar REST-suspension wiring tests (#135).
 *
 * Verifies the media-route suspension hooks are registered with the
 * delivery adapters and that their callbacks suspend/resume delivery
 * around media REST callbacks — the guard that keeps proxied URLs out
 * of editor-saved post_content.
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\DeliverySuspension;
use PHPUnit\Framework\TestCase;

class ServiceRegistrarRestSuspensionTest extends TestCase
{
    protected function setUp(): void
    {
        DeliverySuspension::reset();
    }

    protected function tearDown(): void
    {
        DeliverySuspension::reset();
    }

    private function callbacksFor(string $hook): array
    {
        $found = [];
        foreach ($GLOBALS['__oxpulse_filters'] ?? [] as $entry) {
            if ($entry['hook'] === $hook) {
                $found[] = $entry['callback'];
            }
        }
        return $found;
    }

    public function test_before_callback_suspends_on_media_route_and_after_resumes(): void
    {
        // The wiring closures live in registerRestSuspension(); invoke it
        // via reflection so the test does not depend on the full
        // plugins_loaded bootstrap (deliveryEnabled etc.).
        $method = new \ReflectionMethod(
            \OXPulse\Imager\Infrastructure\WordPress\ServiceRegistrar::class,
            'registerRestSuspension'
        );
        $method->invoke(null);

        $before = $this->callbacksFor('rest_request_before_callbacks');
        $after = $this->callbacksFor('rest_request_after_callbacks');
        $this->assertCount(1, $before, 'before-callbacks hook not registered');
        $this->assertCount(1, $after, 'after-callbacks hook not registered');

        $media = new FakeRestRequest('/wp/v2/media/123');
        $posts = new FakeRestRequest('/wp/v2/posts/5');

        // Non-media route: untouched.
        $before[0](null, null, $posts);
        $this->assertFalse(DeliverySuspension::isSuspended());

        // Media route: suspended during callbacks, resumed after.
        $before[0](null, null, $media);
        $this->assertTrue(DeliverySuspension::isSuspended());
        $after[0](null, null, $media);
        $this->assertFalse(DeliverySuspension::isSuspended());

        // After on a non-media route never underflows.
        $after[0](null, null, $posts);
        $this->assertFalse(DeliverySuspension::isSuspended());
    }
}

/**
 * Minimal WP_REST_Request stand-in for route matching.
 */
class FakeRestRequest extends \WP_REST_Request
{
    public function __construct(private string $fakeRoute)
    {
    }

    public function get_route(): string
    {
        return $this->fakeRoute;
    }
}
