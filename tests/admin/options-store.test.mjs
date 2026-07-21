/**
 * OXPulse Imager Admin - Options Store unit tests
 *
 * Verifies the Zustand store's state transitions: load, set, save
 * blocking on fieldErrors, dirty tracking.
 *
 * Run with: node --test tests/admin/options-store.test.mjs
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

// The store imports from '../utils/api.js' which references `window`.
// Node has no window — stub it before importing.
globalThis.window = {
  oxpulseAdmin: {
    restUrl: 'http://example.test/wp-json/oxpulse/v1/options',
    nonce: 'test-nonce',
  },
};

// Stub fetch — the store calls fetch() on load/save.
const fetchCalls = [];
globalThis.fetch = async (url, opts) => {
  fetchCalls.push({ url, opts });
  // Default: return the default options.
  const response = {
    ok: true,
    json: async () => ({
      enabled: false,
      endpoint: 'https://imgproxy.example.com',
      allowedSources: ['https://example.com/uploads/'],
      outputFormat: 'auto',
      defaultQuality: 80,
      formatQuality: {},
      lqipEnabled: false,
      lqipBlur: 1,
      dprEnabled: false,
      dprVariants: [1, 2, 3],
      watermark: null,
      diagnosticLevel: 'off',
      devHttpOverride: false,
      removeOnUninstall: false,
      secretStatus: 'empty',
    }),
  };
  return response;
};

const { useOptionsStore } = await import('../../src/admin/store/useOptionsStore.js');

test('store starts with default options and isLoading=true', () => {
  const state = useOptionsStore.getState();
  assert.equal(state.isLoading, true);
  assert.equal(state.options.enabled, false);
  assert.equal(state.options.defaultQuality, 80);
  assert.deepEqual(state.options.dprVariants, [1, 2, 3]);
  assert.equal(state.isDirty, false);
});

test('setOption updates a field and marks dirty', () => {
  useOptionsStore.getState().setOption('enabled', true);
  const state = useOptionsStore.getState();
  assert.equal(state.options.enabled, true);
  assert.equal(state.isDirty, true);
});

test('setOptions merges multiple fields and marks dirty', () => {
  useOptionsStore.getState().setOptions({ endpoint: 'https://new.example.com', defaultQuality: 75 });
  const state = useOptionsStore.getState();
  assert.equal(state.options.endpoint, 'https://new.example.com');
  assert.equal(state.options.defaultQuality, 75);
  assert.equal(state.isDirty, true);
});

test('setFieldError adds and removes errors', () => {
  useOptionsStore.getState().setFieldError('endpoint', 'Invalid URL');
  assert.equal(Object.keys(useOptionsStore.getState().fieldErrors).length, 1);

  useOptionsStore.getState().setFieldError('endpoint', null);
  assert.equal(Object.keys(useOptionsStore.getState().fieldErrors).length, 0);
});

test('saveOptions blocks when fieldErrors is non-empty', async () => {
  useOptionsStore.getState().setFieldError('endpoint', 'Invalid URL');
  const fetchCountBefore = fetchCalls.length;
  const result = await useOptionsStore.getState().saveOptions();
  assert.equal(result, false);
  assert.equal(fetchCalls.length, fetchCountBefore, 'fetch should not be called when fieldErrors exist');
  useOptionsStore.getState().setFieldError('endpoint', null);
});

test('loadOptions fetches and normalizes options from REST', async () => {
  await useOptionsStore.getState().loadOptions();
  const state = useOptionsStore.getState();
  assert.equal(state.isLoading, false);
  assert.equal(state.options.endpoint, 'https://imgproxy.example.com');
  assert.equal(state.options.secretStatus, 'empty');
  assert.equal(state.isDirty, false);
  assert.equal(state.error, null);
});
