<?php
/**
 * Ranked, health-gated delivery backend registry.
 *
 * Holds an ordered list of DeliveryBackendProvider instances and
 * selects the best applicable, healthy one:
 *
 *   1. signing === null → null (short-circuit; no backend can sign).
 *   2. filter providers by isApplicable().
 *   3. sort by priority DESC (stable — input order breaks ties).
 *   4. select the first whose health() is selectable (not Down).
 *   5. call build() on the winner; memoize the result.
 *
 * The `oxpulse_delivery_backends` filter (applied in default()) is the
 * extension point: a third party adds/removes/reorders providers by
 * hooking the filter — ZERO edits to this class or the factory. Adding
 * a new backend = one provider class + one add_filter call.
 *
 * Memoization: one decision per registry instance (the factory result
 * is used once per call site today; the registry caches its selection
 * so repeated select() calls on the same instance return the same
 * backend).
 *
 * @package OXPulse\Imager\Application\Delivery
 * @copyright Copyright (c) 2026 Anatoly Koptev
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace OXPulse\Imager\Application\Delivery;

use OXPulse\Imager\Domain\Config\DeliveryConfig;
use OXPulse\Imager\Domain\Config\SigningConfig;
use OXPulse\Imager\Infrastructure\Image\ImageTransformer;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyBackendProvider;
use OXPulse\Imager\Infrastructure\Imgproxy\ImgproxyHealthCache;
use OXPulse\Imager\Infrastructure\Local\LocalBackendProvider;
use OXPulse\Imager\Infrastructure\Local\WpRemoteHttpRequester;

final class DeliveryBackendRegistry
{
    /** @var list<DeliveryBackendProvider> */
    private array $providers;

    /** Memoized selection result (null = not yet decided; the decision itself may be a null backend). */
    private bool $decided = false;
    private ?DeliveryBackend $selected = null;

    /**
     * @param DeliveryBackendProvider ...$providers Ordered provider list.
     */
    public function __construct(DeliveryBackendProvider ...$providers)
    {
        $this->providers = array_values($providers);
    }

    /**
     * Build the default registry: the 3 core providers (imgproxy →
     * local → passthrough) then apply the `oxpulse_delivery_backends`
     * filter so third parties can add/remove/reorder. This filter is
     * the extension point — adding a new backend requires NO edits to
     * the registry or factory.
     */
    public static function default(DeliveryConfig $config, ?SigningConfig $signing): self
    {
        $core = [
            new ImgproxyBackendProvider(new WpRemoteHttpRequester(), new ImgproxyHealthCache()),
            new LocalBackendProvider(new ImageTransformer()),
            new PassthroughBackendProvider(),
        ];

        /**
         * Filter the delivery backend provider list.
         *
         * Third parties add/remove/reorder providers here. A new
         * backend = one DeliveryBackendProvider class + one
         * add_filter('oxpulse_delivery_backends', ...) call.
         *
         * @param list<DeliveryBackendProvider> $providers
         * @param DeliveryConfig $config
         * @param SigningConfig|null $signing
         */
        $providers = apply_filters('oxpulse_delivery_backends', $core, $config, $signing);

        // A misbehaving filter that returns a non-array (null / scalar /
        // false) would otherwise trip a PHP 8 foreach-warning and drop
        // ALL providers including the passthrough floor. Fall back to
        // the unfiltered core providers. An empty array is a valid
        // "remove everything" intent and is left through.
        if (!is_array($providers)) {
            $providers = $core;
        }

        // Re-index + filter to only DeliveryBackendProvider instances
        // (a misbehaving filter callback must not corrupt the list).
        $clean = [];
        foreach ($providers as $p) {
            if ($p instanceof DeliveryBackendProvider) {
                $clean[] = $p;
            }
        }

        return new self(...$clean);
    }

    /**
     * Select the best applicable, healthy backend.
     *
     * @return DeliveryBackend|null The selected backend, or null when
     *         signing is null (short-circuit) or the winner's build()
     *         returns null (the passthrough floor — preserve original).
     */
    public function select(DeliveryConfig $config, ?SigningConfig $signing): ?DeliveryBackend
    {
        if ($this->decided) {
            return $this->selected;
        }

        // Short-circuit: no signing → no backend can sign → null.
        // Mirrors the pre-seam DeliveryBackendFactory guard.
        if ($signing === null) {
            $this->decided = true;
            $this->selected = null;
            return null;
        }

        // 1. Filter by applicability (config-presence only, no I/O).
        $applicable = array_filter(
            $this->providers,
            static fn(DeliveryBackendProvider $p): bool => $p->isApplicable($config, $signing),
        );

        // 2. Sort by priority DESC, stable (preserve input order on ties).
        //    PHP's usort is NOT stable pre-8.0; on 8.0+ it is stable,
        //    but array_multisort with a priority + index column is
        //    deterministically stable across versions.
        $priorities = [];
        $indices = [];
        foreach (array_values($applicable) as $i => $p) {
            $priorities[] = -$p->priority(); // DESC via negation.
            $indices[] = $i;
        }
        array_multisort($priorities, SORT_NUMERIC, $indices, SORT_NUMERIC, $applicable);

        // 3. First whose health() is selectable (not Down) → build.
        foreach ($applicable as $provider) {
            if ($provider->health($config)->selectable()) {
                $this->decided = true;
                $this->selected = $provider->build($config, $signing);
                return $this->selected;
            }
        }

        // No applicable+healthy provider (should not happen —
        // passthrough is always applicable + Healthy). Fail-safe null.
        $this->decided = true;
        $this->selected = null;
        return null;
    }
}
