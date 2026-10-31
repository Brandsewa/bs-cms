import type { ComponentConfig } from '@puckeditor/core';

/** Props contract for the `RichText` block (mirrored by the PHP data migration). */
export interface RichTextProps {
  /** Trusted, CMS-authored HTML. Rendered verbatim — same trust model as the legacy `html_code` field. */
  html: string;
}

/**
 * Free-form rich content authored in the CMS. The `html` string is inserted
 * verbatim via `dangerouslySetInnerHTML`; only CMS editors can set it.
 * Rendered markup: `<div class="bs-richtext">`.
 */
export const RichText: ComponentConfig<RichTextProps> = {
  label: 'Rich text',
  fields: {
    html: { type: 'textarea', label: 'HTML content' },
  },
  defaultProps: {
    html: '<p>Write something worth reading.</p>',
  },
  render: ({ html }) => (
    <div className="bs-richtext" dangerouslySetInnerHTML={{ __html: html }} />
  ),
};
