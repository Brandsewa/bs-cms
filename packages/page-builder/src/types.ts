import type { Data, Field } from '@puckeditor/core';

/**
 * Serialized page data as stored by the CMS and consumed by Puck.
 * Shape: `{ root: object, content: ComponentData[], zones?: Record<string, ComponentData[]> }`
 * where every `ComponentData` is `{ type, props }` and `props.id` is a string.
 */
export type PuckData = Data;

/**
 * A field definition for `ImageBlock.src`. The admin injects a custom
 * image-picker field (typically a Puck `custom` or `external` field backed by
 * the media library); when omitted, a plain URL text field is used.
 */
export type PuckFieldConfig = Field<string>;

/** Options for {@link createPuckConfig}. */
export interface CreatePuckConfigOptions {
  /**
   * Replaces the default plain text field on `ImageBlock.src` — e.g. a
   * media-library picker component registered by the admin.
   */
  imageField?: PuckFieldConfig;
}
