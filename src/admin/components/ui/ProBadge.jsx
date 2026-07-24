/**
 * OXPulse Imager Admin - Pro Badge + Pro Lock primitives
 *
 * Two reusable primitives for the 5 Pro-gated controls:
 *
 *   <ProBadge />          — a small "PRO" pill next to a control's label.
 *                           Renders for everyone (Pro users see it too,
 *                           so the Pro feature set is visible), styled
 *                           green for active Pro / gray for locked-free.
 *
 *   <ProLock feature={…}> — a wrapper that, for a Pro feature under the
 *                           free tier (NOT grandfathered), renders its
 *                           children DISABLED + greyed with a one-line
 *                           upsell hint linking to upgradeUrl. Pro and
 *                           grandfathered users see the control normally.
 *
 * Both read the license once via useLicenseStore — no prop drilling.
 * The lock is UX ONLY: the backend isPro() gates (PR #110) are the real
 * enforcement; this wrapper never claims a Pro feature is active under
 * free, and never silently accepts a value the backend will ignore.
 *
 * @package OXPulse\Imager\Admin
 */

import clsx from 'clsx';
import { __ } from '@utils/i18n';
import { useLicenseStore } from '@store/useLicenseStore';

/**
 * Small "PRO" badge pill. Shown next to a Pro control's label for all
 * users (so the Pro feature set is discoverable). Green when the site
 * is Pro, gray when free.
 *
 * @param {{className?: string}} props
 */
export const ProBadge = ({ className = '' }) => {
  const isPro = useLicenseStore((s) => s.isPro);

  return (
    <span
      className={clsx(
        'oxp-inline-flex oxp-items-center oxp-rounded-pill oxp-border oxp-px-1.5 oxp-py-0 oxp-text-[10px] oxp-font-semibold oxp-uppercase oxp-leading-4 oxp-tracking-wide',
        isPro
          ? 'oxp-border-green-200 oxp-bg-green-50 oxp-text-success'
          : 'oxp-border-gray-300 oxp-bg-gray-50 oxp-text-gray-500',
        className
      )}
      title={isPro
        ? __('Pro feature (active)', 'oxpulse-imager')
        : __('Pro feature', 'oxpulse-imager')}
    >
      {__('PRO', 'oxpulse-imager')}
    </span>
  );
};

/**
 * Wrapper that locks a Pro control under the free tier.
 *
 * For Pro or grandfathered users: renders children unchanged (the
 * control works normally — no badge lock, no disabled state).
 *
 * For free users (isPro false, not grandfathered): renders children
 * inside a disabled/greyed wrapper with a one-line upsell hint. The
 * `disabled` prop is forwarded to the wrapped control via a render
 * prop pattern OR by cloning — see usage notes below.
 *
 * The upsell hint links to upgradeUrl (new tab). If upgradeUrl is empty
 * (SDK absent), the hint is plain text with no link — never a broken
 * href. The hint is a single line, no nag, no modal.
 *
 * Usage (render-prop form — preferred, the control receives disabled):
 *   <ProLock feature="avif">
 *     {(locked) => <SelectField ... disabled={locked} />}
 *   </ProLock>
 *
 * @param {{feature: string, children: ((locked: boolean) => React.ReactNode)|React.ReactNode, className?: string}} props
 */
export const ProLock = ({ feature, children, className = '' }) => {
  const isPro = useLicenseStore((s) => s.isPro);
  const isGrandfathered = useLicenseStore((s) => s.isGrandfathered);
  const upgradeUrl = useLicenseStore((s) => s.upgradeUrl);

  // Pro or grandfathered → control works normally, no lock.
  if (isPro || isGrandfathered) {
    return typeof children === 'function' ? children(false) : children;
  }

  // Free tier → lock the control + show the upsell hint.
  const hint = upgradeUrl
    ? (
      <a
        href={upgradeUrl}
        target="_blank"
        rel="noopener noreferrer"
        className="oxp-text-primary hover:oxp-underline"
      >
        {__('Available on Pro — Upgrade', 'oxpulse-imager')}
      </a>
    )
    : (
      <span className="oxp-text-gray-500">
        {__('Available on Pro', 'oxpulse-imager')}
      </span>
    );

  return (
    <div className={clsx('oxp-relative', className)} data-oxpulse-pro-lock={feature}>
      <div className="oxp-pointer-events-none oxp-opacity-50">
        {typeof children === 'function' ? children(true) : children}
      </div>
      <p className="oxp-mt-1.5 oxp-text-xs oxp-font-medium">
        {hint}
      </p>
    </div>
  );
};

export default ProBadge;
