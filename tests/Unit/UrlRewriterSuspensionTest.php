<?php
/**
 * UrlRewriter delivery-suspension tests.
 *
 * While DeliverySuspension is active (REST media context), every
 * rewrite entrypoint must preserve the original URL — this is the
 * guard that keeps signed proxy URLs out of stored post_content
 * (#135).
 *
 * @package OXPulse\Imager
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Tests\Unit;

use OXPulse\Imager\Application\Delivery\DeliverySuspension;
use OXPulse\Imager\Application\Delivery\UrlRewriter;
use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Domain\Source\SourcePolicy;
use PHPUnit\Framework\TestCase;

class UrlRewriterSuspensionTest extends TestCase
{
    private const SRC = 'https://example.com/wp-content/uploads/photo.jpg';

    protected function tearDown(): void
    {
        DeliverySuspension::reset();
        parent::tearDown();
    }

    private function createRewriter(): UrlRewriter
    {
        return new UrlRewriter(
            new SourcePolicy(),
            new DeliveryConfig(
                enabled: true,
                endpoint: 'https://imgproxy.example.com',
                allowedSources: ['https://example.com/wp-content/uploads/'],
            ),
            SigningConfig::fromHex('736563726574', '68656C6C6F')
        );
    }

    public function test_rewrite_preserves_while_suspended(): void
    {
        DeliverySuspension::suspend();
        $result = $this->createRewriter()->rewrite(self::SRC, 300);

        $this->assertSame(self::SRC, $result->url);
        $this->assertSame('delivery_suspended', $result->reason);
    }

    public function test_social_rewrite_preserves_while_suspended(): void
    {
        DeliverySuspension::suspend();
        $result = $this->createRewriter()->rewriteSocialImage(self::SRC, 1200, 630);

        $this->assertSame(self::SRC, $result->url);
        $this->assertSame('delivery_suspended', $result->reason);
    }

    public function test_dpr_rewrite_preserves_while_suspended(): void
    {
        DeliverySuspension::suspend();
        $result = $this->createRewriter()->rewriteDpr(self::SRC, 300, 2.0);

        $this->assertSame(self::SRC, $result->url);
    }

    public function test_rewrite_resumes_after_balanced_resume(): void
    {
        DeliverySuspension::suspend();
        DeliverySuspension::suspend();
        DeliverySuspension::resume();
        // still nested-suspended
        $this->assertSame(self::SRC, $this->createRewriter()->rewrite(self::SRC, 300)->url);

        DeliverySuspension::resume();
        $result = $this->createRewriter()->rewrite(self::SRC, 300);
        $this->assertStringStartsWith('https://imgproxy.example.com/', $result->url);
    }
}
