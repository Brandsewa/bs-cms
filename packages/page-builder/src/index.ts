/**
 * @bs/page-builder — shared Puck page components for BS CMS.
 *
 * Single source of truth for the visual-editor blocks used by the admin
 * editor (React canvas) and the Astro starter (static rendering). Consumers
 * import the config/factories from this package and `styles.css` via the
 * `@bs/page-builder/styles.css` subpath export.
 */

// Component configs (the serialized data contract)
export {
  Hero,
  type HeroProps,
  RichText,
  type RichTextProps,
  Callout,
  type CalloutProps,
  type CalloutVariant,
  Faq,
  type FaqProps,
  type FaqItem,
  CtaBanner,
  type CtaBannerProps,
  type CtaAlign,
  FeaturesGrid,
  type FeaturesGridProps,
  type FeatureItem,
  ImageBlock,
  defaultImageField,
  type ImageBlockProps,
  Spacer,
  type SpacerProps,
  componentTypes,
  type ComponentType,
} from './components/index.js';

// Config + rendering
export { createPuckConfig, puckConfig } from './config.js';
export { sanitizePuckData } from './sanitize.js';
export { renderPuckToHtml } from './render.js';

// Types
export { type PuckData, type PuckFieldConfig, type CreatePuckConfigOptions } from './types.js';

// Islands (Astro hydration convention)
export {
  defineIsland,
  ISLAND_ATTRIBUTE,
  type BsIsland,
  type IslandComponent,
} from './island.js';
