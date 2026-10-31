import type { ComponentConfig, Field } from '@puckeditor/core';

/** Props contract for the `ImageBlock` (mirrored by the PHP data migration). */
export interface ImageBlockProps {
  src: string;
  alt: string;
  caption?: string;
}

/** Default field for `ImageBlock.src` when no custom image picker is injected. */
export const defaultImageField: Field<string> = {
  type: 'text',
  label: 'Image URL',
  placeholder: 'https://…',
};

/**
 * Standalone figure with optional caption. The `src` field is the injectable
 * image picker: pass `imageField` to `createPuckConfig` to swap the plain URL
 * text field for a media-library picker.
 * Rendered markup: `<figure class="bs-image">`.
 */
export const ImageBlock: ComponentConfig<ImageBlockProps> = {
  label: 'Image',
  fields: {
    src: defaultImageField,
    alt: { type: 'text', label: 'Alt text' },
    caption: { type: 'text', label: 'Caption (optional)' },
  },
  defaultProps: {
    src: '',
    alt: '',
    caption: '',
  },
  render: ({ id, src, alt, caption }) => (
    <figure id={id} className="bs-image">
      {src ? <img className="bs-image__img" src={src} alt={alt} loading="lazy" /> : null}
      {caption ? <figcaption className="bs-image__caption">{caption}</figcaption> : null}
    </figure>
  ),
};
