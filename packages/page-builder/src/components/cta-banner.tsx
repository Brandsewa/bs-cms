import type { ComponentConfig } from '@puckeditor/core';

export type CtaAlign = 'left' | 'center';

/** Props contract for the `CtaBanner` block (mirrored by the PHP data migration). */
export interface CtaBannerProps {
  heading: string;
  text: string;
  buttonLabel: string;
  buttonUrl: string;
  align: CtaAlign;
}

const ALIGN_OPTIONS: { label: string; value: CtaAlign }[] = [
  { label: 'Left', value: 'left' },
  { label: 'Center', value: 'center' },
];

/**
 * Promotional banner with a single button.
 * Rendered markup: `<section class="bs-cta bs-cta--{align}">`.
 */
export const CtaBanner: ComponentConfig<CtaBannerProps> = {
  label: 'CTA banner',
  fields: {
    heading: { type: 'text', label: 'Heading' },
    text: { type: 'textarea', label: 'Text' },
    buttonLabel: { type: 'text', label: 'Button label' },
    buttonUrl: { type: 'text', label: 'Button URL' },
    align: { type: 'select', label: 'Alignment', options: ALIGN_OPTIONS },
  },
  defaultProps: {
    heading: 'Start your own site',
    text: 'Publish faster with a page builder that renders plain, fast HTML.',
    buttonLabel: 'Get started',
    buttonUrl: '/signup',
    align: 'center',
  },
  render: ({ id, heading, text, buttonLabel, buttonUrl, align }) => (
    <section id={id} className={`bs-cta bs-cta--${align}`}>
      <div className="bs-cta__inner">
        <h2 className="bs-cta__heading">{heading}</h2>
        {text ? <p className="bs-cta__text">{text}</p> : null}
        {buttonLabel ? (
          <a className="bs-cta__button bs-btn" href={buttonUrl}>
            {buttonLabel}
          </a>
        ) : null}
      </div>
    </section>
  ),
};
