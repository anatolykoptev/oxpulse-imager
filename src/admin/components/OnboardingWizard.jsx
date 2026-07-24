/**
 * OXPulse Imager Admin - Onboarding Wizard (free-first)
 *
 * First-run wizard shown when `options.onboarded === false`. Three
 * steps, a SINGLE flow for everyone (fresh installs are ~always free;
 * one conditional label on Step 3 serves the rare Pro-at-first-run):
 *   1. Welcome + turn on — copy switches on the server's WebP
 *      capability (window.oxpulseAdmin.webpCapable, localized by
 *      SettingsPage::buildClientStatus()). Primary CTA "Turn on
 *      optimization" (or "Continue" when WebP is unsupported)
 *      persists {enabled:true} immediately, then advances to Step 2.
 *   2. Optional tuning (skippable) — one number field bound to
 *      options.defaultQuality. "Next" + "Skip" both advance to Step 3.
 *   3. Pro upsell + finish — ProBadge + the Pro feature list. Free
 *      users get an Upgrade link (upgradeUrl, guarded when empty);
 *      Pro users get a "configure in Settings" note instead. Primary
 *      CTA "Finish" persists {enabled:true, onboarded:true}.
 *
 * "Skip for now" (header, all steps) persists {onboarded:true} ONLY —
 * does NOT force-enable delivery (matches the existing skip semantics
 * for advanced users who configure manually).
 *
 * imgproxy config (URL, signing key, health probe, AVIF check, allowed
 * sources) is NOT here — it lives in the Pro-gated ConnectionSection /
 * ToolsSection / FormatSection. The free tier is LocalBackend (WebP via
 * Imagick/GD), auto-installed on activation, zero config.
 */

import { useState, useCallback } from 'react';
import { __ } from '@utils/i18n';
import { useOptionsStore } from '@store/useOptionsStore';
import Button from '@components/ui/Button';
import TextField from '@components/ui/TextField';
import StatusPill from '@components/ui/StatusPill';
import { ProBadge } from '@components/ui/ProBadge';
import { useLicenseStore } from '@store/useLicenseStore';
import { planPill } from '@utils/proGate';
import {
  WIZARD_STEPS,
  buildEnableOptions,
  buildFinishOptions,
  buildSkipOptions,
  welcomeMessageKind,
} from '@utils/onboardingFlow';

const TOTAL_STEPS = WIZARD_STEPS.length;

// Capability free-safe (mirrors the existing window.oxpulseAdmin read
// pattern from useLicenseStore): degrade to false when the localize
// block is absent (SDK missing / not localized / window undefined) so
// the wizard never breaks — the unsupported heads-up is non-blocking.
const readWebpCapable = () =>
  typeof window !== 'undefined' && window.oxpulseAdmin
    ? Boolean(window.oxpulseAdmin.webpCapable)
    : false;

const StepHeader = ({ step, title, description }) => (
  <div className="oxp-mb-6">
    <div className="oxp-flex oxp-items-center oxp-gap-2 oxp-mb-2">
      {Array.from({ length: TOTAL_STEPS }, (_, i) => (
        <span
          key={i}
          className={
            i + 1 === step
              ? 'oxp-h-2 oxp-w-8 oxp-rounded-full oxp-bg-primary'
              : i + 1 < step
                ? 'oxp-h-2 oxp-w-8 oxp-rounded-full oxp-bg-success'
                : 'oxp-h-2 oxp-w-8 oxp-rounded-full oxp-bg-gray-200'
          }
        />
      ))}
    </div>
    <h2 className="oxp-text-xl oxp-font-semibold oxp-text-gray-900">
      {__('Step', 'oxpulse-imager')} {step}/{TOTAL_STEPS} — {title}
    </h2>
    {description && <p className="oxp-mt-1 oxp-text-sm oxp-text-gray-600">{description}</p>}
  </div>
);

