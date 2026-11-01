import type { ComponentConfig } from '@puckeditor/core';

/** Props contract for the `ContactForm` component (contact-form plugin). */
export interface ContactFormProps {
  formHandle: string;
  heading: string;
  subheading: string;
}

/**
 * Embeds a published contact form (contact-form plugin). On the public site
 * the classic `embed.js` loader hydrates the `[data-bs-form]` placeholder:
 * it fetches the published form definition from
 * `/api/v1/public/forms/{handle}` and renders the interactive form there,
 * so the static page stays CSP-clean with zero build-time coupling.
 * Rendered markup: `<section class="bs-contact-form">`.
 */
export const ContactForm: ComponentConfig<ContactFormProps> = {
  label: 'Contact form',
  fields: {
    formHandle: { type: 'text', label: 'Form handle' },
    heading: { type: 'text', label: 'Heading' },
    subheading: { type: 'textarea', label: 'Subheading' },
  },
  defaultProps: {
    formHandle: 'contact-us',
    heading: 'Get in touch',
    subheading: 'We usually reply within one business day.',
  },
  render: ({ id, formHandle, heading, subheading }) => (
    <section id={id} className="bs-contact-form">
      <div className="bs-contact-form__inner">
        {heading ? <h2 className="bs-contact-form__heading">{heading}</h2> : null}
        {subheading ? <p className="bs-contact-form__subheading">{subheading}</p> : null}
        {/* embed.js replaces this placeholder with the interactive form;
            in the editor canvas it doubles as an honest preview. */}
        <div
          className="bs-contact-form__placeholder"
          data-bs-form={formHandle || 'contact-us'}
        >
          <p className="bs-contact-form__placeholder-title">Contact form</p>
          <p className="bs-contact-form__placeholder-handle">
            {formHandle ? `Embeds the published form “${formHandle}”` : 'Set a form handle to embed a published form'}
          </p>
        </div>
      </div>
    </section>
  ),
};
