import { useState, useEffect, useCallback, useMemo } from 'react';
import {
  PageContainer,
  PageHeader,
  Button,
  Input,
  Label,
  Textarea,
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  Badge,
  Spinner,
  errorToast,
  successToast,
  cn,
} from '@bs/ui';
import {
  Tags,
  Plus,
  Trash2,
  Edit2,
  FolderTree,
  ChevronRight,
  ChevronDown,
  GitMerge,
  Search,
  RefreshCw,
  Tag,
} from 'lucide-react';
import { apiClient } from '../lib/api';

export interface TaxonomyItem {
  id: string;
  name: string;
  handle: string;
  description?: string;
  is_hierarchical?: boolean;
}

export interface TermItem {
  id: string;
  taxonomy_id?: string;
  name: string;
  slug: string;
  description?: string;
  parent_id?: string | null;
  count?: number;
  color?: string;
  icon?: string;
  children?: TermItem[];
}

export function TaxonomiesRoute() {
  const [taxonomies, setTaxonomies] = useState<TaxonomyItem[]>([]);
  const [activeTaxonomy, setActiveTaxonomy] = useState<TaxonomyItem | null>(null);
  const [terms, setTerms] = useState<TermItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState('');

  // Expand/collapse state for hierarchical tree
  const [expandedIds, setExpandedIds] = useState<Set<string>>(new Set());

  // Term creation dialog
  const [termDialogOpen, setTermDialogOpen] = useState(false);
  const [editingTerm, setEditingTerm] = useState<TermItem | null>(null);
  const [termName, setTermName] = useState('');
  const [termSlug, setTermSlug] = useState('');
  const [termDescription, setTermDescription] = useState('');
  const [termParentId, setTermParentId] = useState<string>('root');
  const [termColor, setTermColor] = useState('#3b82f6');
  const [savingTerm, setSavingTerm] = useState(false);

  // Merge Dialog
  const [mergeDialogOpen, setMergeDialogOpen] = useState(false);
  const [mergeSourceTerm, setMergeSourceTerm] = useState<TermItem | null>(null);
  const [mergeTargetTermId, setMergeTargetTermId] = useState<string>('');
  const [merging, setMerging] = useState(false);

  const loadTaxonomies = useCallback(async () => {
    try {
      const res = await apiClient.request<TaxonomyItem[] | { data: TaxonomyItem[] }>('GET', '/taxonomies');
      if (res.ok && res.data) {
        const list = Array.isArray(res.data) ? res.data : (res.data as any).data || [];
        setTaxonomies(list);
        if (list.length > 0 && !activeTaxonomy) {
          setActiveTaxonomy(list[0] || null);
        }
      } else {
        // Fallback default standard taxonomies
        const defaults: TaxonomyItem[] = [
          { id: '1', name: 'Categories', handle: 'categories', is_hierarchical: true, description: 'Hierarchical categories' },
          { id: '2', name: 'Tags', handle: 'tags', is_hierarchical: false, description: 'Free-form flat tags' },
        ];
        setTaxonomies(defaults);
        if (!activeTaxonomy) setActiveTaxonomy(defaults[0] || null);
      }
    } catch {
      // Fallback
    }
  }, [activeTaxonomy]);

  const loadTerms = useCallback(async () => {
    if (!activeTaxonomy) return;
    setLoading(true);
    try {
      const res = await apiClient.request<TermItem[] | { data: TermItem[] }>(
        'GET',
        `/taxonomies/${activeTaxonomy.handle}/terms`
      );
      if (res.ok && res.data) {
        const list = Array.isArray(res.data) ? res.data : (res.data as any).data || [];
        setTerms(list);
      } else {
        setTerms([]);
      }
    } catch {
      setTerms([]);
    } finally {
      setLoading(false);
    }
  }, [activeTaxonomy]);

  useEffect(() => {
    loadTaxonomies();
  }, [loadTaxonomies]);

  useEffect(() => {
    loadTerms();
  }, [loadTerms]);

  // Hierarchical tree structure
  const termTree = useMemo(() => {
    if (!activeTaxonomy?.is_hierarchical) return terms;
    const map = new Map<string, TermItem>();
    const roots: TermItem[] = [];

    for (const t of terms) {
      map.set(t.id, { ...t, children: [] });
    }

    for (const t of terms) {
      const node = map.get(t.id)!;
      if (t.parent_id && map.has(t.parent_id)) {
        map.get(t.parent_id)!.children!.push(node);
      } else {
        roots.push(node);
      }
    }

    return roots;
  }, [terms, activeTaxonomy]);

  const toggleExpand = (id: string) => {
    setExpandedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const openCreateDialog = (parentId: string | null = null) => {
    setEditingTerm(null);
    setTermName('');
    setTermSlug('');
    setTermDescription('');
    setTermParentId(parentId || 'root');
    setTermColor('#3b82f6');
    setTermDialogOpen(true);
  };

  const openEditDialog = (term: TermItem) => {
    setEditingTerm(term);
    setTermName(term.name);
    setTermSlug(term.slug);
    setTermDescription(term.description || '');
    setTermParentId(term.parent_id || 'root');
    setTermColor(term.color || '#3b82f6');
    setTermDialogOpen(true);
  };

  const handleSaveTerm = async () => {
    if (!activeTaxonomy || !termName.trim()) return;
    setSavingTerm(true);
    const payload = {
      name: termName.trim(),
      slug: termSlug.trim() || termName.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
      description: termDescription.trim() || undefined,
      parent_id: termParentId === 'root' ? null : termParentId,
      color: termColor,
    };

    try {
      if (editingTerm) {
        const res = await apiClient.request<TermItem>(
          'PATCH',
          `/taxonomies/${activeTaxonomy.handle}/terms/${editingTerm.id}`,
          { body: payload }
        );
        if (res.ok) {
          successToast('Term updated.');
          loadTerms();
          setTermDialogOpen(false);
        } else {
          errorToast('Failed to update term.');
        }
      } else {
        const res = await apiClient.request<TermItem>(
          'POST',
          `/taxonomies/${activeTaxonomy.handle}/terms`,
          { body: payload }
        );
        if (res.ok) {
          successToast('Term created.');
          loadTerms();
          setTermDialogOpen(false);
        } else {
          errorToast('Failed to create term.');
        }
      }
    } catch {
      errorToast('Failed to save term.');
    } finally {
      setSavingTerm(false);
    }
  };

  const handleDeleteTerm = async (id: string) => {
    if (!activeTaxonomy) return;
    if (!window.confirm('Delete this taxonomy term? Associated entries will have this term removed.')) return;
    try {
      await apiClient.request('DELETE', `/taxonomies/${activeTaxonomy.handle}/terms/${id}`);
      successToast('Term deleted.');
      loadTerms();
    } catch {
      errorToast('Failed to delete term.');
    }
  };

  const handleMergeTerms = async () => {
    if (!activeTaxonomy || !mergeSourceTerm || !mergeTargetTermId) return;
    setMerging(true);
    try {
      const res = await apiClient.request('POST', `/taxonomies/${activeTaxonomy.handle}/terms/merge`, {
        body: {
          source_id: mergeSourceTerm.id,
          target_id: mergeTargetTermId,
        },
      });
      if (res.ok) {
        successToast('Terms successfully merged.');
        setMergeDialogOpen(false);
        loadTerms();
      } else {
        errorToast('Failed to merge terms.');
      }
    } catch {
      errorToast('Failed to merge terms.');
    } finally {
      setMerging(false);
    }
  };

  const handleMoveTermHierarchy = async (termId: string, newParentId: string | null) => {
    if (!activeTaxonomy) return;
    try {
      await apiClient.request('PATCH', `/taxonomies/${activeTaxonomy.handle}/terms/${termId}`, {
        body: { parent_id: newParentId },
      });
      loadTerms();
    } catch {
      errorToast('Failed to move term.');
    }
  };

  const renderTreeItem = (node: TermItem, depth = 0) => {
    const hasChildren = node.children && node.children.length > 0;
    const isExpanded = expandedIds.has(node.id);

    return (
      <div key={node.id} className="space-y-1">
        <div
          className="flex items-center justify-between p-2 rounded-md border border-border bg-card hover:bg-surface-50 transition-colors text-xs group"
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

            <div
              className="w-2.5 h-2.5 rounded-full shrink-0"
              style={{ backgroundColor: node.color || '#3b82f6' }}
            />

            <span className="font-medium text-foreground truncate">{node.name}</span>
            <span className="text-[11px] font-mono text-muted-foreground truncate">/{node.slug}</span>

            {node.count !== undefined && (
              <Badge variant="secondary" className="text-[10px] py-0">
                {node.count} {node.count === 1 ? 'entry' : 'entries'}
              </Badge>
            )}
          </div>

          <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
            <Button
              size="sm"
              variant="ghost"
              onClick={() => handleMoveTermHierarchy(node.id, null)}
              className="h-6 px-1.5 text-[11px] text-muted-foreground hover:text-foreground"
              title="Move to root level"
            >
              Root
            </Button>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => openCreateDialog(node.id)}
              className="h-6 px-1.5 text-[11px] text-muted-foreground hover:text-foreground"
              title="Add Child Subcategory"
            >
              <Plus className="h-3 w-3 mr-0.5" />
              Child
            </Button>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => {
                setMergeSourceTerm(node);
                setMergeTargetTermId('');
                setMergeDialogOpen(true);
              }}
              className="h-6 px-1.5 text-[11px] text-muted-foreground hover:text-foreground"
              title="Merge into another term"
            >
              <GitMerge className="h-3 w-3 mr-0.5" />
              Merge
            </Button>
            <Button
              size="sm"
              variant="outline"
              onClick={() => openEditDialog(node)}
              className="h-6 px-2 text-[11px]"
            >
              <Edit2 className="h-3 w-3" />
            </Button>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => handleDeleteTerm(node.id)}
              className="h-6 w-6 p-0 text-destructive"
            >
              <Trash2 className="h-3 w-3" />
            </Button>
          </div>
        </div>

        {hasChildren && isExpanded && (
          <div className="space-y-1">
            {node.children!.map((child) => renderTreeItem(child, depth + 1))}
          </div>
        )}
      </div>
    );
  };

  return (
    <PageContainer width="full" className="space-y-4">
      <div className="flex items-center justify-between border-b border-border pb-4">
        <PageHeader
          title="Taxonomy & Terms Manager"
          description="Manage hierarchical categories, flat tags, custom classifications, and SEO taxonomies."
        />
        <div className="flex items-center gap-2">
          <Button
            size="sm"
            onClick={() => openCreateDialog(null)}
            className="text-xs h-8 gap-1.5"
          >
            <Plus className="h-4 w-4" />
            Add New {activeTaxonomy?.name ? activeTaxonomy.name.replace(/s$/, '') : 'Term'}
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 min-h-[500px]">
        {/* Left Column: Taxonomies List */}
        <div className="lg:col-span-3 rounded-md border border-border bg-card p-3 space-y-2">
          <div className="flex items-center justify-between pb-2 border-b border-border">
            <span className="text-xs font-semibold uppercase tracking-wider text-foreground-lighter">
              Vocabularies
            </span>
          </div>

          <div className="space-y-1">
            {taxonomies.map((tax) => (
              <button
                key={tax.handle}
                type="button"
                onClick={() => setActiveTaxonomy(tax)}
                className={cn(
                  'flex items-center justify-between w-full px-2.5 py-1.5 text-xs rounded-md text-left transition-colors',
                  activeTaxonomy?.handle === tax.handle
                    ? 'bg-primary text-primary-foreground font-medium'
                    : 'text-foreground hover:bg-surface-100'
                )}
              >
                <div className="flex items-center gap-2 truncate">
                  {tax.is_hierarchical ? (
                    <FolderTree className="h-3.5 w-3.5 shrink-0" />
                  ) : (
                    <Tag className="h-3.5 w-3.5 shrink-0" />
                  )}
                  <span className="truncate">{tax.name}</span>
                </div>
                <Badge
                  variant={activeTaxonomy?.handle === tax.handle ? 'outline' : 'secondary'}
                  className="text-[9px] py-0 px-1"
                >
                  {tax.is_hierarchical ? 'Tree' : 'Flat'}
                </Badge>
              </button>
            ))}
          </div>
        </div>

        {/* Right Column: Terms Tree / Table */}
        <div className="lg:col-span-9 flex flex-col space-y-3">
          <div className="flex flex-wrap items-center justify-between gap-2 p-2 rounded-md border border-border bg-card">
            <div className="flex items-center gap-2 flex-1 max-w-sm">
              <div className="relative flex-1">
                <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                <Input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder={`Search ${activeTaxonomy?.name || 'terms'}...`}
                  className="pl-8 text-xs h-8"
                />
              </div>
            </div>

            <div className="flex items-center gap-2">
              {activeTaxonomy?.is_hierarchical && (
                <>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setExpandedIds(new Set(terms.map((t) => t.id)))}
                    className="h-8 text-xs"
                  >
                    Expand All
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setExpandedIds(new Set())}
                    className="h-8 text-xs"
                  >
                    Collapse All
                  </Button>
                </>
              )}
              <Button
                size="sm"
                variant="outline"
                onClick={loadTerms}
                disabled={loading}
                className="h-8 text-xs"
              >
                <RefreshCw className={cn('h-3.5 w-3.5 mr-1', loading && 'animate-spin')} />
                Refresh
              </Button>
            </div>
          </div>

          {loading ? (
            <div className="flex-1 flex items-center justify-center min-h-[300px]">
              <Spinner className="h-8 w-8 text-primary" />
            </div>
          ) : terms.length === 0 ? (
            <div className="flex-1 flex flex-col items-center justify-center border border-dashed border-border rounded-md text-center p-12 text-muted-foreground bg-card space-y-2">
              <Tags className="h-10 w-10 mb-1 stroke-1" />
              <p className="text-sm font-medium">No terms found</p>
              <p className="text-xs">Add your first {activeTaxonomy?.name?.toLowerCase() || 'term'} to begin.</p>
              <Button size="sm" onClick={() => openCreateDialog(null)} className="text-xs mt-3">
                Add First Term
              </Button>
            </div>
          ) : activeTaxonomy?.is_hierarchical ? (
            <div className="space-y-1.5 p-3 rounded-md border border-border bg-card">
              {termTree.map((root) => renderTreeItem(root, 0))}
            </div>
          ) : (
            <div className="rounded-md border border-border bg-card overflow-hidden">
              <table className="w-full text-xs text-left">
                <thead className="bg-surface-100 border-b border-border text-foreground font-semibold">
                  <tr>
                    <th className="p-2.5">Term Name</th>
                    <th className="p-2.5">Slug</th>
                    <th className="p-2.5">Description</th>
                    <th className="p-2.5">Entries Count</th>
                    <th className="p-2.5 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {terms.map((t) => (
                    <tr key={t.id} className="hover:bg-surface-50">
                      <td className="p-2.5 font-medium text-foreground flex items-center gap-2">
                        <div
                          className="w-2.5 h-2.5 rounded-full shrink-0"
                          style={{ backgroundColor: t.color || '#3b82f6' }}
                        />
                        <span>{t.name}</span>
                      </td>
                      <td className="p-2.5 font-mono text-muted-foreground">/{t.slug}</td>
                      <td className="p-2.5 text-muted-foreground truncate max-w-xs">{t.description || '—'}</td>
                      <td className="p-2.5">
                        <Badge variant="secondary" className="text-[10px] py-0">
                          {t.count ?? 0}
                        </Badge>
                      </td>
                      <td className="p-2.5 text-right space-x-1">
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => openEditDialog(t)}
                          className="h-7 text-xs"
                        >
                          Edit
                        </Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => handleDeleteTerm(t.id)}
                          className="h-7 text-xs text-destructive hover:text-destructive"
                        >
                          Delete
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {/* Create / Edit Term Dialog */}
      <Dialog open={termDialogOpen} onOpenChange={setTermDialogOpen}>
        <DialogContent className="max-w-md bg-background">
          <DialogHeader>
            <DialogTitle className="text-sm font-semibold flex items-center gap-2">
              <Tag className="h-4 w-4 text-primary" />
              <span>{editingTerm ? 'Edit Term' : `Create ${activeTaxonomy?.name?.replace(/s$/, '') || 'Term'}`}</span>
            </DialogTitle>
          </DialogHeader>

          <div className="py-2 space-y-3">
            <div className="space-y-1">
              <Label className="text-xs">Name</Label>
              <Input
                value={termName}
                onChange={(e) => setTermName(e.target.value)}
                placeholder="e.g. Technology, Tutorials, Design"
                className="text-xs h-8"
                autoFocus
              />
            </div>

            <div className="space-y-1">
              <Label className="text-xs">Slug</Label>
              <Input
                value={termSlug}
                onChange={(e) => setTermSlug(e.target.value)}
                placeholder="custom-slug"
                className="text-xs h-8 font-mono"
              />
            </div>

            {activeTaxonomy?.is_hierarchical && (
              <div className="space-y-1">
                <Label className="text-xs">Parent Category</Label>
                <select
                  value={termParentId}
                  onChange={(e) => setTermParentId(e.target.value)}
                  className="w-full text-xs h-8 rounded-md border border-border bg-background px-2"
                >
                  <option value="root">Root (No Parent)</option>
                  {terms
                    .filter((t) => t.id !== editingTerm?.id)
                    .map((t) => (
                      <option key={t.id} value={t.id}>
                        {t.name}
                      </option>
                    ))}
                </select>
              </div>
            )}

            <div className="space-y-1">
              <Label className="text-xs">Accent Colour</Label>
              <div className="flex items-center gap-2">
                <input
                  type="color"
                  value={termColor}
                  onChange={(e) => setTermColor(e.target.value)}
                  className="h-8 w-12 rounded border border-border cursor-pointer p-0.5 bg-background"
                />
                <Input
                  value={termColor}
                  onChange={(e) => setTermColor(e.target.value)}
                  className="text-xs h-8 font-mono w-28"
                />
              </div>
            </div>

            <div className="space-y-1">
              <Label className="text-xs">Description</Label>
              <Textarea
                value={termDescription}
                onChange={(e) => setTermDescription(e.target.value)}
                rows={2}
                placeholder="Optional description for archive pages..."
                className="text-xs resize-none"
              />
            </div>
          </div>

          <DialogFooter className="gap-2">
            <Button variant="outline" size="sm" onClick={() => setTermDialogOpen(false)} className="text-xs">
              Cancel
            </Button>
            <Button
              size="sm"
              onClick={handleSaveTerm}
              disabled={savingTerm || !termName.trim()}
              className="text-xs"
            >
              {savingTerm ? 'Saving...' : 'Save Term'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Merge Terms Dialog */}
      <Dialog open={mergeDialogOpen} onOpenChange={setMergeDialogOpen}>
        <DialogContent className="max-w-md bg-background">
          <DialogHeader>
            <DialogTitle className="text-sm font-semibold flex items-center gap-2">
              <GitMerge className="h-4 w-4 text-primary" />
              <span>Merge Term: {mergeSourceTerm?.name}</span>
            </DialogTitle>
          </DialogHeader>

          <div className="py-2 space-y-3">
            <p className="text-xs text-muted-foreground">
              All entries tagged with <strong>{mergeSourceTerm?.name}</strong> will be reassigned to the selected target term below, and <strong>{mergeSourceTerm?.name}</strong> will be removed.
            </p>

            <div className="space-y-1">
              <Label className="text-xs">Select Target Term to Merge Into</Label>
              <select
                value={mergeTargetTermId}
                onChange={(e) => setMergeTargetTermId(e.target.value)}
                className="w-full text-xs h-8 rounded-md border border-border bg-background px-2"
              >
                <option value="">Choose target term...</option>
                {terms
                  .filter((t) => t.id !== mergeSourceTerm?.id)
                  .map((t) => (
                    <option key={t.id} value={t.id}>
                      {t.name} (/{t.slug})
                    </option>
                  ))}
              </select>
            </div>
          </div>

          <DialogFooter className="gap-2">
            <Button variant="outline" size="sm" onClick={() => setMergeDialogOpen(false)} className="text-xs">
              Cancel
            </Button>
            <Button
              size="sm"
              onClick={handleMergeTerms}
              disabled={merging || !mergeTargetTermId}
              className="text-xs"
            >
              {merging ? 'Merging...' : 'Confirm Merge'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </PageContainer>
  );
}
