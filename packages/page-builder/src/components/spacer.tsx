import type { ComponentConfig } from '@puckeditor/core';

/** Props contract for the `Spacer` block (mirrored by the PHP data migration). */
export interface SpacerProps {
  /** Vertical space in pixels. */
  height: number;
}

/**
 * Vertical whitespace block.
 * Rendered markup: `<div class="bs-spacer" style="height:{n}px">`.
 */
export const Spacer: ComponentConfig<SpacerProps> = {
  label: 'Spacer',
  fields: {
    height: { type: 'number', label: 'Height (px)', min: 0, max: 480, step: 4 },
  },
  defaultProps: {
    height: 64,
  },
  render: ({ height }) => (
    <div
      className="bs-spacer"
      style={{ height: `${height}px` }}
      aria-hidden="true"
    />
  ),
};
