/**
 * OXPulse Imager Admin - Status Pill Component
 *
 * Small colored badge for showing signing status (configured / partial
 * / empty) or other binary states.
 */

import clsx from 'clsx';

const StatusPill = ({ status, label, className = '' }) => {
  const styles = {
    ok: 'oxp-bg-green-50 oxp-text-success oxp-border-green-200',
    warning: 'oxp-bg-amber-50 oxp-text-warning oxp-border-amber-200',
    empty: 'oxp-bg-gray-50 oxp-text-gray-500 oxp-border-gray-200',
    error: 'oxp-bg-red-50 oxp-text-danger oxp-border-red-200',
  };

  const styleClass = styles[status] || styles.empty;

  return (
    <span
      className={clsx(
        'oxp-inline-flex oxp-items-center oxp-rounded-pill oxp-border oxp-px-2.5 oxp-py-0.5 oxp-text-xs oxp-font-medium',
        styleClass,
        className
      )}
    >
      {label}
    </span>
  );
};

export default StatusPill;
