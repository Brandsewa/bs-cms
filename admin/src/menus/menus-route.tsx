import { useState, useEffect, useCallback, useRef } from 'react';
import {
  PageContainer,
  PageHeader,
  Button,
  Input,
  Label,
  Card,
  CardHeader,
  CardContent,
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  Badge,
  Spinner,
  Tabs,
  TabsList,
  TabsTrigger,
  errorToast,
  successToast,
  cn,
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from '@bs/ui';
import {
  Compass,
  Plus,
  Trash2,
  Edit2,
  Save,
  RefreshCw,
  CornerDownRight,
} from 'lucide-react';
import {
  DndContext,
  PointerSensor,
  KeyboardSensor,
  closestCenter,
  useSensor,
  useSensors,
  useDroppable,
  type DragEndEvent,
} from '@dnd-kit/core';
import { SortableContext, sortableKeyboardCoordinates, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { SortableItem, SortableDragHandle, useSortableItemState } from '@bs/ui';
import { apiClient } from '../lib/api';

export interface MenuItem {
  id: string;
  label: string;
  url: string;
  type: 'custom' | 'page' | 'post' | 'category';
  open_in_new_tab?: boolean;
  children?: MenuItem[];
}

export interface NavigationMenuData {
  menus: Record<string, MenuItem[]>;
}


/** Shape of one flat repeater row in the navigation singleton. */
interface NavigationRow {
  id?: string;
  label?: string;
  url?: string;
  type?: string;
  target?: number | boolean;
  menu?: string;
  parent_id?: string | null;
  position?: number;
}

function defaultMenus(): Record<string, MenuItem[]> {
  return {
    header: [
      { id: '1', label: 'Home', url: '/', type: 'custom' },
      { id: '2', label: 'Blog', url: '/posts', type: 'custom' },
      { id: '3', label: 'About Us', url: '/about', type: 'page' },
    ],
    footer: [
      { id: '4', label: 'Privacy Policy', url: '/privacy', type: 'page' },
      { id: '5', label: 'Terms of Service', url: '/terms', type: 'page' },
    ],
  };
}

/** Compose header/footer trees from the flat repeater rows. */
function composeTreesFromRows(rows: Record<string, unknown>[]): Record<string, MenuItem[]> {
  const parsed: Array<MenuItem & { menu: string; parentId: string | null }> = rows.map((row, i) => ({
    id: typeof row['id'] === 'string' && row['id'] ? row['id'] : `row_${i}`,
    label: typeof row['label'] === 'string' ? row['label'] : `Item ${i + 1}`,
    url: typeof row['url'] === 'string' ? row['url'] : '/',
    type: (typeof row['type'] === 'string' ? row['type'] : 'custom') as MenuItem['type'],
    open_in_new_tab: Boolean(row['target']),
    menu: typeof row['menu'] === 'string' ? row['menu'] : 'header',
    parentId: typeof row['parent_id'] === 'string' ? row['parent_id'] : null,
  }));

  const byMenu = (menu: string) => parsed.filter((r) => r.menu === menu);
  const tree = (list: Array<MenuItem & { menu: string; parentId: string | null }>): MenuItem[] => {
    const nodes = new Map<string, MenuItem & { children: MenuItem[]; parentId: string | null }>();
    for (const r of list) nodes.set(r.id, { ...r, children: [] });
    const roots: MenuItem[] = [];
    for (const r of list) {
      const node = nodes.get(r.id)!;
      const parent = r.parentId ? nodes.get(r.parentId) : undefined;
      if (parent && parent !== node) parent.children.push(node);
      else roots.push(node);
    }
    return roots;
  };

  return { header: tree(byMenu('header')), footer: tree(byMenu('footer')) };
}

/** Flatten a composed tree back into repeater rows for storage. */
function flattenTree(nodes: MenuItem[], menu: string, parentId: string | null = null): NavigationRow[] {
  const rows: NavigationRow[] = [];
  nodes.forEach((node, index) => {
    rows.push({
      id: node.id,
      label: node.label,
      url: node.url,
      type: node.type,
      target: node.open_in_new_tab ? 1 : 0,
      menu,
      parent_id: parentId,
      position: index,
    });
    if (node.children?.length) {
      rows.push(...flattenTree(node.children, menu, node.id));
    }
  });
  return rows;
}

export function MenusRoute() {
  const [activeMenuKey, setActiveMenuKey] = useState<string>('header');
  const [menuItems, setMenuItems] = useState<MenuItem[]>([]);
  const [allMenus, setAllMenus] = useState<Record<string, MenuItem[]>>({
    header: [],
    footer: [],
  });
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);

  // Keep the per-menu snapshot in sync so drag changes always reach Save.
  const menuItemsRef = useRef<MenuItem[]>(menuItems);
  useEffect(() => {
    menuItemsRef.current = menuItems;
    setAllMenus((prev) => (prev[activeMenuKey] === menuItems ? prev : { ...prev, [activeMenuKey]: menuItems }));
  }, [menuItems, activeMenuKey]);

  // Edit / Add modal
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingItem, setEditingItem] = useState<MenuItem | null>(null);
  const [label, setLabel] = useState('');
  const [url, setUrl] = useState('');
  const [itemType, setItemType] = useState<'custom' | 'page' | 'post' | 'category'>('custom');
  const [openInNewTab, setOpenInNewTab] = useState(false);

  const loadNavigation = useCallback(async () => {
    setLoading(true);
    try {
      const res = await apiClient.request<{ data?: { items?: Record<string, unknown>[] } } | { items?: Record<string, unknown>[] }>(
        'GET',
        '/singletons/navigation'
      );
      if (res.ok && res.data) {
        const payload = (res.data as { data?: { items?: Record<string, unknown>[] } }).data ?? (res.data as { items?: Record<string, unknown>[] });
        // Storage is a FLAT repeater of rows (schema-valid); the tree is
        // composed client-side from menu/parent_id/position.
        const rows = Array.isArray(payload.items) ? payload.items : [];
        // Empty singleton: seed the familiar starter structure instead of a blank editor.
        const trees = rows.length > 0 ? composeTreesFromRows(rows) : defaultMenus();
        setAllMenus(trees);
        setMenuItems(trees[activeMenuKey] || []);
      } else {
        setAllMenus(defaultMenus());
        setMenuItems(defaultMenus()[activeMenuKey] || []);
      }
    } catch {
      setAllMenus(defaultMenus());
      setMenuItems(defaultMenus()[activeMenuKey] || []);
    } finally {
      setLoading(false);
    }
  }, [activeMenuKey]);

  useEffect(() => {
    loadNavigation();
  }, [loadNavigation]);

  const handleMenuTabChange = (key: string) => {
    // Update local store for current menu
    const updated = {
      ...allMenus,
      [activeMenuKey]: menuItems,
    };
    setAllMenus(updated);
    setActiveMenuKey(key);
    setMenuItems(updated[key] || []);
  };

  const handleSaveNavigation = async () => {
    setSaving(true);
    // Flatten both trees into the flat repeater rows the navigation schema
    // stores (the `menus` payload shape used previously was schema-invalid
    // and silently failed every save with a 422).
    const headerTree = activeMenuKey === 'footer' ? (allMenus.header ?? []) : menuItemsRef.current;
    const footerTree = activeMenuKey === 'footer' ? menuItemsRef.current : (allMenus.footer ?? []);
    const items = [
      ...flattenTree(headerTree, 'header'),
      ...flattenTree(footerTree, 'footer'),
    ];

    try {
      const res = await apiClient.request('PUT', '/singletons/navigation', {
        body: {
          // The public site endpoint (GET /api/v1/public/site) reads `items`
          // for the header navigation consumed by the static starter.
          items,
        },
      });

      if (res.ok) {
        successToast('Navigation menus saved successfully.');
      } else {
        errorToast('Failed to save navigation menus.');
      }
    } catch {
      errorToast('Failed to save navigation menus.');
    } finally {
      setSaving(false);
    }
  };

  const openAddDialog = () => {
    setEditingItem(null);
    setLabel('');
    setUrl('');
    setItemType('custom');
    setOpenInNewTab(false);
    setDialogOpen(true);
  };

  const openEditDialog = (item: MenuItem) => {
    setEditingItem(item);
    setLabel(item.label);
    setUrl(item.url);
    setItemType(item.type);
    setOpenInNewTab(Boolean(item.open_in_new_tab));
    setDialogOpen(true);
  };

  const handleSaveMenuItem = () => {
    if (!label.trim()) return;

    if (editingItem) {
      // Recursively update
      const updateRecursive = (list: MenuItem[]): MenuItem[] =>
        list.map((it) => {
          if (it.id === editingItem.id) {
            return {
              ...it,
              label: label.trim(),
              url: url.trim() || '/',
              type: itemType,
              open_in_new_tab: openInNewTab,
            };
          }
          if (it.children) {
            return { ...it, children: updateRecursive(it.children) };
          }
          return it;
        });

      setMenuItems((prev) => updateRecursive(prev));
    } else {
      const newItem: MenuItem = {
        id: `menu_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`,
        label: label.trim(),
        url: url.trim() || '/',
        type: itemType,
        open_in_new_tab: openInNewTab,
      };
      setMenuItems((prev) => [...prev, newItem]);
    }

    setDialogOpen(false);
  };

  const handleDeleteItem = (id: string) => {
    const deleteRecursive = (list: MenuItem[]): MenuItem[] =>
      list
        .filter((it) => it.id !== id)
        .map((it) => (it.children ? { ...it, children: deleteRecursive(it.children) } : it));

    setMenuItems((prev) => deleteRecursive(prev));
  };

  // ---- Nested drag & drop (dnd-kit) ----
  // The tree is dragged as flat contexts per level: reorder within a group,
  // move across groups, or drop on a "nest" zone to make the dragged item a
  // child of that item — mirroring the page builder's drag model.

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  /** Locate the array that directly contains `id` plus its parent item. */
  const findContainingList = (list: MenuItem[], id: string, parent: MenuItem | null): { list: MenuItem[]; parent: MenuItem | null } | null => {
    if (list.some((it) => it.id === id)) return { list, parent };
    for (const it of list) {
      if (it.children?.length) {
        const found = findContainingList(it.children, id, it);
        if (found) return found;
      }
    }
    return null;
  };

  /** True when `ancestorId` contains `id` anywhere in its subtree. */
  const isDescendantOf = (list: MenuItem[], id: string, ancestorId: string): boolean => {
    for (const it of list) {
      if (it.id === ancestorId) {
        return it.children?.some((c) => c.id === id || isDescendantOf([c], id, ancestorId)) ?? false;
      }
      if (it.children && isDescendantOf(it.children, id, ancestorId)) return true;
    }
    return false;
  };

  const removeFromTree = (list: MenuItem[], id: string): { list: MenuItem[]; removed: MenuItem | null } => {
    let removed: MenuItem | null = null;
    const next = list.filter((it) => {
      if (it.id === id) {
        removed = it;
        return false;
      }
      return true;
    });
    if (removed) return { list: next, removed };
    for (const it of next) {
      if (it.children?.length) {
        const sub = removeFromTree(it.children, id);
        if (sub.removed) return { list: next.map((x) => (x.id === it.id ? { ...x, children: sub.list } : x)), removed: sub.removed };
      }
    }
    return { list: next, removed: null };
  };

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (!over) return;
    const activeId = String(active.id);
    const overId = String(over.id);
    if (activeId === overId) return;

    setMenuItems((prev) => {
      // Drop on a nest zone: append as a child of the zone's owner.
      if (overId.startsWith('nest:')) {
        const ownerId = overId.slice(5);
        if (ownerId === activeId || isDescendantOf(prev, activeId, ownerId)) return prev;
        const { list: without, removed } = removeFromTree(prev, activeId);
        if (!removed) return prev;
        const attach = (list: MenuItem[]): MenuItem[] =>
          list.map((it) => (it.id === ownerId ? { ...it, children: [...(it.children ?? []), removed] } : it));
        return attach(without);
      }

      // Drop on an item: reorder within its list, or move across lists.
      const source = findContainingList(prev, activeId, null);
      const target = findContainingList(prev, overId, null);
      if (!source || !target) return prev;

      if (source.parent === target.parent) {
        const from = source.list.findIndex((it) => it.id === activeId);
        const to = target.list.findIndex((it) => it.id === overId);
        const next = [...source.list];
        const [moved] = next.splice(from, 1);
        if (!moved) return prev;
        next.splice(to, 0, moved);
        // Patch the containing list back into the tree
        if (source.parent === null) return next;
        const patch = (list: MenuItem[]): MenuItem[] =>
          list.map((it) => (it.id === source.parent!.id ? { ...it, children: next } : it));
        return patch(prev);
      }

      if (isDescendantOf(prev, activeId, overId)) return prev;
      const { list: without, removed } = removeFromTree(prev, activeId);
      if (!removed) return prev;
      const targetList = findContainingList(without, overId, null);
      if (!targetList) return prev;
      const at = targetList.list.findIndex((it) => it.id === overId);
      const inserted = [...targetList.list];
      inserted.splice(at, 0, removed);
      if (targetList.parent === null) return inserted;
      const patch = (list: MenuItem[]): MenuItem[] =>
        list.map((it) => (it.id === targetList.parent!.id ? { ...it, children: inserted } : it));
      return patch(prev);
    });
  };

  return (
    <PageContainer width="full" className="space-y-4">
      <div className="flex items-center justify-between border-b border-border pb-4">
        <PageHeader
          title="Navigation & Menus"
          description="Structure multi-level header and footer navigation menus with pages, categories, and custom links."
        />
        <div className="flex items-center gap-2">
          <Button
            size="sm"
            onClick={handleSaveNavigation}
            disabled={saving}
            className="text-xs h-8 gap-1.5"
          >
            {saving ? <Spinner className="h-3.5 w-3.5" /> : <Save className="h-3.5 w-3.5" />}
            Save Menu Changes
          </Button>
        </div>
      </div>

      <div className="flex items-center justify-between">
        <Tabs value={activeMenuKey} onValueChange={handleMenuTabChange}>
          <TabsList className="h-9">
            <TabsTrigger value="header" className="text-xs px-4">
              Header Navigation Menu
            </TabsTrigger>
            <TabsTrigger value="footer" className="text-xs px-4">
              Footer Navigation Menu
            </TabsTrigger>
          </TabsList>
        </Tabs>

        <Button size="sm" onClick={openAddDialog} className="text-xs h-8 gap-1">
          <Plus className="h-3.5 w-3.5" />
          Add Menu Item
        </Button>
      </div>

      <Card className="border-border bg-card">
        <CardHeader className="p-4 pb-2 border-b border-border flex flex-row items-center justify-between">
          <span className="text-xs font-semibold uppercase tracking-wider text-foreground-lighter flex items-center gap-1.5">
            <Compass className="h-3.5 w-3.5 text-muted-foreground" />
            Menu Structure ({menuItems.length} items)
          </span>
          <Button
            size="sm"
            variant="ghost"
            onClick={loadNavigation}
            disabled={loading}
            className="h-7 text-xs text-muted-foreground"
          >
            <RefreshCw className={cn('h-3.5 w-3.5 mr-1', loading && 'animate-spin')} />
            Reload
          </Button>
        </CardHeader>

        <CardContent className="p-4 space-y-2">
          {loading ? (
            <div className="flex justify-center p-12">
              <Spinner className="h-8 w-8 text-primary" />
            </div>
          ) : menuItems.length === 0 ? (
            <div className="flex flex-col items-center justify-center p-12 text-center text-muted-foreground border border-dashed border-border rounded-md">
              <Compass className="h-10 w-10 mb-2 stroke-1" />
              <p className="text-xs font-medium">No items in this menu</p>
              <Button size="sm" onClick={openAddDialog} className="text-xs mt-3">
                Add First Menu Item
              </Button>
            </div>
          ) : (
            <DndContext
              sensors={sensors}
              collisionDetection={closestCenter}
              onDragEnd={handleDragEnd}
            >
              <MenuTreeLevel items={menuItems} parentId={null} onEdit={openEditDialog} onDelete={handleDeleteItem} />
            </DndContext>
          )}
        </CardContent>
      </Card>

      {/* Add / Edit Menu Item Dialog */}
      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-w-md bg-background">
          <DialogHeader>
            <DialogTitle className="text-sm font-semibold flex items-center gap-2">
              <Compass className="h-4 w-4 text-primary" />
              <span>{editingItem ? 'Edit Menu Item' : 'Add Menu Item'}</span>
            </DialogTitle>
          </DialogHeader>

          <div className="py-2 space-y-3">
            <div className="space-y-1">
              <Label className="text-xs">Navigation Label</Label>
              <Input
                value={label}
                onChange={(e) => setLabel(e.target.value)}
                placeholder="e.g. Products, Documentation, Contact"
                className="text-xs h-8"
                autoFocus
              />
            </div>

            <div className="space-y-1">
              <Label className="text-xs">Link Destination URL or Path</Label>
              <Input
                value={url}
                onChange={(e) => setUrl(e.target.value)}
                placeholder="/posts or https://brandsewa.com"
                className="text-xs h-8 font-mono"
              />
            </div>

            <div className="space-y-1">
              <Label className="text-xs">Item Type</Label>
              <Select
                value={itemType}
                onValueChange={(val) => setItemType(val as 'custom' | 'page' | 'post' | 'category')}
              >
                <SelectTrigger className="w-full text-xs h-8">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="custom">Custom Link</SelectItem>
                  <SelectItem value="page">Page</SelectItem>
                  <SelectItem value="post">Post</SelectItem>
                  <SelectItem value="category">Category Archive</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="flex items-center gap-2 pt-1">
              <input
                type="checkbox"
                id="menu-item-new-tab"
                checked={openInNewTab}
                onChange={(e) => setOpenInNewTab(e.target.checked)}
                className="rounded border-border"
              />
              <Label htmlFor="menu-item-new-tab" className="text-xs cursor-pointer font-normal">
                Open in new window / tab
              </Label>
            </div>
          </div>

          <DialogFooter className="gap-2">
            <Button variant="outline" size="sm" onClick={() => setDialogOpen(false)} className="text-xs">
              Cancel
            </Button>
            <Button size="sm" onClick={handleSaveMenuItem} disabled={!label.trim()} className="text-xs">
              Save Item
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </PageContainer>
  );
}


