/**
 * OXPulse Imager Admin - Top Navigation
 *
 * Sticky header with section-anchor nav + Save button. The nav
 * scrolls to the chosen section instead of swapping which panel is
 * visible (single scrollable page IA, ported from UTM Linker v1.6.0).
 */

import clsx from 'clsx';
import { __ } from '@utils/i18n';
import { useUIStore } from '@store/useUIStore';
import { useOptionsStore } from '@store/useOptionsStore';
import { useLicenseStore } from '@store/useLicenseStore';
import { planPill as computePlanPill } from '@utils/proGate';
import Button from '@components/ui/Button';
import StatusPill from '@components/ui/StatusPill';
import { IconSave } from '@components/ui/icons';

const TopNav = ({ sections, version = '' }) => {
  const activeSection = useUIStore((s) => s.activeSection);
  const scrollToSection = useUIStore((s) => s.scrollToSection);
  const isDirty = useOptionsStore((s) => s.isDirty);
  const isSaving = useOptionsStore((s) => s.isSaving);
  const fieldErrors = useOptionsStore((s) => s.fieldErrors);
  const saveOptions = useOptionsStore((s) => s.saveOptions);
  const showNotification = useUIStore((s) => s.showNotification);

  // License state — read once, drives the plan pill + CTA. Mirrors the
  // backend isPro()/grandfather flag localized by SettingsPage. The
  // pill is a passive indicator (no nag); the CTA opens the Freemius
  // checkout/account URL in a new tab, or is hidden when the URL is
  // empty (SDK absent).
  const isPro = useLicenseStore((s) => s.isPro);
  const isGrandfathered = useLicenseStore((s) => s.isGrandfathered);
  const upgradeUrl = useLicenseStore((s) => s.upgradeUrl);
  const accountUrl = useLicenseStore((s) => s.accountUrl);

  const hasErrors = Object.keys(fieldErrors).length > 0;

  // Plan pill: green "Pro" for paying, neutral "Pro · included" for
  // grandfathered (pre-Freemius installs keep every feature — no
  // upsell), gray "Free" otherwise. The kind/cta decision lives in
  // the shared proGate helper (tested directly); this maps the kind
  // to the StatusPill status + i18n label.
  const { kind: planKind, cta: ctaKind } = computePlanPill({ isPro, isGrandfathered });
  const planPill = planKind === 'pro'
    ? { status: 'ok', label: __('Pro', 'oxpulse-imager') }
    : planKind === 'pro-included'
      ? { status: 'empty', label: __('Pro · included', 'oxpulse-imager') }
      : { status: 'empty', label: __('Free', 'oxpulse-imager') };

  const handleSave = async () => {
    const ok = await saveOptions();
    if (ok) {
      showNotification({ type: 'success', message: __('Settings saved.', 'oxpulse-imager') });
    } else {
      const { error } = useOptionsStore.getState();
      showNotification({
        type: 'error',
        message: error || __('Failed to save settings.', 'oxpulse-imager'),
      });
    }
  };

  return (
    <div className="oxp-sticky oxp-top-0 oxp-z-40 oxp-flex oxp-items-center oxp-justify-between oxp-gap-4 oxp-border-b oxp-border-gray-200 oxp-bg-white oxp-px-6 oxp-py-3">
      <nav className="oxp-flex oxp-items-center oxp-gap-1" aria-label={__('Settings sections', 'oxpulse-imager')}>
        {sections.map((section) => (
          <button
            key={section.id}
            type="button"
            onClick={() => scrollToSection(section.id)}
            className={clsx(
              'oxp-rounded-md oxp-px-3 oxp-py-1.5 oxp-text-sm oxp-font-medium oxp-transition-colors',
              activeSection === section.id
                ? 'oxp-bg-primary-soft oxp-text-primary'
                : 'oxp-text-gray-600 hover:oxp-bg-gray-100 hover:oxp-text-gray-900'
            )}
            aria-current={activeSection === section.id ? 'true' : undefined}
          >
            {section.label}
          </button>
        ))}
      </nav>

      <div className="oxp-flex oxp-items-center oxp-gap-3">
        <StatusPill status={planPill.status} label={planPill.label} />
        {ctaKind === 'manage' ? (
          accountUrl && (
            <a
              href={accountUrl}
              target="_blank"
              rel="noopener noreferrer"
              className="oxp-text-xs oxp-font-medium oxp-text-gray-500 hover:oxp-text-gray-700 hover:oxp-underline"
            >
              {__('Manage license', 'oxpulse-imager')}
            </a>
          )
        ) : (
          // Upgrade CTA rendered as an <a> (Button anchor variant) so
          // middle-click / open-in-new-tab / copy-link work — a
          // <button onClick={window.open}> cannot. Hidden when
          // upgradeUrl is empty (SDK absent).
          upgradeUrl && (
            <Button
              size="sm"
              variant="primary"
              href={upgradeUrl}
              target="_blank"
              rel="noopener noreferrer"
            >
              {__('Upgrade to Pro', 'oxpulse-imager')}
            </Button>
          )
        )}
        {version && (
          <span className="oxp-text-xs oxp-text-gray-400">v{version}</span>
        )}
        {isDirty && (
          <span className="oxp-text-xs oxp-text-warning">
            {__('Unsaved changes', 'oxpulse-imager')}
          </span>
        )}
        <Button
          onClick={handleSave}
          disabled={isSaving}
          variant={hasErrors ? 'secondary' : 'primary'}
          icon={<IconSave className="oxp-h-4 oxp-w-4" />}
        >
          {isSaving ? __('Saving…', 'oxpulse-imager') : __('Save', 'oxpulse-imager')}
        </Button>
      </div>
    </div>
  );
};

export default TopNav;
