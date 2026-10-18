import {
  Children,
  isValidElement,
  useCallback,
  useRef,
  useState,
  type CSSProperties,
  type KeyboardEvent as ReactKeyboardEvent,
  type PointerEvent as ReactPointerEvent,
  type ReactNode,
} from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';

/**
 * Two-pane resizable split, hand-rolled on pointer events (the common
 * resizable library is not on the dependency allow-list). Supports pointer
 * drag, double-click reset, keyboard resizing (arrows, Home/End) and the
 * ARIA separator pattern with aria-valuenow.
 *
 * Usage:
 *   <ResizablePanelGroup direction="horizontal" aria-label="…">
 *     <ResizablePanel defaultSize={60}>…</ResizablePanel>
 *     <ResizableHandle aria-label="Resize" />
 *     <ResizablePanel defaultSize={40} minSize={20}>…</ResizablePanel>
 *   </ResizablePanelGroup>
 */

export interface ResizablePanelProps {
  children?: ReactNode;
  /** Initial share of the group in percent. */
  defaultSize: number;
  /** Minimum share in percent (default 10). */
  minSize?: number;
  className?: string;
  style?: CSSProperties;
}

export interface ResizableHandleProps {
  disabled?: boolean;
  'aria-label'?: string;
  className?: string;
}

interface PanelConfig {
  defaultSize: number;
  minSize: number;
  content: ReactNode;
  className?: string;
  style?: CSSProperties;
}

interface HandleConfig {
  disabled: boolean;
  label?: string;
  className?: string;
}

const DEFAULT_MIN = 10;
const KEY_STEP = 2;

function parseChildren(children: ReactNode): {
  panels: PanelConfig[];
  handles: HandleConfig[];
} {
  const panels: PanelConfig[] = [];
  const handles: HandleConfig[] = [];
  Children.forEach(children, (child) => {
    if (!isValidElement(child)) return;
    const props = child.props as ResizablePanelProps & ResizableHandleProps;
    if (props.defaultSize !== undefined) {
      panels.push({
        defaultSize: props.defaultSize,
        minSize: props.minSize ?? DEFAULT_MIN,
        content: props.children,
        className: props.className,
        style: props.style,
      });
    } else {
      handles.push({
        disabled: props.disabled ?? false,
        label: props['aria-label'],
        className: props.className,
      });
    }
  });
  if (panels.length < 2 || handles.length !== panels.length - 1) {
    throw new Error(
      'ResizablePanelGroup expects alternating ResizablePanel and ResizableHandle children, starting and ending with a panel.'
    );
  }
  return { panels, handles };
}

