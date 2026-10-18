import {
  ChevronDown,
  ChevronRight,
} from 'lucide-react';
import {
  useCallback,
  useMemo,
  useState,
  type KeyboardEvent,
} from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';

export interface TreeNode {
  id: string;
  label: string;
  children?: TreeNode[];
  disabled?: boolean;
}

export interface TreeViewProps {
  nodes: TreeNode[];
  selectedId?: string | null;
  onSelect?: (id: string) => void;
  /** Ids expanded on mount; defaults to all ancestors of the selected node. */
  defaultExpandedIds?: string[];
  className?: string;
  'aria-label': string;
}

interface FlatNode {
  node: TreeNode;
  level: number;
  expanded: boolean;
  hasChildren: boolean;
}

function collectExpandedDefaults(nodes: TreeNode[], selectedId: string | undefined): string[] {
  const path: string[] = [];
  const walk = (items: TreeNode[], trail: string[]): boolean => {
    for (const item of items) {
      if (item.id === selectedId) {
        path.push(...trail);
        return true;
      }
      if (item.children && walk(item.children, [...trail, item.id])) return true;
    }
    return false;
  };
  walk(nodes, []);
  return path;
}

/**
 * Accessible tree view (WAI-ARIA tree pattern) for hierarchical pickers and
 * the term manager: roving focus, Right/Left expand-collapse and move to
 * parent, Up/Down navigation, Enter selects (docs/02-ui-spec.md 8).
 */
export function TreeView({
  nodes,
  selectedId,
  onSelect,
  defaultExpandedIds,
  className,
  'aria-label': ariaLabel,
}: TreeViewProps) {
  const { t } = useI18n();
  const [expanded, setExpanded] = useState<Set<string>>(
    () =>
      new Set(
        defaultExpandedIds ??
          (selectedId ? collectExpandedDefaults(nodes, selectedId) : [])
      )
  );
  const [focusId, setFocusId] = useState<string | null>(selectedId ?? nodes[0]?.id ?? null);
  // Selection is controlled via selectedId; when the prop is absent the tree
  // manages its own selection so uncontrolled usage still highlights.
  const [internalSelected, setInternalSelected] = useState<string | null>(
    selectedId ?? null
  );
  const isControlled = selectedId !== undefined;
  const currentSelected = isControlled ? selectedId : internalSelected;

  const select = useCallback(
    (id: string) => {
      if (!isControlled) setInternalSelected(id);
      onSelect?.(id);
    },
    [isControlled, onSelect]
  );

  const flat = useMemo<FlatNode[]>(() => {
    const result: FlatNode[] = [];
    const walk = (items: TreeNode[], level: number) => {
      for (const node of items) {
        const hasChildren = !!node.children && node.children.length > 0;
        const isOpen = expanded.has(node.id);
        result.push({ node, level, expanded: isOpen, hasChildren });
        if (hasChildren && isOpen) walk(node.children!, level + 1);
      }
    };
    walk(nodes, 0);
    return result;
  }, [nodes, expanded]);

  const toggleExpand = useCallback((id: string) => {
    setExpanded((previous) => {
      const next = new Set(previous);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }, []);

  const onKeyDown = (event: KeyboardEvent<HTMLElement>, flatIndex: number) => {
    const current = flat[flatIndex];
    if (!current) return;
    const focusIndexById = (id: string | undefined) => {
      if (!id) return false;
      const index = flat.findIndex((entry) => entry.node.id === id);
      if (index === -1) return false;
      setFocusId(id);
      document.getElementById(`tree-item-${id}`)?.focus();
      return true;
    };

    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        focusIndexById(flat[flatIndex + 1]?.node.id);
        break;
      case 'ArrowUp':
        event.preventDefault();
        focusIndexById(flat[flatIndex - 1]?.node.id);
        break;
      case 'ArrowRight':
        event.preventDefault();
        if (current.hasChildren && !current.expanded) toggleExpand(current.node.id);
        else focusIndexById(current.node.children?.[0]?.id);
        break;
      case 'ArrowLeft':
        event.preventDefault();
        if (current.hasChildren && current.expanded) toggleExpand(current.node.id);
        else {
          const parentId = findParentId(nodes, current.node.id);
          if (parentId) focusIndexById(parentId);
        }
        break;
      case 'Home':
        event.preventDefault();
        focusIndexById(flat[0]?.node.id);
        break;
      case 'End':
        event.preventDefault();
        focusIndexById(flat[flat.length - 1]?.node.id);
        break;
      case 'Enter':
      case ' ':
        event.preventDefault();
        if (!current.node.disabled) select(current.node.id);
        break;
      default:
        break;
    }
  };

  if (flat.length === 0) {
    return <p className="text-13 text-muted-foreground">{t('tree.empty')}</p>;
  }

  return (
    <ul role="tree" aria-label={ariaLabel} className={cn('select-none text-13', className)}>
      {flat.map((entry, index) => {
        const { node, level, expanded: isOpen, hasChildren } = entry;
        const isSelected = node.id === currentSelected;
        const isFocusTarget = node.id === focusId;
        return (
          <li
            key={node.id}
            id={`tree-item-${node.id}`}
            role="treeitem"
            aria-level={level + 1}
            aria-expanded={hasChildren ? isOpen : undefined}
            aria-selected={node.id === currentSelected}
            aria-disabled={node.disabled || undefined}
            tabIndex={isFocusTarget ? 0 : -1}
            onKeyDown={(event) => onKeyDown(event, index)}
            onFocus={() => setFocusId(node.id)}
            onClick={() => {
              setFocusId(node.id);
              if (!node.disabled) select(node.id);
              if (hasChildren) toggleExpand(node.id);
            }}
            className={cn(
              'flex cursor-default items-center gap-1 rounded-sm py-1 pr-2 transition-colors',
              'hover:bg-surface-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
              isSelected && 'bg-accent text-accent-foreground',
              node.disabled && 'cursor-not-allowed opacity-50',
              node.disabled && 'hover:bg-transparent'
            )}
            style={{ paddingLeft: `${level * 16 + 4}px` }}
          >
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              {hasChildren ? (
                <button
                  type="button"
                  tabIndex={-1}
                  aria-hidden="true"
                  onClick={(event) => {
                    event.stopPropagation();
                    toggleExpand(node.id);
                  }}
                  className="rounded-sm p-0.5 hover:bg-surface-300"
                >
                  {isOpen ? (
                    <ChevronDown className="h-3.5 w-3.5" />
                  ) : (
                    <ChevronRight className="h-3.5 w-3.5" />
                  )}
                </button>
              ) : null}
            </span>
            <span className="truncate">{node.label}</span>
          </li>
        );
      })}
    </ul>
  );
}

function findParentId(nodes: TreeNode[], id: string, trail: string[] = []): string | undefined {
  for (const node of nodes) {
    if (node.id === id) return trail[trail.length - 1];
    if (node.children) {
      const found = findParentId(node.children, id, [...trail, node.id]);
      if (found !== undefined) return found;
    }
  }
  return undefined;
}
