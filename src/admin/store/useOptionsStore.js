/**
 * OXPulse Imager Admin - Options Store (Zustand)
 *
 * Manages plugin options:
 * - Loading options from WordPress REST API
 * - Saving options via REST
 * - Tracking dirty state (unsaved changes)
 * - Handling loading/error states
 * - Blocking Save while any panel holds an invalid uncommitted field
 *   (fieldErrors — ported from UTM Linker's useOptionsStore)
 *
 * Imports are RELATIVE (not @utils/@store aliases) so this store is
 * importable directly under plain `node --test` for unit testing.
 */

import { create } from 'zustand';
import { getOptions, saveOptions as saveOptionsApi } from '../utils/api.js';
import { normalizeOptions } from '../utils/normalizeOptions.js';
import { defaultOptions } from './defaults.js';

export const useOptionsStore = create((set, get) => ({
  // State
  options: defaultOptions,
  isLoading: true,
  isSaving: false,
  error: null,
  isDirty: false,

  // Map of fieldKey -> human-readable error message. A non-empty map
  // means at least one panel holds an invalid uncommitted value —
  // saveOptions() BLOCKS entirely while this is non-empty, so an
  // in-progress invalid edit can never be silently dropped.
  fieldErrors: {},

  // Actions

  /**
   * Load options from the REST API.
   */
  loadOptions: async () => {
    set({ isLoading: true, error: null });

    try {
      const raw = await getOptions();
      const normalized = normalizeOptions(raw);
      set({ options: normalized, isLoading: false, isDirty: false, fieldErrors: {} });
    } catch (error) {
      set({ isLoading: false, error: error.message || 'Failed to load settings.' });
    }
  },

  /**
   * Update a single option field. Marks the store as dirty.
   *
   * @param {string} field  The option key (camelCase).
   * @param {*}      value  The new value.
   */
  setOption: (field, value) => {
    set((state) => ({
      options: { ...state.options, [field]: value },
      isDirty: true,
    }));
  },

  /**
   * Update multiple option fields at once. Marks the store as dirty.
   *
   * @param {Object} updates Partial options object to merge.
   */
  setOptions: (updates) => {
    set((state) => ({
      options: { ...state.options, ...updates },
      isDirty: true,
    }));
  },

  /**
   * Set/clear a field validation error. A non-empty fieldErrors map
   * blocks saveOptions().
   *
   * @param {string} field  The option key with the error.
   * @param {string|null} error  The error message, or null to clear.
   */
  setFieldError: (field, error) => {
    set((state) => {
      const fieldErrors = { ...state.fieldErrors };
      if (error) {
        fieldErrors[field] = error;
      } else {
        delete fieldErrors[field];
      }
      return { fieldErrors };
    });
  },

  /**
   * Save options to the REST API. BLOCKS if fieldErrors is non-empty
   * (returns without saving and sets a warning error).
   *
   * @return {Promise<boolean>} True if saved successfully, false otherwise.
   */
  saveOptions: async () => {
    const { fieldErrors, options } = get();

    if (Object.keys(fieldErrors).length > 0) {
      set({ error: 'Please fix the errors before saving.' });
      return false;
    }

    set({ isSaving: true, error: null });

    try {
      // Send only the fields the SPA manages (not secretStatus —
      // that's read-only from the REST perspective).
      const payload = { ...options };
      delete payload.secretStatus;

      const saved = await saveOptionsApi(payload);
      const normalized = normalizeOptions(saved);
      set({ options: normalized, isSaving: false, isDirty: false, fieldErrors: {} });
      return true;
    } catch (error) {
      // Validation errors from the server come as error.fieldErrors.
      if (error.fieldErrors) {
        set({ isSaving: false, error: error.message, fieldErrors: error.fieldErrors });
      } else {
        set({ isSaving: false, error: error.message || 'Failed to save settings.' });
      }
      return false;
    }
  },

  /**
   * Reset to the last-loaded state (discard unsaved changes).
   */
  reset: () => {
    set((state) => ({
      options: state.options, // Already the last-loaded state if not dirty
      isDirty: false,
      fieldErrors: {},
      error: null,
    }));
    // Reload from server to get the authoritative state.
    get().loadOptions();
  },
}));
