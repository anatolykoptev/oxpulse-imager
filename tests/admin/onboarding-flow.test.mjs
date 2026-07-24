/**
 * OXPulse Imager Admin - Onboarding flow pure-helpers unit tests
 *
 * Verifies the free-first onboarding flow module:
 * - WIZARD_STEPS is the 3-step free-first sequence with NO imgproxy tokens.
 * - buildFinishOptions(true/false) → {enabled, onboarded} (immutability).
 * - buildSkipOptions() → {onboarded:true} ONLY (no forced enable).
 * - welcomeMessageKind(webpCapable) → 'ready' | 'unsupported'.
 * - planPill (real proGate helper) → cta 'upgrade' under free, 'manage' under Pro.
 *
 * Run with: node --test tests/admin/onboarding-flow.test.mjs
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
  WIZARD_STEPS,
  buildFinishOptions,
  buildSkipOptions,
  welcomeMessageKind,
} from '../../src/admin/utils/onboardingFlow.js';
import { planPill } from '../../src/admin/utils/proGate.js';

// ─── WIZARD_STEPS: 3 free-first steps, no imgproxy tokens ───────────

test('WIZARD_STEPS is the 3-step free-first sequence', () => {
  assert.deepEqual(WIZARD_STEPS, ['welcome', 'tuning', 'upgrade']);
});

test('WIZARD_STEPS contains none of the imgproxy step tokens', () => {
  const forbidden = ['endpoint', 'signing', 'health', 'avif', 'sources'];
  for (const token of forbidden) {
    assert.ok(
      !WIZARD_STEPS.includes(token),
      `WIZARD_STEPS must not contain imgproxy token "${token}"`
    );
  }
});

// ─── buildFinishOptions: immutable {enabled, onboarded} ─────────────

test('buildFinishOptions(true) → {enabled:true, onboarded:true}', () => {
  assert.deepEqual(buildFinishOptions(true), { enabled: true, onboarded: true });
});

test('buildFinishOptions(false) → {enabled:false, onboarded:true}', () => {
  assert.deepEqual(buildFinishOptions(false), { enabled: false, onboarded: true });
});

test('buildFinishOptions returns a NEW object each call (immutability)', () => {
  const a = buildFinishOptions(true);
  const b = buildFinishOptions(true);
  assert.notEqual(a, b, 'must return a fresh object, not a shared reference');
});

// ─── buildSkipOptions: {onboarded:true} ONLY, no enabled key ─────────

test('buildSkipOptions() → {onboarded:true} with NO enabled key', () => {
  const skip = buildSkipOptions();
  assert.deepEqual(skip, { onboarded: true });
  assert.ok(!('enabled' in skip), 'skip must NOT force-enable delivery');
});

test('buildSkipOptions returns a NEW object each call (immutability)', () => {
  const a = buildSkipOptions();
  const b = buildSkipOptions();
  assert.notEqual(a, b);
});

// ─── welcomeMessageKind: ready | unsupported ─────────────────────────

test('welcomeMessageKind(true) === "ready"', () => {
  assert.equal(welcomeMessageKind(true), 'ready');
});

test('welcomeMessageKind(false) === "unsupported"', () => {
  assert.equal(welcomeMessageKind(false), 'unsupported');
});

// ─── planPill (real proGate helper): cta routing ────────────────────

test('planPill free → cta "upgrade"', () => {
  assert.equal(planPill({ isPro: false, isGrandfathered: false }).cta, 'upgrade');
});

test('planPill Pro → cta "manage"', () => {
  assert.equal(planPill({ isPro: true, isGrandfathered: false }).cta, 'manage');
});