const OnboardingWizard = () => {
  const options = useOptionsStore((s) => s.options);
  const setOption = useOptionsStore((s) => s.setOption);
  const setOptions = useOptionsStore((s) => s.setOptions);
  const saveOptions = useOptionsStore((s) => s.saveOptions);

  const [step, setStep] = useState(1);
  const [isWorking, setIsWorking] = useState(false);
  const [error, setError] = useState('');

  // Server-side WebP capability (localized by SettingsPage). Read once
  // at render — it does not change during the wizard session.
  const webpCapable = readWebpCapable();
  const messageKind = welcomeMessageKind(webpCapable);

  // License state for the Step 3 upsell (mirrors ProBadge / TopNav).
  const { isPro, isGrandfathered, upgradeUrl } = useLicenseStore();
  const cta = planPill({ isPro, isGrandfathered }).cta;

  // Step 1: "Turn on optimization" → persist {enabled:true} NOW (so
  // the user who clicked the on-switch actually gets optimization),
  // then advance to Step 2. Finish later sets {enabled:true,
  // onboarded:true} — idempotent on `enabled`. On save failure we
  // surface the error and stay on Step 1 (no advance on a failed
  // enable).
  const handleStep1Next = useCallback(async () => {
    setError('');
    setOptions(buildEnableOptions());
    setIsWorking(true);
    const ok = await saveOptions();
    setIsWorking(false);
    if (ok) {
      setStep(2);
    } else {
      setError(useOptionsStore.getState().error || __('Failed to enable optimization.', 'oxpulse-imager'));
    }
  }, [setOptions, saveOptions]);

  // Step 2: "Next" → advance (the defaultQuality field is already
  // bound to the store via setOption; it persists on Finish with the
  // rest of the options).
  const handleStep2Next = useCallback(() => {
    setError('');
    setStep(3);
  }, []);

  const handleStep2Skip = useCallback(() => {
    setError('');
    setStep(3);
  }, []);

  // Step 3: "Finish" → persist {enabled:true, onboarded:true}.
  const handleFinish = useCallback(async () => {
    setError('');
    setOptions(buildFinishOptions(true));
    setIsWorking(true);
    const ok = await saveOptions();
    setIsWorking(false);
    if (!ok) {
      setError(useOptionsStore.getState().error || __('Failed to enable delivery.', 'oxpulse-imager'));
    }
    // On success, the parent App re-renders (options.onboarded === true)
    // and unmounts the wizard automatically.
  }, [setOptions, saveOptions]);

  // Header "Skip for now" (all steps) → {onboarded:true} ONLY.
  const handleSkip = useCallback(async () => {
    setError('');
    setOptions(buildSkipOptions());
    setIsWorking(true);
    await saveOptions();
    setIsWorking(false);
  }, [setOptions, saveOptions]);

  const handleBack = useCallback(() => {
    setError('');
    setStep((s) => Math.max(1, s - 1));
  }, []);

  return (
    <div className="oxp-fixed oxp-inset-0 oxp-z-50 oxp-flex oxp-items-center oxp-justify-center oxp-bg-black/40">
      <div className="oxp-w-full oxp-max-w-2xl oxp-max-h-[90vh] oxp-overflow-y-auto oxp-rounded-lg oxp-bg-white oxp-shadow-xl">
        <div className="oxp-flex oxp-items-center oxp-justify-between oxp-border-b oxp-border-gray-200 oxp-px-6 oxp-py-4">
          <h1 className="oxp-text-lg oxp-font-semibold oxp-text-gray-900">
            {__('Welcome to OXPulse Imager', 'oxpulse-imager')}
          </h1>
          <button
            type="button"
            onClick={handleSkip}
            disabled={isWorking}
            className="oxp-text-sm oxp-text-gray-500 hover:oxp-text-gray-700 disabled:oxp-opacity-50"
          >
            {__('Skip for now', 'oxpulse-imager')}
          </button>
        </div>

        <div className="oxp-px-6 oxp-py-6">
          {step === 1 && (
            <>
              <StepHeader
                step={1}
                title={__('Welcome', 'oxpulse-imager')}
              />
              {messageKind === 'ready' ? (
                <div className="oxp-mb-4">
                  <p className="oxp-mb-3 oxp-text-sm oxp-text-gray-700">
                    {__(
                      'OXPulse Imager optimizes your images as WebP automatically — right on your server. No external service, no keys, no setup.',
                      'oxpulse-imager'
                    )}
                  </p>
                  <StatusPill
                    status="ok"
                    label={__('Ready — WebP supported', 'oxpulse-imager')}
                  />
                </div>
              ) : (
                <div className="oxp-mb-4">
                  <p className="oxp-mb-3 oxp-text-sm oxp-text-gray-700">
                    {__(
                      "Your server's image library can't produce WebP yet. Images are served unchanged until that's available — nothing else to do here.",
                      'oxpulse-imager'
                    )}
                  </p>
                  <StatusPill
                    status="warning"
                    label={__('WebP not available yet', 'oxpulse-imager')}
                  />
                </div>
              )}
            </>
          )}

          {step === 2 && (
            <>
              <StepHeader
                step={2}
                title={__('Tuning', 'oxpulse-imager')}
                description={__('Default quality 80 works for most sites.', 'oxpulse-imager')}
              />
              <TextField
                name="defaultQuality"
                type="number"
                label={__('Default quality', 'oxpulse-imager')}
                value={String(options.defaultQuality)}
                onChange={(v) => setOption('defaultQuality', parseInt(v, 10) || 80)}
              />
              <p className="oxp-mt-2 oxp-text-sm oxp-text-gray-600">
                {__('Fine-tune formats & per-format quality anytime in Settings → Format.', 'oxpulse-imager')}
              </p>
            </>
          )}

          {step === 3 && (
            <>
              <StepHeader
                step={3}
                title={__('Unlock Pro', 'oxpulse-imager')}
              />
              <div className="oxp-mb-4">
                <div className="oxp-mb-3 oxp-flex oxp-items-center oxp-gap-2">
                  <ProBadge />
                </div>
                <ul className="oxp-list-disc oxp-pl-5 oxp-text-sm oxp-text-gray-700 oxp-space-y-1">
                  <li>{__('AVIF (~30% smaller than WebP)', 'oxpulse-imager')}</li>
                  <li>{__('Self-hosted imgproxy delivery', 'oxpulse-imager')}</li>
                  <li>{__('<picture> element', 'oxpulse-imager')}</li>
                  <li>{__('Cache control', 'oxpulse-imager')}</li>
                </ul>
              </div>
              {cta === 'upgrade' ? (
                <div className="oxp-mb-4 oxp-flex oxp-items-center oxp-gap-3">
                  {upgradeUrl ? (
                    <a
                      href={upgradeUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="oxp-text-sm oxp-font-medium oxp-text-primary hover:oxp-underline"
                    >
                      {__('Upgrade', 'oxpulse-imager')}
                    </a>
                  ) : (
                    <span className="oxp-text-sm oxp-text-gray-500">
                      {__('Available on Pro', 'oxpulse-imager')}
                    </span>
                  )}
                </div>
              ) : (
                <p className="oxp-mb-4 oxp-text-sm oxp-text-gray-700">
                  {__(
                    "You're on Pro — configure AVIF & imgproxy in Settings → Connection",
                    'oxpulse-imager'
                  )}
                </p>
              )}
            </>
          )}

          {error && (
            <p role="alert" className="oxp-mt-4 oxp-text-sm oxp-text-danger">{error}</p>
          )}

          <div className="oxp-mt-6 oxp-flex oxp-items-center oxp-justify-between oxp-border-t oxp-border-gray-200 oxp-pt-4">
            <Button onClick={handleBack} variant="secondary" disabled={isWorking || step === 1}>
              {__('Back', 'oxpulse-imager')}
            </Button>
            <div className="oxp-flex oxp-items-center oxp-gap-2">
              {step === 1 && (
                <Button onClick={handleStep1Next} variant="primary" disabled={isWorking}>
                  {isWorking
                    ? __('Turning on…', 'oxpulse-imager')
                    : messageKind === 'ready'
                      ? __('Turn on optimization', 'oxpulse-imager')
                      : __('Continue', 'oxpulse-imager')}
                </Button>
              )}
              {step === 2 && (
                <>
                  <Button onClick={handleStep2Skip} variant="secondary" disabled={isWorking}>
                    {__('Skip', 'oxpulse-imager')}
                  </Button>
                  <Button onClick={handleStep2Next} variant="primary" disabled={isWorking}>
                    {__('Next', 'oxpulse-imager')}
                  </Button>
                </>
              )}
              {step === 3 && (
                <Button onClick={handleFinish} variant="primary" disabled={isWorking}>
                  {isWorking ? __('Finishing…', 'oxpulse-imager') : __('Finish', 'oxpulse-imager')}
                </Button>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default OnboardingWizard;
