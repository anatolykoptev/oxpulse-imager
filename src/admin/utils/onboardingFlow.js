/**
 * OXPulse Imager Admin — pure onboarding-flow decision helpers.
 *
 * Stateless, framework-free decision functions shared by the
 * OnboardingWizard component and the unit tests. NO React, NO i18n,
 * NO store imports, NO alias imports — only relative paths + plain
 * JS — so this module is importable directly under `node --test`
 * (mirrors the testability constraint documented in proGate.js /
 * useLicenseStore.js). Tests exercise the REAL decision logic the
 * component uses, so they FAIL if the logic diverges.
 *
 * The component owns presentation (i18n labels, classNames); these
 * helpers own only the step list + the finish/skip option builders.
 *
 * Free-first: the wizard is a 3-step single flow for everyone. Fresh
 * installs are ~always free (LocalBackend auto-installs, zero config);
 * imgproxy config lives in the Pro-gated Settings sections, NOT here.
 *
 * @package OXPulse\Imager\Admin
 */

/**
 * The 3 free-first wizard steps. Contains NONE of the legacy imgproxy
 * step tokens (endpoint|signing|health|avif|sources) — those were the
 * 6-step imgproxy-first wizard replaced by this flow.
 *
 * @type {string[]}
 */
export const WIZARD_STEPS = ['welcome', 'tuning', 'upgrade'];

/**
 * Build the options payload persisted on "Finish". Enables delivery
 * (when the user opted in) and marks onboarding complete. Returns a
 * NEW object every call (immutability — never mutate the store's
 * existing options reference).
 *
 * @param {boolean} enable Whether to turn on delivery.
 * @return {{enabled: boolean, onboarded: true}}
 */
export const buildFinishOptions = (enable) => ({
  enabled: Boolean(enable),
  onboarded: true,
});

/**
 * Build the options payload persisted on Step-1 "Turn on optimization".
 * Enables delivery IMMEDIATELY (so the user who clicks the on-switch
 * actually gets optimization) WITHOUT marking onboarding complete —
 * the user still advances through Steps 2–3, and Finish later sets
 * {enabled:true, onboarded:true} (idempotent on `enabled`). Returns a
 * NEW object every call (immutability).
 *
 * @return {{enabled: true}}
 */
export const buildEnableOptions = () => ({ enabled: true });

/**
 * Build the options payload persisted on "Skip for now". Marks
 * onboarding complete ONLY — does NOT force-enable delivery (matches
 * the existing skip semantics: advanced users configure manually).
 * Returns a NEW object every call.
 *
 * @return {{onboarded: true}}
 */
export const buildSkipOptions = () => ({ onboarded: true });

/**
 * Pick the welcome-message kind for Step 1 based on the server's
 * WebP capability signal (localized as window.oxpulseAdmin.webpCapable
 * by SettingsPage::buildClientStatus()).
 *
 *   'ready'        → server can produce WebP; positive copy.
 *   'unsupported'  → server cannot produce WebP yet; non-blocking
 *                    heads-up (images served unchanged, nothing to do).
 *
 * @param {boolean} webpCapable
 * @return {'ready'|'unsupported'}
 */
export const welcomeMessageKind = (webpCapable) =>
  webpCapable ? 'ready' : 'unsupported';

export default { WIZARD_STEPS, buildEnableOptions, buildFinishOptions, buildSkipOptions, welcomeMessageKind };
