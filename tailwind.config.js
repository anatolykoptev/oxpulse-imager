/**
 * Tailwind config for OXPulse Imager admin React SPA.
 *
 * SCOPED so it cannot bleed into the rest of wp-admin:
 *  - corePlugins.preflight: false — no global CSS reset
 *  - prefix: 'oxp-' — every utility class is oxp-*, no collision
 *  - important: '#oxpulse-admin-root' — utilities only apply inside
 *    the SPA's mount root
 *
 * Ported from UTM Linker (tailwind.config.js) with OXPulse-specific
 * tokens. Only src/admin/** is scanned for class names.
 */
module.exports = {
  prefix: 'oxp-',
  important: '#oxpulse-admin-root',
  corePlugins: {
    preflight: false,
  },
  content: ['./src/admin/**/*.{js,jsx}'],
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: '#2563eb',
          hover: '#1d4ed8',
          active: '#1e40af',
          soft: '#2563eb14',
        },
        accent: '#59a2ff',
        success: '#16a34a',
        danger: '#dc2626',
        warning: {
          DEFAULT: '#b45309',
          hover: '#92400e',
        },
        info: '#2563eb',
      },
      borderRadius: {
        sm: '6px',
        md: '8px',
        lg: '12px',
        pill: '9999px',
      },
      boxShadow: {
        card: '0 1px 2px rgb(16 24 40 / .06), 0 1px 3px rgb(16 24 40 / .10)',
        'card-hover': '0 4px 12px rgb(16 24 40 / .10)',
        shell: '0 1px 2px rgb(16 24 40 / .04), 0 12px 32px -8px rgb(16 24 40 / .14)',
        'focus-ring': '0 0 0 3px #1d4ed8cc',
      },
      fontFamily: {
        sans: [
          '-apple-system',
          'BlinkMacSystemFont',
          '"Segoe UI"',
          'Roboto',
          'Ubuntu',
          '"Helvetica Neue"',
          'sans-serif',
        ],
      },
      fontSize: {
        xs: ['12px', { lineHeight: '16px' }],
        sm: ['13px', { lineHeight: '18px' }],
        base: ['14px', { lineHeight: '20px' }],
        md: ['16px', { lineHeight: '24px' }],
        lg: ['20px', { lineHeight: '28px' }],
        xl: ['24px', { lineHeight: '32px' }],
      },
    },
  },
  plugins: [],
};
