/**
 * OXPulse Imager Admin - UI Store (Zustand)
 *
 * Manages UI state: active section, notifications.
 */

import { create } from 'zustand';

export const useUIStore = create((set) => ({
  // State
  activeSection: 'connection',
  notification: null,

  // Actions

  /**
   * Passive update — driven by scroll-spy as the operator scrolls
   * past a section, so the nav's active indicator tracks what's
   * actually in view.
   */
  setActiveSection: (sectionId) => {
    set({ activeSection: sectionId });
  },

  /**
   * Active navigation — scrolls the target section into view and
   * updates the active indicator immediately. Honors
   * prefers-reduced-motion.
   */
  scrollToSection: (sectionId) => {
    if (typeof document !== 'undefined') {
      const target = document.getElementById(sectionId);
      if (target) {
        const prefersReducedMotion =
          typeof window !== 'undefined' &&
          typeof window.matchMedia === 'function' &&
          window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        target.scrollIntoView({
          behavior: prefersReducedMotion ? 'auto' : 'smooth',
          block: 'start',
        });

        target.setAttribute('tabindex', '-1');
        target.focus({ preventScroll: true });
        target.addEventListener(
          'blur',
          () => target.removeAttribute('tabindex'),
          { once: true }
        );
      }
    }

    set({ activeSection: sectionId });
  },

  /**
   * Show a notification (auto-hides after 5 seconds).
   *
   * @param {{type: 'success'|'error'|'info', message: string}} notification
   */
  showNotification: (notification) => {
    set({ notification });

    setTimeout(() => {
      set((state) => {
        if (state.notification === notification) {
          return { notification: null };
        }
        return {};
      });
    }, 5000);
  },

  clearNotification: () => {
    set({ notification: null });
  },
}));
