/**
 * OXPulse Imager Admin - Icons
 *
 * Inline SVG icons — the self-contained bundle carries no icon font
 * (WP Dashicons would render as empty boxes). Each icon is a small
 * functional component accepting a className for sizing.
 */

const baseProps = {
  viewBox: '0 0 20 20',
  fill: 'none',
  'aria-hidden': 'true',
};

export const IconChevronDown = ({ className = '' }) => (
  <svg {...baseProps} className={className}>
    <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" />
  </svg>
);

export const IconCheck = ({ className = '' }) => (
  <svg {...baseProps} className={className}>
    <path d="M4 10L8 14L16 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
  </svg>
);

export const IconAlert = ({ className = '' }) => (
  <svg {...baseProps} className={className}>
    <path d="M10 6V10M10 14V14.01" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
    <circle cx="10" cy="10" r="8" stroke="currentColor" strokeWidth="1.75" />
  </svg>
);

export const IconInfo = ({ className = '' }) => (
  <svg {...baseProps} className={className}>
    <path d="M10 8V14M10 6V6.01" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
    <circle cx="10" cy="10" r="8" stroke="currentColor" strokeWidth="1.75" />
  </svg>
);

export const IconSave = ({ className = '' }) => (
  <svg {...baseProps} className={className}>
    <path d="M4 4V16H16V7L13 4H4Z" stroke="currentColor" strokeWidth="1.75" strokeLinejoin="round" />
    <path d="M7 4V8H12V4" stroke="currentColor" strokeWidth="1.75" strokeLinejoin="round" />
    <path d="M7 12H13V16H7V12Z" stroke="currentColor" strokeWidth="1.75" strokeLinejoin="round" />
  </svg>
);
