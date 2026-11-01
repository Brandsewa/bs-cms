export { Hero, type HeroProps } from './hero.js';
export { RichText, type RichTextProps } from './richtext.js';
export { Callout, type CalloutProps, type CalloutVariant } from './callout.js';
export { Faq, type FaqProps, type FaqItem } from './faq.js';
export { CtaBanner, type CtaBannerProps, type CtaAlign } from './cta-banner.js';
export { FeaturesGrid, type FeaturesGridProps, type FeatureItem } from './features-grid.js';
export { ImageBlock, defaultImageField, type ImageBlockProps } from './image-block.js';
export { Spacer, type SpacerProps } from './spacer.js';
export { ContactForm, type ContactFormProps } from './contact-form.js';

/**
 * Every page-builder component type name, keyed exactly as serialized into
 * Puck data. This list IS the data contract: content rows referencing other
 * type names are dropped by `sanitizePuckData`.
 */
export const componentTypes = [
  'Hero',
  'RichText',
  'Callout',
  'Faq',
  'CtaBanner',
  'FeaturesGrid',
  'ImageBlock',
  'Spacer',
  'ContactForm',
] as const;

export type ComponentType = (typeof componentTypes)[number];
