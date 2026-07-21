/**
 * OXPulse Imager Admin - Select Field Component
 *
 * Radix Select — styled trigger + menu matching design tokens.
 */

import * as SelectPrimitive from '@radix-ui/react-select';
import clsx from 'clsx';
import { IconChevronDown } from '@components/ui/icons';

const SelectField = ({
  label,
  value,
  onChange,
  options = [],
  help = '',
  id,
  name,
  className = '',
  required = false,
  error = '',
  disabled = false,
}) => {
  const inputId = id || `oxpulse-select-${name}`;
  const selected = options.find((option) => option.value === value);

  return (
    <div className={clsx('oxp-mb-4', className)}>
      {label && (
        <label htmlFor={inputId} className="oxp-mb-1.5 oxp-block oxp-text-sm oxp-font-medium oxp-text-gray-700">
          {label}
          {required && <span className="oxp-ml-0.5 oxp-text-danger">*</span>}
        </label>
      )}

      <SelectPrimitive.Root
        value={value}
        onValueChange={(next) => onChange(next, name)}
        disabled={disabled}
        name={name}
      >
        <SelectPrimitive.Trigger
          id={inputId}
          className={clsx(
            'oxp-flex oxp-w-full oxp-max-w-lg oxp-items-center oxp-justify-between oxp-gap-2 oxp-rounded-md oxp-border oxp-border-gray-300 oxp-bg-white oxp-px-3 oxp-py-2 oxp-text-sm oxp-text-gray-900',
            'focus:oxp-border-primary focus:oxp-outline-none focus:oxp-shadow-focus-ring',
            disabled && 'oxp-cursor-not-allowed oxp-bg-gray-50 oxp-opacity-50',
            error && 'oxp-border-danger'
          )}
        >
          <SelectPrimitive.Value placeholder="…">{selected?.label}</SelectPrimitive.Value>
          <SelectPrimitive.Icon>
            <IconChevronDown className="oxp-h-4 oxp-w-4 oxp-text-gray-400" />
          </SelectPrimitive.Icon>
        </SelectPrimitive.Trigger>

        <SelectPrimitive.Portal>
          <SelectPrimitive.Content
            position="popper"
            sideOffset={4}
            className="oxp-z-50 oxp-overflow-hidden oxp-rounded-md oxp-border oxp-border-gray-200 oxp-bg-white oxp-shadow-card-hover"
          >
            <SelectPrimitive.Viewport className="oxp-p-1">
              {options.map((option) => (
                <SelectPrimitive.Item
                  key={option.value}
                  value={option.value}
                  disabled={option.disabled}
                  className={clsx(
                    'oxp-relative oxp-flex oxp-cursor-pointer oxp-select-none oxp-items-center oxp-rounded-sm oxp-px-3 oxp-py-1.5 oxp-text-sm oxp-text-gray-900 oxp-outline-none',
                    'data-[highlighted]:oxp-bg-primary-soft data-[highlighted]:oxp-text-primary',
                    'data-[disabled]:oxp-cursor-not-allowed data-[disabled]:oxp-opacity-50'
                  )}
                >
                  <SelectPrimitive.ItemText>{option.label}</SelectPrimitive.ItemText>
                </SelectPrimitive.Item>
              ))}
            </SelectPrimitive.Viewport>
          </SelectPrimitive.Content>
        </SelectPrimitive.Portal>
      </SelectPrimitive.Root>

      {help && <p className="oxp-mt-1.5 oxp-text-sm oxp-text-gray-500">{help}</p>}
      {error && <p className="oxp-mt-1.5 oxp-text-sm oxp-text-danger">{error}</p>}
    </div>
  );
};

export default SelectField;
