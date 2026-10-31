import type { ComponentConfig } from '@puckeditor/core';

export type CalloutVariant = 'info' | 'tip' | 'warning' | 'note';

/** Props contract for the `Callout` block (mirrored by the PHP data migration). */
export interface CalloutProps {
  variant: CalloutVariant;
  title: string;
  content: string;
}

const VARIANT_OPTIONS: { label: string; value: CalloutVariant }[] = [
  { label: 'Info', value: 'info' },
  { label: 'Tip', value: 'tip' },
  { label: 'Warning', value: 'warning' },
  { label: 'Note', value: 'note' },
];

/**
 * Highlighted aside for notes, tips and warnings.
 * Rendered markup: `<aside class="bs-callout bs-callout--{variant}">`.
 */
export const Callout: ComponentConfig<CalloutProps> = {
  label: 'Callout',
  fields: {
    variant: { type: 'select', label: 'Variant', options: VARIANT_OPTIONS },
    title: { type: 'text', label: 'Title' },
    content: { type: 'textarea', label: 'Content' },
  },
  defaultProps: {
    variant: 'tip',
    title: 'Good to know',
    content: 'You can highlight important context without breaking the reading flow.',
  },
  render: ({ variant, title, content }) => (
    <aside className={`bs-callout bs-callout--${variant}`}>
      {title ? <p className="bs-callout__title">{title}</p> : null}
      <div className="bs-callout__content">{content}</div>
    </aside>
  ),
};
