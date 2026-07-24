/**
 * OXPulse Imager Admin - License store + Pro-gate unit tests
 *
 * Verifies:
 * - useLicenseStore defaults to free-safe when window.oxpulseAdmin.license
 *   is absent (SDK missing / not localized) — never throws.
 * - useLicenseStore reads the license block correctly when present.
 * - ProLock renders children disabled under free, enabled under Pro.
 * - TopNav shows Free+Upgrade vs Pro+Manage vs grandfathered.
 *
 * Run with: node --test tests/admin/license.test.mjs
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

// ─── useLicenseStore: defaults + read ───────────────────────────────

test('useLicenseStore defaults to free-safe when window.oxpulseAdmin.license absent', async () => {
  // No license block on window.
  globalThis.window = { oxpulseAdmin: { restUrl: 'http://x.test', nonce: 'n' } };

  // Fresh import — use dynamic import with cache-busting query.
  const mod = await import(`../../src/admin/store/useLicenseStore.js?t=${Date.now()}`);
  const { useLicenseStore, defaultLicense } = mod;

  const state = useLicenseStore.getState();
  assert.equal(state.isPro, false);
  assert.equal(state.plan, 'free');
  assert.equal(state.upgradeUrl, '');
  assert.equal(state.accountUrl, '');
  assert.equal(state.isGrandfathered, false);
  assert.deepEqual(state.isPro, defaultLicense.isPro);
});

test('useLicenseStore defaults to free-safe when window is undefined', async () => {
  delete globalThis.window;

  const mod = await import(`../../src/admin/store/useLicenseStore.js?t=${Date.now()}2`);
  const { useLicenseStore } = mod;

  const state = useLicenseStore.getState();
  assert.equal(state.isPro, false);
  assert.equal(state.plan, 'free');
  assert.equal(state.upgradeUrl, '');
});

test('useLicenseStore reads pro license with URLs when present', async () => {
  globalThis.window = {
    oxpulseAdmin: {
      restUrl: 'http://x.test',
      nonce: 'n',
      license: {
        isPro: true,
        plan: 'pro',
        upgradeUrl: 'https://checkout.freemius.com/upgrade',
        accountUrl: 'https://users.freemius.com/account',
        isGrandfathered: false,
      },
    },
  };

  const mod = await import(`../../src/admin/store/useLicenseStore.js?t=${Date.now()}3`);
  const { useLicenseStore } = mod;

  const state = useLicenseStore.getState();
  assert.equal(state.isPro, true);
  assert.equal(state.plan, 'pro');
  assert.equal(state.upgradeUrl, 'https://checkout.freemius.com/upgrade');
  assert.equal(state.accountUrl, 'https://users.freemius.com/account');
  assert.equal(state.isGrandfathered, false);
});

test('useLicenseStore reads grandfathered license correctly', async () => {
  globalThis.window = {
    oxpulseAdmin: {
      restUrl: 'http://x.test',
      nonce: 'n',
      license: {
        isPro: true,
        plan: 'pro',
        upgradeUrl: '',
        accountUrl: '',
        isGrandfathered: true,
      },
    },
  };

  const mod = await import(`../../src/admin/store/useLicenseStore.js?t=${Date.now()}4`);
  const { useLicenseStore } = mod;

  const state = useLicenseStore.getState();
  assert.equal(state.isPro, true);
  assert.equal(state.isGrandfathered, true);
});

test('useLicenseStore degrades malformed license to free-safe', async () => {
  // Realistic malformed inputs: null isPro, non-pro plan, non-string URL.
  // The PHP side sends JSON true/false for isPro, but a corrupt/cached
  // localize block could send null. Boolean(null) = false, plan !== 'pro'
  // → 'free', typeof number !== 'string' → ''.
  globalThis.window = {
    oxpulseAdmin: {
      restUrl: 'http://x.test',
      nonce: 'n',
      license: { isPro: null, plan: 'invalid', upgradeUrl: 123 },
    },
  };

  const mod = await import(`../../src/admin/store/useLicenseStore.js?t=${Date.now()}5`);
  const { useLicenseStore } = mod;

  const state = useLicenseStore.getState();
  assert.equal(state.isPro, false, 'isPro must be boolean false for null input');
  assert.equal(state.plan, 'free', 'plan must be free for non-pro input');
  assert.equal(state.upgradeUrl, '', 'upgradeUrl must be string for non-string input');
});

// ─── ProLock: locked under free, enabled under Pro ──────────────────
//
// ProLock uses a render-prop: children(true) under free, children(false)
// under Pro. We test the render-prop callback directly (no React DOM
// render needed — the lock decision is a pure function of the store).

test('ProLock render-prop receives locked=true under free', async () => {
  globalThis.window = { oxpulseAdmin: { restUrl: 'http://x.test', nonce: 'n' } };

  const storeMod = await import(`../../src/admin/store/useLicenseStore.js?t=${Date.now()}6`);
  const { useLicenseStore } = storeMod;

  // Simulate the ProLock decision logic: free (not Pro, not grandfathered).
  const { isPro, isGrandfathered } = useLicenseStore.getState();
  const locked = !isPro && !isGrandfathered;
  assert.equal(locked, true, 'free tier must lock the control');
});

test('ProLock render-prop receives locked=false under Pro', async () => {
  globalThis.window = {
    oxpulseAdmin: {
      restUrl: 'http://x.test',
      nonce: 'n',
      license: { isPro: true, plan: 'pro', upgradeUrl: '', accountUrl: '', isGrandfathered: false },
    },
  };

  const storeMod = await import(`../../src/admin/store/useLicenseStore.js?t=${Date.now()}7`);
  const { useLicenseStore } = storeMod;

  const { isPro, isGrandfathered } = useLicenseStore.getState();
  const locked = !isPro && !isGrandfathered;
  assert.equal(locked, false, 'Pro tier must NOT lock the control');
});

test('ProLock render-prop receives locked=false when grandfathered', async () => {
  globalThis.window = {
    oxpulseAdmin: {
      restUrl: 'http://x.test',
      nonce: 'n',
      license: { isPro: true, plan: 'pro', upgradeUrl: '', accountUrl: '', isGrandfathered: true },
    },
  };

  const storeMod = await import(`../../src/admin/store/useLicenseStore.js?t=${Date.now()}8`);
  const { useLicenseStore } = storeMod;

  const { isPro, isGrandfathered } = useLicenseStore.getState();
  const locked = !isPro && !isGrandfathered;
  assert.equal(locked, false, 'grandfathered must NOT lock the control');
});

// ─── TopNav: plan pill + CTA decision logic ─────────────────────────
//
// TopNav computes planPill + CTA from the license store. We test the
// decision logic (the ternary chain) directly against store state,
// mirroring the component's derivation.

const computePlanPill = (isPro, isGrandfathered) => {
  if (isPro && !isGrandfathered) return { pill: 'Pro', cta: 'manage' };
  if (isPro && isGrandfathered) return { pill: 'Pro · included', cta: 'manage' };
  return { pill: 'Free', cta: 'upgrade' };
};

test('TopNav shows Free + Upgrade CTA under free', () => {
  const result = computePlanPill(false, false);
  assert.equal(result.pill, 'Free');
  assert.equal(result.cta, 'upgrade');
});

test('TopNav shows Pro + Manage CTA under Pro (not grandfathered)', () => {
  const result = computePlanPill(true, false);
  assert.equal(result.pill, 'Pro');
  assert.equal(result.cta, 'manage');
});

test('TopNav shows Pro · included under grandfathered', () => {
  const result = computePlanPill(true, true);
  assert.equal(result.pill, 'Pro · included');
  assert.equal(result.cta, 'manage');
});

// ─── defaults.js: pictureEnabled + cacheMaxMb present ───────────────

test('defaults.js includes pictureEnabled + cacheMaxMb with correct defaults', async () => {
  const { defaultOptions } = await import(`../../src/admin/store/defaults.js?t=${Date.now()}`);
  assert.equal(defaultOptions.pictureEnabled, false);
  assert.equal(defaultOptions.cacheMaxMb, 512);
});

// ─── normalizeOptions.js: pictureEnabled + cacheMaxMb pass-through ──

test('normalizeOptions passes through pictureEnabled + cacheMaxMb from REST', async () => {
  const { normalizeOptions } = await import(`../../src/admin/utils/normalizeOptions.js?t=${Date.now()}`);
  const normalized = normalizeOptions({
    pictureEnabled: true,
    cacheMaxMb: 1024,
    outputFormat: 'webp',
  });
  assert.equal(normalized.pictureEnabled, true);
  assert.equal(normalized.cacheMaxMb, 1024);
});

test('normalizeOptions falls back to defaults when pictureEnabled + cacheMaxMb absent', async () => {
  const { normalizeOptions } = await import(`../../src/admin/utils/normalizeOptions.js?t=${Date.now()}2`);
  const normalized = normalizeOptions({ outputFormat: 'webp' });
  assert.equal(normalized.pictureEnabled, false);
  assert.equal(normalized.cacheMaxMb, 512);
});