export function ResizablePanelGroup({
  direction,
  children,
  className,
  'aria-label': ariaLabel,
}: {
  direction: 'horizontal' | 'vertical';
  children: ReactNode;
  className?: string;
  'aria-label'?: string;
}) {
  const { t } = useI18n();
  const groupRef = useRef<HTMLDivElement>(null);
  const { panels, handles } = parseChildren(children);

  const [sizes, setSizes] = useState<number[]>(() => panels.map((panel) => panel.defaultSize));

  const setPair = useCallback(
    (handle: number, firstSize: number) => {
      setSizes((previous) => {
        const minFirst = panels[handle]!.minSize;
        const minSecond = panels[handle + 1]!.minSize;
        const pairTotal = previous[handle]! + previous[handle + 1]!;
        const first = Math.min(Math.max(firstSize, minFirst), pairTotal - minSecond);
        const next = [...previous];
        next[handle] = first;
        next[handle + 1] = pairTotal - first;
        return next;
      });
    },
    [panels]
  );

  const startDrag = useCallback(
    (handle: number, event: ReactPointerEvent<HTMLElement>) => {
      const group = groupRef.current;
      if (!group) return;
      const rect = group.getBoundingClientRect();
      const span = direction === 'horizontal' ? rect.width : rect.height;
      if (span <= 0) return;
      const startPos = direction === 'horizontal' ? event.clientX : event.clientY;
      const startSizes = [...sizes];
      const move = (moveEvent: PointerEvent) => {
        const pos = direction === 'horizontal' ? moveEvent.clientX : moveEvent.clientY;
        const deltaPercent = ((pos - startPos) / span) * 100;
        const minFirst = panels[handle]!.minSize;
        const minSecond = panels[handle + 1]!.minSize;
        const pairTotal = startSizes[handle]! + startSizes[handle + 1]!;
        const first = Math.min(
          Math.max(startSizes[handle]! + deltaPercent, minFirst),
          pairTotal - minSecond
        );
        setSizes((previous) => {
          const next = [...previous];
          next[handle] = first;
          next[handle + 1] = pairTotal - first;
          return next;
        });
      };
      const stop = () => {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', stop);
      };
      window.addEventListener('pointermove', move);
      window.addEventListener('pointerup', stop);
    },
    [direction, sizes, panels]
  );

  const handleKeyDown = useCallback(
    (handle: number, event: ReactKeyboardEvent<HTMLElement>) => {
      const forward = direction === 'horizontal' ? 'ArrowRight' : 'ArrowDown';
      const back = direction === 'horizontal' ? 'ArrowLeft' : 'ArrowUp';
      if (event.key === forward) {
        event.preventDefault();
        setPair(handle, sizes[handle]! + KEY_STEP);
      } else if (event.key === back) {
        event.preventDefault();
        setPair(handle, sizes[handle]! - KEY_STEP);
      } else if (event.key === 'Home') {
        event.preventDefault();
        setPair(handle, panels[handle]!.minSize);
      } else if (event.key === 'End') {
        event.preventDefault();
        setPair(handle, 100 - panels[handle + 1]!.minSize);
      }
    },
    [direction, sizes, panels, setPair]
  );

  const reset = useCallback(() => {
    setSizes(panels.map((panel) => panel.defaultSize));
  }, [panels]);

  let panelIndex = 0;
  const nodes: ReactNode[] = [];
  panels.forEach((panel, index) => {
    nodes.push(
      <div
        key={`panel-${index}`}
        className={cn('min-h-0 min-w-0 overflow-hidden', panel.className)}
        style={{
          ...panel.style,
          flexBasis: `${sizes[index] ?? 0}%`,
          flexGrow: 0,
          flexShrink: 0,
        }}
      >
        {panel.content}
      </div>
    );
    panelIndex = index + 1;
    const handle = handles[index];
    if (handle && index < panels.length - 1) {
      const valueNow = Math.round(sizes[panelIndex - 1] ?? 0);
      nodes.push(
        <div
          key={`handle-${index}`}
          role="separator"
          aria-orientation={direction === 'horizontal' ? 'vertical' : 'horizontal'}
          aria-valuenow={valueNow}
          aria-valuemin={Math.round(panels[index]!.minSize)}
          aria-valuemax={Math.round(100 - panels[index + 1]!.minSize)}
          aria-label={handle.label ?? t('resizable.separatorLabel')}
          tabIndex={handle.disabled ? -1 : 0}
          onPointerDown={(event) => {
            if (!handle.disabled) startDrag(index, event);
          }}
          onKeyDown={(event) => {
            if (!handle.disabled) handleKeyDown(index, event);
          }}
          onDoubleClick={handle.disabled ? undefined : reset}
          data-disabled={handle.disabled || undefined}
          className={cn(
            'group/handle relative flex shrink-0 items-center justify-center bg-transparent transition-colors',
            direction === 'horizontal'
              ? 'w-1 cursor-col-resize after:absolute after:inset-y-0 after:left-1/2 after:w-px after:-translate-x-1/2 after:bg-border'
              : 'h-1 cursor-row-resize after:absolute after:inset-x-0 after:top-1/2 after:h-px after:-translate-y-1/2 after:bg-border',
            !handle.disabled && 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1',
            handle.disabled && 'cursor-default opacity-50',
            handle.className
          )}
        />
      );
    }
  });

  return (
    <div
      ref={groupRef}
      aria-label={ariaLabel}
      className={cn(
        'flex h-full w-full',
        direction === 'horizontal' ? 'flex-row' : 'flex-col',
        className
      )}
    >
      {nodes}
    </div>
  );
}

export function ResizablePanel(_props: ResizablePanelProps): null {
  // declarative placeholder — rendered by ResizablePanelGroup
  return null;
}

export function ResizableHandle(_props: ResizableHandleProps): null {
  // declarative placeholder — rendered by ResizablePanelGroup
  return null;
}
