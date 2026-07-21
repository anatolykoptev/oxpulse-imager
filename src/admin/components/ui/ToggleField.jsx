/**
 * OXPulse Imager Admin - Toggle Field Component
 *
 * Radix Switch under the hood — renders no native checkbox, which
 * eliminates the double-control bug (wp-admin's checkbox showing
 * next to the styled track). Ported from UTM Linker's ToggleField.
 */

import * as SwitchPrimitive from '@radix-ui/react-switch';
import clsx from 'clsx';

const ToggleField = ({
  label,
  checked,
  onChange,
  help = '',
  id,
  name,
  className = '',
  disabled = false,
  hideLabel = false,
  ...props
}) => {
  const inputId = id || `oxpulse-toggle-${name}`;

  const handleCheckedChange = (next) => {
    onChange(next, name);
  };

  return (
    <div
      className={clsx(
        'oxp-flex oxp-items-start oxp-gap-3',
        disabled && 'oxp-cursor-not-allowed oxp-opacity-50',
        className
      )}
    >
      <SwitchPrimitive.Root
        id={inputId}
        name={name}
        checked={checked}
        onCheckedChange={handleCheckedChange}
        disabled={disabled}
        className={clsx(
          'oxp-relative oxp-mt-0.5 oxp-inline-flex oxp-h-6 oxp-w-11 oxp-flex-shrink-0 oxp-cursor-pointer oxp-items-center oxp-rounded-pill oxp-border',
          'oxp-border-gray-500 oxp-bg-gray-300 data-[state=checked]:oxp-border-primary data-[state=checked]:oxp-bg-primary',
          'oxp-transition-colors oxp-duration-150 oxp-ease-in-out',
          'focus-visible:oxp-outline-none focus-visible:oxp-shadow-focus-ring',
          disabled && 'oxp-cursor-not-allowed'
        )}
        {...props}
      >
        <SwitchPrimitive.Thumb
          className={clsx(
            'oxp-block oxp-h-5 oxp-w-5 oxp-translate-x-0.5 oxp-rounded-pill oxp-bg-white oxp-shadow-sm',
            'oxp-transition-transform oxp-duration-150 oxp-ease-in-out',
            'data-[state=checked]:oxp-translate-x-[22px]'
          )}
        />
      </SwitchPrimitive.Root>

      {!hideLabel && (
        <div className="oxp-flex oxp-flex-col">
          <label htmlFor={inputId} className="oxp-cursor-pointer oxp-text-sm oxp-font-medium oxp-text-gray-800">
            {label}
          </label>
          {help && <p className="oxp-mt-0.5 oxp-text-sm oxp-text-gray-500">{help}</p>}
        </div>
      )}
    </div>
  );
};

export default ToggleField;
