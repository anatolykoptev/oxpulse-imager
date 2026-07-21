/**
 * OXPulse Imager Admin - Textarea Component
 *
 * Multi-line text input. Used for allowed sources (one URL per line)
 * and DPR variants (comma-separated).
 */

import clsx from 'clsx';

const buildDescribedBy = (hasError, errorId, hasHelp, helpId) => {
  const ids = [];
  if (hasError) ids.push(errorId);
  if (hasHelp) ids.push(helpId);
  return ids.length > 0 ? ids.join(' ') : undefined;
};

const Textarea = ({
  label,
  value,
  onChange,
  placeholder = '',
  help = '',
  id,
  name,
  rows = 4,
  className = '',
  inputClassName = '',
  required = false,
  error = '',
  disabled = false,
  ...props
}) => {
  const inputId = id || `oxpulse-textarea-${name}`;
  const errorId = `${inputId}-error`;
  const helpId = `${inputId}-help`;
  const hasError = Boolean(error);
  const hasHelp = Boolean(help);

  const handleChange = (e) => {
    onChange(e.target.value, name);
  };

  return (
    <div className={clsx('oxp-mb-4', className)}>
      {label && (
        <label htmlFor={inputId} className="oxp-mb-1.5 oxp-block oxp-text-sm oxp-font-medium oxp-text-gray-700">
          {label}
          {required && <span className="oxp-ml-0.5 oxp-text-danger">*</span>}
        </label>
      )}

      <textarea
        id={inputId}
        name={name}
        value={value}
        onChange={handleChange}
        placeholder={placeholder}
        rows={rows}
        disabled={disabled}
        required={required}
        aria-invalid={hasError ? 'true' : undefined}
        aria-describedby={buildDescribedBy(hasError, errorId, hasHelp, helpId)}
        className={clsx(
          'oxp-w-full oxp-rounded-md oxp-border oxp-border-gray-300 oxp-px-3 oxp-py-2 oxp-text-sm oxp-text-gray-900',
          'placeholder:oxp-text-gray-400',
          'focus:oxp-border-primary focus:oxp-outline-none focus:oxp-shadow-focus-ring',
          disabled && 'oxp-cursor-not-allowed oxp-bg-gray-50 oxp-opacity-50',
          error && 'oxp-border-danger',
          inputClassName
        )}
        {...props}
      />

      {hasHelp && <p id={helpId} className="oxp-mt-1.5 oxp-text-sm oxp-text-gray-500">{help}</p>}
      {hasError && (
        <p id={errorId} role="alert" className="oxp-mt-1.5 oxp-text-sm oxp-text-danger">
          {error}
        </p>
      )}
    </div>
  );
};

export default Textarea;
