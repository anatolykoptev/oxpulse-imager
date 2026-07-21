/**
 * OXPulse Imager Admin - Card Component
 *
 * A plain static panel by default. The collapse chevron only appears
 * when a caller explicitly opts in via `collapsible` (Radix
 * Collapsible). Ported from UTM Linker's Card.jsx.
 */

import * as CollapsiblePrimitive from '@radix-ui/react-collapsible';
import { useId, useState } from 'react';
import clsx from 'clsx';

const ChevronIcon = ({ isOpen, className = '' }) => (
  <svg
    viewBox="0 0 20 20"
    fill="none"
    aria-hidden="true"
    className={clsx('oxp-h-4 oxp-w-4 oxp-transition-transform oxp-duration-150', isOpen && 'oxp-rotate-180', className)}
  >
    <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" />
  </svg>
);

const CardChrome = ({ title, description, children, headingLevel = 'h3', className = '' }) => {
  const HeadingTag = headingLevel;

  return (
    <div
      className={clsx(
        'oxp-rounded-lg oxp-border oxp-border-gray-200 oxp-bg-white oxp-p-5 oxp-shadow-sm sm:oxp-p-6',
        className
      )}
    >
      {(title || description) && (
        <div className="oxp-mb-4">
          {title && <HeadingTag className="oxp-text-md oxp-font-semibold oxp-text-gray-900">{title}</HeadingTag>}
          {description && <p className="oxp-mt-1 oxp-text-sm oxp-text-gray-500">{description}</p>}
        </div>
      )}
      {children}
    </div>
  );
};

const Card = ({ title, description, children, collapsible = false, defaultOpen = true, headingLevel = 'h3', className = '' }) => {
  const [isOpen, setIsOpen] = useState(defaultOpen);
  const contentId = useId();

  if (!collapsible) {
    return (
      <CardChrome title={title} description={description} headingLevel={headingLevel} className={className}>
        {children}
      </CardChrome>
    );
  }

  const HeadingTag = headingLevel;

  return (
    <CollapsiblePrimitive.Root
      open={isOpen}
      onOpenChange={setIsOpen}
      className={clsx(
        'oxp-rounded-lg oxp-border oxp-border-gray-200 oxp-bg-white oxp-p-5 oxp-shadow-sm sm:oxp-p-6',
        className
      )}
    >
      <div className="oxp-flex oxp-items-start oxp-justify-between oxp-gap-4">
        <div>
          {title && <HeadingTag className="oxp-text-md oxp-font-semibold oxp-text-gray-900">{title}</HeadingTag>}
          {description && <p className="oxp-mt-1 oxp-text-sm oxp-text-gray-500">{description}</p>}
        </div>

        <CollapsiblePrimitive.Trigger
          aria-controls={contentId}
          className={clsx(
            'oxp-shrink-0 oxp-rounded-md oxp-border-none oxp-bg-transparent oxp-p-1 oxp-text-gray-500 hover:oxp-bg-gray-100',
            'focus-visible:oxp-outline-none focus-visible:oxp-shadow-focus-ring'
          )}
          aria-label={isOpen ? 'Collapse section' : 'Expand section'}
        >
          <ChevronIcon isOpen={isOpen} />
        </CollapsiblePrimitive.Trigger>
      </div>

      <CollapsiblePrimitive.Content
        id={contentId}
        forceMount
        className={clsx('oxp-mt-4', !isOpen && 'oxp-hidden')}
      >
        {children}
      </CollapsiblePrimitive.Content>
    </CollapsiblePrimitive.Root>
  );
};

export default Card;
