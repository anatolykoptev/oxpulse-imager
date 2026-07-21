/**
 * OXPulse Imager Admin - Main App Component
 *
 * Single scrollable settings page with sticky section-anchor nav.
 * Sections: Connection · Format · Enhancements · Diagnostics · Tools.
 *
 * Ported from UTM Linker's App.jsx (v1.6.0 IA restructure) — one
 * full-bleed white surface, sticky TopNav with scroll-to-section nav.
 */

import { useEffect } from 'react';
import { __ } from '@utils/i18n';
import TopNav from '@components/layout/TopNav';
import Notification from '@components/layout/Notification';
import Section from '@components/layout/Section';
import ConnectionSection from '@sections/ConnectionSection';
import FormatSection from '@sections/FormatSection';
import EnhancementsSection from '@sections/EnhancementsSection';
import DiagnosticsSection from '@sections/DiagnosticsSection';
import ToolsSection from '@sections/ToolsSection';
import PrewarmSection from '@sections/PrewarmSection';
import { useOptionsStore } from '@store/useOptionsStore';

const SECTIONS = [
  { id: 'connection', label: __('Connection', 'oxpulse-imager') },
  { id: 'format', label: __('Format', 'oxpulse-imager') },
  { id: 'enhancements', label: __('Enhancements', 'oxpulse-imager') },
  { id: 'diagnostics', label: __('Diagnostics', 'oxpulse-imager') },
  { id: 'tools', label: __('Tools', 'oxpulse-imager') },
  { id: 'prewarm', label: __('Pre-warm', 'oxpulse-imager') },
];

const ADMIN_VERSION =
  (typeof window !== 'undefined' && window.oxpulseAdmin?.version) || '';

const App = () => {
  const isLoading = useOptionsStore((s) => s.isLoading);
  const error = useOptionsStore((s) => s.error);
  const loadOptions = useOptionsStore((s) => s.loadOptions);

  useEffect(() => {
    loadOptions();
  }, [loadOptions]);

  if (isLoading) {
    return (
      <div className="oxp-flex oxp-items-center oxp-justify-center oxp-py-20">
        <p className="oxp-text-sm oxp-text-gray-500">
          {__('Loading settings…', 'oxpulse-imager')}
        </p>
      </div>
    );
  }

  // If loading failed entirely, show an error state (the SPA can't
  // function without the initial GET). The mount-failure notice from
  // SettingsPage.php covers the case where the bundle itself failed
  // to load; this covers the case where the bundle loaded but the
  // REST GET failed.
  if (error && !useOptionsStore.getState().options) {
    return (
      <div className="oxp-flex oxp-items-center oxp-justify-center oxp-py-20">
        <div className="oxp-text-center">
          <p className="oxp-text-sm oxp-font-medium oxp-text-danger">{error}</p>
          <button
            type="button"
            onClick={() => loadOptions()}
            className="oxp-mt-3 oxp-text-sm oxp-text-primary hover:oxp-underline"
          >
            {__('Try again', 'oxpulse-imager')}
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="oxp-min-h-screen oxp-bg-white oxp-shadow-shell">
      <TopNav sections={SECTIONS} version={ADMIN_VERSION} />

      <div className="oxp-px-6 oxp-py-8">
        <Section
          id="connection"
          title={__('Connection', 'oxpulse-imager')}
          description={__('imgproxy endpoint, signing secrets, and allowed source origins.', 'oxpulse-imager')}
        >
          <ConnectionSection />
        </Section>

        <Section
          id="format"
          title={__('Format', 'oxpulse-imager')}
          description={__('Output format and quality settings.', 'oxpulse-imager')}
        >
          <FormatSection />
        </Section>

        <Section
          id="enhancements"
          title={__('Enhancements', 'oxpulse-imager')}
          description={__('imgproxy-native features: LQIP placeholders, DPR-aware srcset, watermark.', 'oxpulse-imager')}
        >
          <EnhancementsSection />
        </Section>

        <Section
          id="diagnostics"
          title={__('Diagnostics', 'oxpulse-imager')}
          description={__('Logging, development overrides, cleanup.', 'oxpulse-imager')}
        >
          <DiagnosticsSection />
        </Section>

        <Section
          id="tools"
          title={__('Tools', 'oxpulse-imager')}
          description={__('Health check and AVIF format verification.', 'oxpulse-imager')}
        >
          <ToolsSection />
        </Section>

        <Section
          id="prewarm"
          title={__('Pre-warm', 'oxpulse-imager')}
          description={__('Bulk pre-warm imgproxy cache for a batch of source image URLs.', 'oxpulse-imager')}
        >
          <PrewarmSection />
        </Section>
      </div>

      <Notification />
    </div>
  );
};

export default App;
