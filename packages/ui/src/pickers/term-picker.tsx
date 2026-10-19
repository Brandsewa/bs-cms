import { Search } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { Checkbox } from '../components/checkbox';
import { Input } from '../components/input';
import { Label } from '../components/label';
import { TreeView, type TreeNode } from '../components/tree-view';

export interface PickerTermNode {
  id: string;
  label: string;
  children?: PickerTermNode[];
}

export interface TermPickerProps {
  tree: PickerTermNode[];
  /** Selected term ids. */
  value: string[];
  onChange: (ids: string[]) => void;
  /** "Include subcategories": selecting a parent targets its whole subtree. */
  includeSubcategories?: boolean;
  onIncludeSubcategoriesChange?: (include: boolean) => void;
  className?: string;
  'aria-label'?: string;
}

function collectSubtreeIds(nodes: PickerTermNode[], rootId: string): string[] {
  for (const node of nodes) {
    if (node.id === rootId) {
      const ids: string[] = [node.id];
      const walk = (items: PickerTermNode[]) => {
        for (const item of items) {
          ids.push(item.id);
          if (item.children) walk(item.children);
        }
      };
      if (node.children) walk(node.children);
      return ids;
    }
    if (node.children) {
      const nested = collectSubtreeIds(node.children, rootId);
      if (nested.length > 0) return nested;
    }
  }
  return [];
}

/**
 * Term picker (docs/02-ui-spec.md 4.5): searchable tree with multi-select
 * checkboxes and an "include subcategories" toggle for hierarchical
 * taxonomies. Keyboard navigation comes from the TreeView primitive.
 */
export function TermPicker({
  tree,
  value,
  onChange,
  includeSubcategories = false,
  onIncludeSubcategoriesChange,
  className,
  'aria-label': ariaLabel,
}: TermPickerProps) {
  const { t } = useI18n();
  const searchId = useId();
  const [query, setQuery] = useState('');

  const filteredTree = useMemo(() => {
    if (!query) return tree;
    const prune = (nodes: PickerTermNode[]): PickerTermNode[] => {
      const result: PickerTermNode[] = [];
      for (const node of nodes) {
        const children = node.children ? prune(node.children) : undefined;
        if (node.label.toLowerCase().includes(query.toLowerCase()) || (children && children.length > 0)) {
          result.push({ ...node, children: children && children.length > 0 ? children : undefined });
        }
      }
      return result;
    };
    return prune(tree);
  }, [tree, query]);

  const toggle = (id: string) => {
    if (includeSubcategories) {
      const subtree = new Set(collectSubtreeIds(tree, id));
      const anyOn = value.some((existing) => subtree.has(existing));
      const next = new Set(value);
      for (const subtreeId of subtree) {
        if (anyOn) next.delete(subtreeId);
        else next.add(subtreeId);
      }
      onChange([...next]);
    } else {
      onChange(value.includes(id) ? value.filter((existing) => existing !== id) : [...value, id]);
    }
  };

  const isSelected = (id: string): boolean => {
    if (value.includes(id)) return true;
    if (includeSubcategories) {
      const subtree = collectSubtreeIds(tree, id);
      return value.some((existing) => subtree.includes(existing));
    }
    return false;
  };

  // Decorate labels with a selected marker; the TreeView selection callbacks
  // drive the actual multi-select state above.
  const checkableNodes = useMemo(() => {
    const decorate = (nodes: PickerTermNode[]): TreeNode[] =>
      nodes.map((node) => ({
        id: node.id,
        label: isSelected(node.id) ? `${node.label} ✓` : node.label,
        children: node.children ? decorate(node.children) : undefined,
      }));
    return decorate(filteredTree);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filteredTree, value, includeSubcategories]);

  // Search results must be visible without manual expansion: remount the
  // tree fully expanded whenever a query prunes it.
  const searchExpandedIds = useMemo(() => {
    if (!query) return undefined;
    const ids: string[] = [];
    const walk = (nodes: PickerTermNode[]) => {
      for (const node of nodes) {
        ids.push(node.id);
        if (node.children) walk(node.children);
      }
    };
    walk(filteredTree);
    return ids;
  }, [query, filteredTree]);

  return (
    <div className={cn('space-y-2', className)}>
      <div className="relative">
        <Search
          className="pointer-events-none absolute left-2 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
          aria-hidden="true"
        />
        <Input
          id={searchId}
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder={t('pickers.termSearch')}
          aria-label={t('pickers.termSearch')}
          className="pl-8"
        />
      </div>

      {onIncludeSubcategoriesChange ? (
        <div className="flex items-center gap-2">
          <Checkbox
            id="term-picker-subcategories"
            checked={includeSubcategories}
            onCheckedChange={(checked) => onIncludeSubcategoriesChange(checked === true)}
          />
          <Label htmlFor="term-picker-subcategories">{t('pickers.includeSubcategories')}</Label>
        </div>
      ) : null}

      <div className="max-h-64 overflow-y-auto rounded-md border border-border p-1">
        <TreeView
          key={query}
          nodes={checkableNodes}
          selectedId={value[0] ?? null}
          onSelect={toggle}
          defaultExpandedIds={searchExpandedIds}
          aria-label={ariaLabel ?? t('pickers.terms')}
          className="text-13"
        />
      </div>
      {value.length > 0 ? (
        <p className="text-xs text-muted-foreground">
          {t('common.selected', { count: value.length })}
        </p>
      ) : null}
    </div>
  );
}
