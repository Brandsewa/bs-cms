import { useState, useEffect, useCallback } from 'react';
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
} from '@bs/ui';
import {
  Compass,
  Plus,
  Trash2,
  Edit2,
  Save,
  RefreshCw,
  ArrowUp,
  ArrowDown,
} from 'lucide-react';
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

export function MenusRoute() {
  const [activeMenuKey, setActiveMenuKey] = useState<string>('header');
  const [menuItems, setMenuItems] = useState<MenuItem[]>([]);
  const [allMenus, setAllMenus] = useState<Record<string, MenuItem[]>>({
    header: [],
    footer: [],
  });
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);

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
      const res = await apiClient.request<NavigationMenuData | { data: NavigationMenuData }>(
        'GET',
        '/singletons/navigation'
      );
      if (res.ok && res.data) {
        const payload = (res.data as any).data || res.data;
        const menusObj = payload.menus || payload || {};
        const safeMenus: Record<string, MenuItem[]> = {
          header: menusObj.header || [
            { id: '1', label: 'Home', url: '/', type: 'custom' },
            { id: '2', label: 'Blog', url: '/posts', type: 'custom' },
            { id: '3', label: 'About Us', url: '/about', type: 'page' },
          ],
          footer: menusObj.footer || [
            { id: '4', label: 'Privacy Policy', url: '/privacy', type: 'page' },
            { id: '5', label: 'Terms of Service', url: '/terms', type: 'page' },
          ],
        };
        setAllMenus(safeMenus);
        setMenuItems(safeMenus[activeMenuKey] || []);
      } else {
        // Fallback default structure
        const defaultMenus: Record<string, MenuItem[]> = {
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
        setAllMenus(defaultMenus);
        setMenuItems(defaultMenus[activeMenuKey] || []);
      }
    } catch {
      // Ignored
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
    const finalData = {
      ...allMenus,
      [activeMenuKey]: menuItems,
    };

    try {
      const res = await apiClient.request('PUT', '/singletons/navigation', {
        body: {
          menus: finalData,
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

  const handleMoveUp = (index: number) => {
    if (index === 0) return;
    const copy = [...menuItems];
    const temp = copy[index];
    const prev = copy[index - 1];
    if (temp && prev) {
      copy[index] = prev;
      copy[index - 1] = temp;
      setMenuItems(copy);
    }
  };

  const handleMoveDown = (index: number) => {
    if (index >= menuItems.length - 1) return;
    const copy = [...menuItems];
    const temp = copy[index];
    const next = copy[index + 1];
    if (temp && next) {
      copy[index] = next;
      copy[index + 1] = temp;
      setMenuItems(copy);
    }
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
            menuItems.map((item, index) => (
              <div
                key={item.id}
                className="flex items-center justify-between p-2.5 rounded-md border border-border bg-surface-50 hover:bg-surface-100 transition-colors text-xs group"
              >
                <div className="flex items-center gap-2.5 flex-1 min-w-0">
                  <span className="text-muted-foreground font-mono text-[10px] w-4">
                    {index + 1}
                  </span>
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
                </div>

                <div className="flex items-center gap-1">
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => handleMoveUp(index)}
                    disabled={index === 0}
                    className="h-6 w-6 p-0 text-muted-foreground"
                    aria-label="Move item up"
                  >
                    <ArrowUp className="h-3 w-3" />
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => handleMoveDown(index)}
                    disabled={index === menuItems.length - 1}
                    className="h-6 w-6 p-0 text-muted-foreground"
                    aria-label="Move item down"
                  >
                    <ArrowDown className="h-3 w-3" />
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => openEditDialog(item)}
                    className="h-6 px-2 text-[11px]"
                  >
                    <Edit2 className="h-3 w-3" />
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => handleDeleteItem(item.id)}
                    className="h-6 w-6 p-0 text-destructive"
                  >
                    <Trash2 className="h-3 w-3" />
                  </Button>
                </div>
              </div>
            ))
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
              <select
                value={itemType}
                onChange={(e) => setItemType(e.target.value as any)}
                className="w-full text-xs h-8 rounded-md border border-border bg-background px-2"
              >
                <option value="custom">Custom Link</option>
                <option value="page">Page</option>
                <option value="post">Post</option>
                <option value="category">Category Archive</option>
              </select>
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
