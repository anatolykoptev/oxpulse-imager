/**
 * OXPulse Imager Admin - Entry Point
 *
 * Mounts the React SPA into #oxpulse-admin-root. Guarded for
 * readyState (the script is enqueued in_footer, so DOMContentLoaded
 * may have already fired). If mounting throws, the mount-failure
 * notice script (SettingsPage.php) shows an actionable error.
 */

import { createRoot } from 'react-dom/client';
import React from 'react';
import App from './App';
import './styles/index.css';

const mount = () => {
  const rootElement = document.getElementById('oxpulse-admin-root');

  if (!rootElement) {
    return;
  }

  try {
    const root = createRoot(rootElement);
    root.render(
      <React.StrictMode>
        <App />
      </React.StrictMode>
    );
  } catch (error) {
    // Swallow — the mount-failure notice in SettingsPage.php watches
    // whether #oxpulse-admin-root ended up with children and shows
    // an actionable notice if not.
    console.error('OXPulse Imager admin: failed to mount', error);
  }
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount);
} else {
  mount();
}
