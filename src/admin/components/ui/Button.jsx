/**
 * OXPulse Imager Admin - Button Component
 *
 * Variants: primary (default), secondary, destructive.
 * Sizes: default, sm.
 *
 * `icon`/`iconAfter` take a rendered icon node (see ui/icons.jsx),
 * not a dashicon class name — the self-contained bundle carries no
 * icon font.
 *
 * Anchor variant: pass `href` to render an `<a>` (same classes) so the
 * control supports middle-click / open-in-new-tab / copy-link — a
 * `<button onClick={() => window.open()}>` cannot. `target`/`rel` are
 * forwarded via `...props`; callers set `target="_blank"
 * rel="noopener noreferrer"` for external links.
 */

import clsx from 'clsx';

const Button = ({
  children,
  onClick,
  type = 'button',
  variant = 'primary',
  size = 'default',
  disabled = false,
  icon = null,
  iconAfter = null,
  className = '',
  href = null,
  ...props
}) => {
  const base = 'oxp-inline-flex oxp-items-center oxp-justify-center oxp-gap-2 oxp-rounded-md oxp-font-medium oxp-transition-colors oxp-duration-150 focus:oxp-outline-none focus:oxp-shadow-focus-ring disabled:oxp-cursor-not-allowed disabled:oxp-opacity-50';

  const variants = {
    primary: 'oxp-bg-primary oxp-text-white hover:oxp-bg-primary-hover active:oxp-bg-primary-active',
    secondary: 'oxp-bg-white oxp-text-gray-700 oxp-border oxp-border-gray-300 hover:oxp-bg-gray-50',
    destructive: 'oxp-bg-danger oxp-text-white hover:oxp-bg-red-700',
  };

  const sizes = {
    default: 'oxp-px-4 oxp-py-2 oxp-text-sm',
    sm: 'oxp-px-3 oxp-py-1.5 oxp-text-xs',
  };

  const classes = clsx(base, variants[variant], sizes[size], className);

  if (href !== null) {
    return (
      <a
        href={href}
        onClick={onClick}
        className={classes}
        {...props}
      >
        {icon}
        {children}
        {iconAfter}
      </a>
    );
  }

  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      className={classes}
      {...props}
    >
      {icon}
      {children}
      {iconAfter}
    </button>
  );
};

export default Button;
