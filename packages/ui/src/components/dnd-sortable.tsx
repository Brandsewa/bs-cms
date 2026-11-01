import {
  DndContext,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core';
import {
  SortableContext,
  SortableContext as SortableGroup,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { createContext, useContext, type ReactNode } from 'react';
import { cn } from '../lib/utils';
import { GripVertical } from 'lucide-react';

/**
 * Reusable drag-to-reorder list (dnd-kit). Convention since 2026-11: lists
 * are reordered by dragging a grip handle — manual up/down arrow buttons are
 * retired. Keyboard reordering is built in (focus the handle, space to lift,
 * arrows to move) so the pattern stays axe-clean.
 */

export interface SortableListProps {
  /** Item ids in current order. */
  items: string[];
  /** Called with the new id order after a drop. */
  onReorder: (ids: string[]) => void;
  children: ReactNode;
  className?: string;
}

export function SortableList({ items, onReorder, children, className }: SortableListProps) {
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const from = items.indexOf(String(active.id));
    const to = items.indexOf(String(over.id));
    if (from === -1 || to === -1) return;
    const next = [...items];
    const [moved] = next.splice(from, 1);
    if (moved === undefined) return;
    next.splice(to, 0, moved);
    onReorder(next);
  };

  return (
    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
      <SortableContext items={items} strategy={verticalListSortingStrategy}>
        <div className={className}>{children}</div>
      </SortableContext>
    </DndContext>
  );
}

export { SortableGroup };

interface SortableItemContextValue {
  handleProps: Record<string, unknown>;
  isDragging: boolean;
}

const SortableItemContext = createContext<SortableItemContextValue>({
  handleProps: {},
  isDragging: false,
});

/** Read the drag state / handle props inside a SortableItem. */
export function useSortableItemState(): SortableItemContextValue {
  return useContext(SortableItemContext);
}

export function SortableItem({
  id,
  children,
  className,
}: {
  id: string;
  children: ReactNode;
  className?: string;
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id,
  });

  return (
    <SortableItemContext.Provider value={{ handleProps: { ...attributes, ...listeners }, isDragging }}>
      <div
        ref={setNodeRef}
        style={{ transform: CSS.Transform.toString(transform), transition }}
        className={cn(isDragging && 'relative z-10 opacity-80 shadow-lg', className)}
      >
        {children}
      </div>
    </SortableItemContext.Provider>
  );
}

/** Grip handle — place inside a SortableItem. Dragging reorders the row. */
export function SortableDragHandle({ className, label = 'Drag to reorder' }: { className?: string; label?: string }) {
  const { handleProps, isDragging } = useContext(SortableItemContext);
  return (
    <button
      type="button"
      aria-label={label}
      className={cn(
        'flex h-6 w-6 shrink-0 cursor-grab touch-none items-center justify-center rounded text-muted-foreground hover:bg-surface-100 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        isDragging && 'cursor-grabbing',
        className,
      )}
      {...handleProps}
    >
      <GripVertical className="h-3.5 w-3.5" />
    </button>
  );
}
