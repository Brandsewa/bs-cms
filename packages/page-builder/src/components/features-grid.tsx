import type { ComponentConfig } from '@puckeditor/core';

/** One card inside a `FeaturesGrid` block. */
export interface FeatureItem {
  title: string;
  description: string;
}

/** Props contract for the `FeaturesGrid` block (mirrored by the PHP data migration). */
export interface FeaturesGridProps {
  /** Number of columns, serialized as a string select value ('2' | '3' | '4'). */
  columns: string;
  items: FeatureItem[];
}

const COLUMN_OPTIONS: { label: string; value: string }[] = [
  { label: '2 columns', value: '2' },
  { label: '3 columns', value: '3' },
  { label: '4 columns', value: '4' },
];

/**
 * Responsive grid of feature cards.
 * Rendered markup: `<section class="bs-features bs-features--{cols}">`.
 */
export const FeaturesGrid: ComponentConfig<FeaturesGridProps> = {
  label: 'Features grid',
  fields: {
    columns: { type: 'select', label: 'Columns', options: COLUMN_OPTIONS },
    items: {
      type: 'array',
      label: 'Features',
      arrayFields: {
        title: { type: 'text', label: 'Title' },
        description: { type: 'textarea', label: 'Description' },
      },
      defaultItemProps: { title: 'New feature', description: 'Describe the feature.' },
      getItemSummary: (item) => item.title,
    },
  },
  defaultProps: {
    columns: '3',
    items: [
      { title: 'Own your content', description: 'Everything is stored as plain, portable data.' },
      { title: 'Fast pages', description: 'Blocks render to semantic HTML with zero runtime bloat.' },
      { title: 'Easy editing', description: 'Authors compose pages visually in the admin editor.' },
    ],
  },
  render: ({ id, columns, items }) => (
    <section id={id} className={`bs-features bs-features--${columns}`}>
      <ul className="bs-features__grid">
        {items.map((item, index) => (
          <li key={index} className="bs-features__item">
            <h3 className="bs-features__title">{item.title}</h3>
            <p className="bs-features__description">{item.description}</p>
          </li>
        ))}
      </ul>
    </section>
  ),
};