// ---- Nested drag & drop tree ----
// Each level is its own sortable group; a drag handle (grip) reorders among
// siblings, and a drop zone under every item accepts drops to nest as a child.

function MenuTreeLevel({
  items,
  parentId,
  depth = 0,
  onEdit,
  onDelete,
}: {
  items: MenuItem[];
  parentId: string | null;
  depth?: number;
  onEdit: (item: MenuItem) => void;
  onDelete: (id: string) => void;
}) {
  return (
    <SortableContext items={items.map((it) => it.id)} strategy={verticalListSortingStrategy}>
      <div className="space-y-1.5">
        {items.map((item) => (
          <MenuTreeItem key={item.id} item={item} depth={depth} onEdit={onEdit} onDelete={onDelete} />
        ))}
        {items.length === 0 && parentId !== null && <NestDropZone ownerId={parentId} label="Drop here to nest" />}
      </div>
    </SortableContext>
  );
}

function MenuTreeItem({
  item,
  depth = 0,
  onEdit,
  onDelete,
}: {
  item: MenuItem;
  depth?: number;
  onEdit: (item: MenuItem) => void;
  onDelete: (id: string) => void;
}) {
  const { isDragging } = useSortableItemState();
  const hasChildren = Boolean(item.children?.length);

  return (
    <SortableItem id={item.id} className={cn(isDragging && 'rounded-md')}>
      <div
        className="p-2.5 rounded-md border border-border bg-surface-50 hover:bg-surface-100 transition-colors text-xs group"
      >
        <div className="flex items-center justify-between gap-2">
          <div className="flex items-center gap-2.5 flex-1 min-w-0">
            <SortableDragHandle label={`Drag ${item.label}`} />
            <span className="font-semibold text-foreground truncate">{item.label}</span>
            <span className="font-mono text-muted-foreground truncate">{item.url}</span>
            <Badge variant="outline" className="text-[10px] capitalize py-0">
              {item.type}
            </Badge>
            {item.open_in_new_tab && (
              <Badge variant="secondary" className="text-[9px] py-0">
                New Tab
              </Badge>
            )}
            {hasChildren && (
              <Badge variant="secondary" className="text-[9px] py-0">
                {item.children?.length} sub
              </Badge>
            )}
          </div>

          <div className="flex items-center gap-1 opacity-60 group-hover:opacity-100 transition-opacity">
            <Button
              size="sm"
              variant="outline"
              onClick={() => onEdit(item)}
              className="h-6 px-2 text-[11px]"
            >
              <Edit2 className="h-3 w-3" />
            </Button>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => onDelete(item.id)}
              className="h-6 w-6 p-0 text-destructive"
            >
              <Trash2 className="h-3 w-3" />
            </Button>
          </div>
        </div>

        {hasChildren && (
          <div className="mt-1.5 ml-5 border-l border-dashed border-border pl-3">
            <MenuTreeLevel items={item.children ?? []} parentId={item.id} depth={depth + 1} onEdit={onEdit} onDelete={onDelete} />
          </div>
        )}
        <NestDropZone ownerId={item.id} />
      </div>
    </SortableItem>
  );
}

/** Drop target under every item: dropping here nests the item as a child. */
function NestDropZone({ ownerId, label }: { ownerId: string; label?: string }) {
  const { isOver, setNodeRef, active } = useDroppable({ id: `nest:${ownerId}` });
  if (!active) return null;
  return (
    <div
      ref={setNodeRef}
      className={cn(
        'mt-1 rounded border border-dashed text-[10px] text-center py-1 flex items-center justify-center gap-1',
        isOver ? 'border-primary bg-primary/10 text-primary' : 'border-border/60 text-muted-foreground/60',
      )}
    >
      <CornerDownRight className="h-3 w-3" />
      {label ?? 'Drop to nest under this item'}
    </div>
  );
}
