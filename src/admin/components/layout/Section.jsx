/**
 * OXPulse Imager Admin - Section layout component
 *
 * A `<section>` with an id (for scroll-spy nav) and an h2 heading.
 * Cards live inside sections.
 */

import clsx from 'clsx';

const Section = ({ id, title, description, children, className = '' }) => (
  <section
    id={id}
    className={clsx('oxp-scroll-mt-20 oxp-mb-8', className)}
  >
    <h2 className="oxp-text-lg oxp-font-semibold oxp-text-gray-900">{title}</h2>
    {description && <p className="oxp-mt-1 oxp-mb-4 oxp-text-sm oxp-text-gray-500">{description}</p>}
    <div className={clsx(!description && 'oxp-mt-4', 'oxp-space-y-4')}>
      {children}
    </div>
  </section>
);

export default Section;
