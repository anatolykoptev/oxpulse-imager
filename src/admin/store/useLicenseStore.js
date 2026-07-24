/**
 * OXPulse Imager Admin - License Store (Zustand)
 *
 * Reads the license state localized by SettingsPage::buildLicenseData()
 * onto `window.oxpulseAdmin.license` ONCE at store creation. The SPA
 * never re-fetches license state — it is server-rendered per request
 * (isPro reflects the current Freemius entitlement + grandfather flag
 * at the moment the admin page loaded).
 *
 * Degrades to a free-safe default when the `license` block is absent
 * (SDK missing / not localized / window undefined) — NEVER throws.
 * This is the SPA-side mirror of the PHP `function_exists('oxpulse_fs')`
 * guard: a deploy shipped without the Freemius SDK still gets a
 * working admin that reads as the free tier.
 *
 * Imports are RELATIVE (not @store aliases) so this store is importable
 * directly under plain `node --test` for unit testing (mirrors
 * useOptionsStore.js's testability constraint).
 *
 * @package OXPulse\Imager\Admin
 */

import { create } from 'zustand';

/**
 * Free-safe default license — used when window.oxpulseAdmin.license is
 * absent or malformed. Every field matches the PHP buildLicenseData()
 * free-safe fallback so the SPA and backend never disagree on the
 * default state.
 */
export const defaultLicense = {
  isPro: false,
  plan: 'free',
  upgradeUrl: '',
  accountUrl: '',
  isGrandfathered: false,
};

/**
 * Read the license block from window.oxpulseAdmin, validating shape.
 * Returns defaultLicense for any missing/malformed input — never throws.
 *
 * @return {object} A complete license object with all 5 fields.
 */
const readLicense = () => {
  if (typeof window === 'undefined' || !window.oxpulseAdmin || !window.oxpulseAdmin.license) {
    return { ...defaultLicense };
  }
  const raw = window.oxpulseAdmin.license;
  if (!raw || typeof raw !== 'object') {
    return { ...defaultLicense };
  }
  return {
    isPro: Boolean(raw.isPro),
    plan: raw.plan === 'pro' ? 'pro' : 'free',
    upgradeUrl: typeof raw.upgradeUrl === 'string' ? raw.upgradeUrl : '',
    accountUrl: typeof raw.accountUrl === 'string' ? raw.accountUrl : '',
    isGrandfathered: Boolean(raw.isGrandfathered),
  };
};

export const useLicenseStore = create(() => ({
  ...readLicense(),
}));

/**
 * Convenience selector hook: the full license object.
 * Components that need multiple fields use this once and destructure,
 * avoiding multiple store subscriptions.
 */
export const useLicense = () => useLicenseStore();
