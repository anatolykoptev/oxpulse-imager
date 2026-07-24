/**
 * OXPulse Imager Admin — pure Pro-gate decision helpers.
 *
 * Stateless, framework-free decision functions shared by the React
 * components (ProLock, TopNav) and the unit tests. NO React, NO i18n,
 * NO store imports, NO alias imports — only relative paths + plain
 * JS — so this module is importable directly under `node --test`
 * (mirrors the testability constraint documented in
 * useLicenseStore.js). Tests exercise the REAL decision logic the
 * components use, so they FAIL if the logic diverges.
 *
 * The components own presentation (i18n labels, classNames); these
 * helpers own only the boolean/enum decision.
 *
 * @package OXPulse\Imager\Admin
 */

/**
 * Whether a Pro-gated control is LOCKED (rendered disabled + greyed)
 * for the given license state. Mirrors ProLock: locked when NOT Pro
 * AND NOT grandfathered. Pro and grandfathered both unlock.
 *
 * @param {{isPro: boolean, isGrandfathered: boolean}} license
 * @return {boolean} true when the control must be locked.
 */
export const isLocked = ({ isPro, isGrandfathered }) =>
  !isPro && !isGrandfathered;

/**
 * TopNav plan-pill + CTA decision. Returns a stable `kind` key (which
 * the component maps to a status + i18n label) and a `cta` kind
 * ('manage' | 'upgrade'). Mirrors the TopNav ternary chain:
 *  - Pro (not grandfathered)      → kind 'pro',        manage
 *  - Pro + grandfathered          → kind 'pro-included', manage
 *  - Free                         → kind 'free',       upgrade
 *
 * @param {{isPro: boolean, isGrandfathered: boolean}} license
 * @return {{kind: 'pro'|'pro-included'|'free', cta: 'manage'|'upgrade'}}
 */
export const planPill = ({ isPro, isGrandfathered }) => {
  if (isPro && !isGrandfathered) return { kind: 'pro', cta: 'manage' };
  if (isPro && isGrandfathered) return { kind: 'pro-included', cta: 'manage' };
  return { kind: 'free', cta: 'upgrade' };
};

export default { isLocked, planPill };
