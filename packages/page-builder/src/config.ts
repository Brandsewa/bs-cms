import type { Config } from '@puckeditor/core';
import {
  Callout,
  ContactForm,
  CtaBanner,
  Faq,
  FeaturesGrid,
  Hero,
  ImageBlock,
  RichText,
  Spacer,
} from './components/index.js';
import type { CreatePuckConfigOptions } from './types.js';

/**
 * Build a Puck config containing every page-builder component.
 *
 * Pass `{ imageField }` to swap the default URL text field on
 * `ImageBlock.src` for a custom image-picker field (e.g. a media-library
 * picker rendered by the admin). Everything else is fixed so that the
 * serialized data contract stays identical across consumers.
 */
export function createPuckConfig(options: CreatePuckConfigOptions = {}): Config {
  const imageBlock = options.imageField
    ? {
        ...ImageBlock,
        fields: { ...ImageBlock.fields, src: options.imageField },
      }
    : ImageBlock;

  return {
    components: {
      Hero,
      RichText,
      Callout,
      Faq,
      CtaBanner,
      FeaturesGrid,
      ImageBlock: imageBlock,
      Spacer,
      ContactForm,
    },
  };
}

/** Default config with every page-builder component and plain-text image field. */
export const puckConfig: Config = createPuckConfig();
