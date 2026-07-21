/**
 * OXPulse Imager Admin - Notification
 *
 * Auto-hiding toast/banner for save success/error feedback.
 */

import clsx from 'clsx';
import { useUIStore } from '@store/useUIStore';
import { IconCheck, IconAlert, IconInfo } from '@components/ui/icons';
import { __ } from '@utils/i18n';

const Notification = () => {
  const notification = useUIStore((s) => s.notification);
  const clearNotification = useUIStore((s) => s.clearNotification);

  if (!notification) {
    return null;
  }

  const styles = {
    success: 'oxp-bg-green-50 oxp-border-green-200 oxp-text-success',
    error: 'oxp-bg-red-50 oxp-border-red-200 oxp-text-danger',
    info: 'oxp-bg-blue-50 oxp-border-blue-200 oxp-text-info',
  };

  const icons = {
    success: <IconCheck className="oxp-h-5 oxp-w-5 oxp-flex-shrink-0" />,
    error: <IconAlert className="oxp-h-5 oxp-w-5 oxp-flex-shrink-0" />,
    info: <IconInfo className="oxp-h-5 oxp-w-5 oxp-flex-shrink-0" />,
  };

  return (
    <div
      role="alert"
      className={clsx(
        'oxp-fixed oxp-top-4 oxp-right-4 oxp-z-50 oxp-flex oxp-items-center oxp-gap-3 oxp-rounded-md oxp-border oxp-px-4 oxp-py-3 oxp-shadow-card-hover',
        styles[notification.type] || styles.info
      )}
    >
      {icons[notification.type] || icons.info}
      <p className="oxp-text-sm oxp-font-medium">{notification.message}</p>
      <button
        type="button"
        onClick={clearNotification}
        className="oxp-ml-2 oxp-text-current oxp-opacity-60 hover:oxp-opacity-100"
        aria-label={__('Dismiss', 'oxpulse-imager')}
      >
        ×
      </button>
    </div>
  );
};

export default Notification;
