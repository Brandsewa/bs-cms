import { useCallback, useMemo, useState } from 'react';
import type { ReactElement } from 'react';
import { Puck, Render } from '@puckeditor/core';
import type { Field } from '@puckeditor/core';
import { createPuckConfig, sanitizePuckData } from '@bs/page-builder';
import type { PuckData } from '@bs/page-builder';
// Canvas styles for the page components + Puck's own editor chrome.
// `no-external.css` avoids the remote @font @import of puck.css (CSP: style-src).
import '@bs/page-builder/styles.css';
import '@puckeditor/core/no-external.css';
import './puck-theme.css';
import { Button } from '@bs/ui';
import { ImagePlus } from 'lucide-react';
import { MediaPickerModal, type MediaItem } from '../media/media-picker-modal';

/**
 * Lazy chunk module: everything that touches the Puck runtime lives here.
 * Imported only through the `puck-page-editor` wrapper (React.lazy), so
 * posts and other collections never download the page builder.
 */

/** Public media URL for a picked asset (mirrors MediaPickerModal previews). */
function resolveMediaUrl(item: MediaItem): string {
  return item.url || `/api/v1/public/media/${item.id}/file`;
}

interface MediaImageFieldProps {
  value?: string;
  onChange: (value: string) => void;
  readOnly?: boolean;
}

/**
 * Custom Puck field for `ImageBlock.src`: opens the admin media library,
 * shows the current asset with replace/clear actions.
 */
export function MediaImageField({ value, onChange, readOnly }: MediaImageFieldProps) {
  const [open, setOpen] = useState(false);

  return (
    <div className="space-y-2">
      {value ? (
        <div className="flex items-start gap-3">
          <img
            src={value}
            alt=""
            className="h-16 w-16 shrink-0 rounded-md border border-border bg-surface-100 object-cover"
          />
          <div className="flex min-w-0 flex-col gap-1.5">
            <span className="max-w-[220px] truncate text-[11px] text-muted-foreground">
              {value}
            </span>
            <div className="flex gap-2">
              <Button
                type="button"
                size="sm"
                variant="outline"
                className="h-7 text-xs"
                disabled={readOnly}
                onClick={() => setOpen(true)}
              >
                Replace
              </Button>
              <Button
                type="button"
                size="sm"
                variant="ghost"
                className="h-7 text-xs text-destructive hover:text-destructive"
                disabled={readOnly}
                onClick={() => onChange('')}
              >
                Clear
              </Button>
            </div>
          </div>
        </div>
      ) : (
        <Button
          type="button"
          size="sm"
          variant="outline"
          className="h-7 text-xs"
          disabled={readOnly}
          onClick={() => setOpen(true)}
        >
          <ImagePlus className="mr-1 h-3.5 w-3.5" />
          Choose from media library
        </Button>
      )}

      <MediaPickerModal
        open={open}
        onClose={() => setOpen(false)}
        title="Select Image"
        onSelect={(item) => onChange(resolveMediaUrl(item))}
      />
    </div>
  );
}

const imageField: Field<string> = {
  type: 'custom',
  label: 'Image',
  render: MediaImageField,
};

export interface PuckPageEditorImplProps {
  /** Raw `content` value from the entries API (normalized internally). */
  value: unknown;
  /** Called with sanitized Puck data on every editor change. */
  onChange: (data: PuckData) => void;
  /** No save permission: render a static preview instead of the editor. */
  disabled?: boolean;
  /**
   * Optional replacement for Puck's header bar (full-screen designer toolbar).
   * When omitted the header is hidden entirely (embedded mode: the CMS chrome
   * around the card owns saving).
   */
  header?: () => ReactElement;
  /** Puck canvas height. Defaults to a workable embedded card height. */
  height?: string;
}

/**
 * The embedded Puck visual editor for page entries. The CMS header Save
 * button is the single save path: Puck's own header/publish chrome is
 * overridden away and every change flows upward via `onChange`.
 */
export function PuckPageEditorImpl({
  value,
  onChange,
  disabled = false,
  header,
  height = 'max(72vh, 540px)',
}: PuckPageEditorImplProps) {
  // Stable config: the eight page-builder components with the media-picker
  // image field. `sanitizePuckData` never throws and guarantees the Puck
  // Data contract (root object, id'd content rows, known types only).
  const config = useMemo(() => createPuckConfig({ imageField }), []);
  const data = useMemo(() => sanitizePuckData(value), [value]);

  const handleChange = useCallback(
    (next: PuckData) => {
      onChange(sanitizePuckData(next));
    },
    [onChange],
  );

  if (disabled) {
    return (
      <div
        className="bs-puck-editor bs-puck-readout rounded-md border border-border bg-white p-4"
        data-testid="puck-page-readout"
      >
        <Render config={config} data={data} />
      </div>
    );
  }

  return (
    <div className="bs-puck-editor rounded-md border border-border" data-testid="puck-page-editor">
      <Puck
        config={config}
        data={data}
        onChange={handleChange}
        // Inline canvas: components render in the host document so the
        // admin theme, page-builder styles and axe scans see the real DOM.
        iframe={{ enabled: false }}
        // Responsive workable height (mobile floor), Puck owns its layout.
        height={height}
        // Embedded mode hides Puck's header (the CMS chrome owns saving);
        // the full-screen designer passes its own toolbar.
        overrides={{ header: header ?? (() => <></>) }}
      />
    </div>
  );
}

export default PuckPageEditorImpl;
