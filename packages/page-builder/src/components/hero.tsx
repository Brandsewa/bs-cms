import type { ComponentConfig } from '@puckeditor/core';

/** Props contract for the `Hero` block (mirrored by the PHP data migration). */
export interface HeroProps {
  heading: string;
  text: string;
  ctaLabel: string;
  ctaUrl: string;
}

/**
 * Full-width page opener: heading, intro copy and an optional call to action.
 * Rendered markup: `<section class="bs-hero">`.
 */
export const Hero: ComponentConfig<HeroProps> = {
  label: 'Hero',
  fields: {
    heading: { type: 'text', label: 'Heading' },
    text: { type: 'textarea', label: 'Text' },
    ctaLabel: { type: 'text', label: 'CTA label' },
    ctaUrl: { type: 'text', label: 'CTA URL' },
  },
  defaultProps: {
    heading: 'A quiet corner of the web',
    text: 'Stories, notes and guides — hand-published with a CMS that stays out of the way.',
    ctaLabel: 'Read the latest',
    ctaUrl: '/blog',
  },
  render: ({ id, heading, text, ctaLabel, ctaUrl }) => (
    <section id={id} className="bs-hero">
      <div className="bs-hero__inner">
        <h1 className="bs-hero__heading">{heading}</h1>
        {text ? <p className="bs-hero__text">{text}</p> : null}
        {ctaLabel ? (
          <a className="bs-hero__cta bs-btn" href={ctaUrl}>
            {ctaLabel}
          </a>
        ) : null}
      </div>
    </section>
  ),
};
