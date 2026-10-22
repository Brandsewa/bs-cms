import { useState, useMemo } from 'react';
import {
  Card,
  CardHeader,
  CardContent,
  Button,
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  Label,
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
  Badge,
  cn,
} from '@bs/ui';
import {
  ChevronRight,
  ChevronDown,
  Folder,
  FileText,
  Move,
} from 'lucide-react';
import type { EntryItem } from './types';

export interface PageTreeNode extends EntryItem {
  children?: PageTreeNode[];
}

export interface PagesTreeViewProps {
  entries: EntryItem[];
  onMovePage: (entryId: string, newParentId: string | null) => Promise<void>;
  onOpenPage: (page: EntryItem) => void;
  canUpdate?: boolean;
}

export function PagesTreeView({
  entries,
  onMovePage,
  onOpenPage,
  canUpdate = true,
}: PagesTreeViewProps) {
  const [expandedIds, setExpandedIds] = useState<Set<string>>(new Set());
  const [movingEntry, setMovingEntry] = useState<EntryItem | null>(null);
  const [targetParentId, setTargetParentId] = useState<string>('root');
  const [isSubmittingMove, setIsSubmittingMove] = useState(false);
  const [draggedId, setDraggedId] = useState<string | null>(null);

  // Build tree from parent_id / data.parent_id
  const tree = useMemo(() => {
    const nodeMap = new Map<string, PageTreeNode>();
    const roots: PageTreeNode[] = [];

    for (const e of entries) {
      nodeMap.set(e.id, { ...e, children: [] });
    }

    for (const e of entries) {
      const node = nodeMap.get(e.id)!;
      const eData = e.data as Record<string, unknown> | undefined;
      const parentId = ((e as unknown as Record<string, unknown>).parent_id as string | undefined) || (eData?.parent_id as string | undefined);
      if (parentId && nodeMap.has(parentId)) {
        nodeMap.get(parentId)!.children!.push(node);
      } else {
        roots.push(node);
      }
    }

    return roots;
  }, [entries]);

  const toggleExpand = (id: string) => {
    setExpandedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const handleOpenMoveDialog = (entry: EntryItem) => {
    setMovingEntry(entry);
    const eData = entry.data as Record<string, unknown> | undefined;
    const parentId = ((entry as unknown as Record<string, unknown>).parent_id as string | undefined) || (eData?.parent_id as string | undefined) || 'root';
    setTargetParentId(parentId);
  };

  const handleConfirmMove = async () => {
    if (!movingEntry) return;
    setIsSubmittingMove(true);
    try {
      await onMovePage(movingEntry.id, targetParentId === 'root' ? null : targetParentId);
      setMovingEntry(null);
    } finally {
      setIsSubmittingMove(false);
    }
  };

  const renderNode = (node: PageTreeNode, depth = 0) => {
    const hasChildren = node.children && node.children.length > 0;
    const isExpanded = expandedIds.has(node.id);

    return (
      <div key={node.id} className="space-y-1">
        <div
          draggable={canUpdate}
          onDragStart={(e) => {
            e.dataTransfer.setData('text/plain', node.id);
            setDraggedId(node.id);
          }}
          onDragOver={(e) => {
            if (draggedId && draggedId !== node.id) {
              e.preventDefault();
            }
          }}
          onDrop={(e) => {
            e.preventDefault();
            const sourceId = e.dataTransfer.getData('text/plain');
            if (sourceId && sourceId !== node.id) {
              onMovePage(sourceId, node.id);
            }
            setDraggedId(null);
          }}
          className={cn(
            'flex items-center justify-between p-2 rounded-md border border-border bg-card hover:bg-surface-100 transition-colors group text-xs',
            draggedId === node.id && 'opacity-50 border-dashed'
          )}
          style={{ marginLeft: `${depth * 20}px` }}
        >
          <div className="flex items-center gap-2 flex-1 min-w-0">
            {hasChildren ? (
              <button
                type="button"
                onClick={() => toggleExpand(node.id)}
                className="p-0.5 rounded text-muted-foreground hover:text-foreground"
              >
                {isExpanded ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
              </button>
            ) : (
              <span className="w-4.5" />
            )}

            {hasChildren ? (
              <Folder className="h-4 w-4 text-primary shrink-0" />
            ) : (
              <FileText className="h-4 w-4 text-muted-foreground shrink-0" />
            )}

            <span
              onClick={() => onOpenPage(node)}
              className="font-medium text-foreground hover:underline cursor-pointer truncate"
            >
              {node.title || 'Untitled Page'}
            </span>

            <span className="text-[11px] font-mono text-muted-foreground truncate">
              /{node.slug || ''}
            </span>

            <Badge variant="outline" className="text-[10px] py-0">
              {node.status || 'published'}
            </Badge>
          </div>

          <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
            {canUpdate && (
              <Button
                size="sm"
                variant="ghost"
                onClick={() => handleOpenMoveDialog(node)}
                className="h-6 px-1.5 text-[11px] text-muted-foreground hover:text-foreground flex items-center gap-1"
                title="Keyboard Move To..."
              >
                <Move className="h-3 w-3" />
                <span>Move</span>
              </Button>
            )}
            <Button
              size="sm"
              variant="outline"
              onClick={() => onOpenPage(node)}
              className="h-6 px-2 text-[11px]"
            >
              Edit
            </Button>
          </div>
        </div>

        {hasChildren && isExpanded && (
          <div className="space-y-1">
            {node.children!.map((child) => renderNode(child, depth + 1))}
          </div>
        )}
      </div>
    );
  };

  return (
    <Card className="border-border bg-card">
      <CardHeader className="p-4 pb-2 border-b border-border flex flex-row items-center justify-between">
        <div>
          <span className="text-xs font-semibold uppercase tracking-wider text-foreground-lighter">
            Pages Tree Hierarchy
          </span>
          <p className="text-[11px] text-muted-foreground">
            Drag to re-parent or use the keyboard "Move to..." alternative.
          </p>
        </div>
        <div className="flex gap-2">
          <Button
            size="sm"
            variant="outline"
            onClick={() => setExpandedIds(new Set(entries.map((e) => e.id)))}
            className="text-xs h-7 px-2"
          >
            Expand All
          </Button>
          <Button
            size="sm"
            variant="outline"
            onClick={() => setExpandedIds(new Set())}
            className="text-xs h-7 px-2"
          >
            Collapse All
          </Button>
        </div>
      </CardHeader>
      <CardContent className="p-4 space-y-1.5">
        {tree.length === 0 ? (
          <p className="text-xs text-muted-foreground text-center py-6">No pages found in collection.</p>
        ) : (
          tree.map((root) => renderNode(root, 0))
        )}
      </CardContent>

      {/* Keyboard Accessible "Move To..." Dialog */}
      <Dialog open={Boolean(movingEntry)} onOpenChange={(val) => !val && setMovingEntry(null)}>
        <DialogContent className="max-w-md bg-background">
          <DialogHeader>
            <DialogTitle className="text-sm font-semibold flex items-center gap-2">
              <Move className="h-4 w-4 text-primary" />
              <span>Move Page: {movingEntry?.title}</span>
            </DialogTitle>
          </DialogHeader>

          <div className="py-2 space-y-3">
            <div className="space-y-1.5">
              <Label className="text-xs">Select Target Parent Hierarchy</Label>
              <Select value={targetParentId} onValueChange={setTargetParentId}>
                <SelectTrigger className="text-xs h-8">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="root">Root (No Parent)</SelectItem>
                  {entries
                    .filter((e) => e.id !== movingEntry?.id)
                    .map((e) => (
                      <SelectItem key={e.id} value={e.id}>
                        {e.title} (/{e.slug})
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
            </div>
            <p className="text-[11px] text-muted-foreground">
              This updates the parent route link and nests the page in navigation structures.
            </p>
          </div>

          <DialogFooter className="gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setMovingEntry(null)}
              className="text-xs"
            >
              Cancel
            </Button>
            <Button
              size="sm"
              onClick={handleConfirmMove}
              disabled={isSubmittingMove}
              className="text-xs"
            >
              {isSubmittingMove ? 'Moving...' : 'Apply Move'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
