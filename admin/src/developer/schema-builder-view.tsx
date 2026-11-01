import { FC, useState, useEffect, useCallback } from 'react';
import {
  Badge,
  Breadcrumbs,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  EmptyState,
  Input,
  Label,
  PageContainer,
  PageHeader,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Switch,
  Tabs,
  TabsList,
  TabsTrigger,
  SortableList,
  SortableItem,
  SortableDragHandle,
} from '@bs/ui';
import {
  Database,
  Plus,
  Trash2,
  Eye,
  CheckCircle2,
      AlertTriangle,
  Tags,
} from 'lucide-react';
import { apiClient } from '../lib/api';
import { problemToast } from '../lib/problem-toast';
import { StepUpDialog } from '../auth/step-up-dialog';

export interface CollectionField {
  name: string;
  type: string;
  label: string;
  required?: boolean;
  filterable?: boolean;
  sortable?: boolean;
  searchable?: boolean;
  options?: unknown;
}

export interface CollectionSchema {
  id?: string;
  handle: string;
  name_singular: string;
  name_plural: string;
  description?: string;
  icon?: string;
  route_pattern?: string;
  fields: CollectionField[];
  taxonomies?: string[];
  relations?: Array<{ target_collection: string; relation_type: string; field_name: string }>;
}

export interface TaxonomyInfo {
  handle: string;
  label_singular: string;
  label_plural: string;
  hierarchical: boolean;
  is_system?: boolean;
}

/** Field primitives offered by the builder; values must match the backend FieldTypeRegistry. */
const FIELD_TYPES: Array<{ value: string; label: string }> = [
  { value: 'text', label: 'Text (Single-line)' },
  { value: 'textarea', label: 'Long Text / Textarea' },
  { value: 'richtext', label: 'Rich Text (Editor)' },
  { value: 'markdown', label: 'Markdown' },
  { value: 'number', label: 'Number' },
  { value: 'boolean', label: 'Toggle / Boolean' },
  { value: 'date', label: 'Date' },
  { value: 'datetime', label: 'Date & Time' },
  { value: 'enum', label: 'Select (Single)' },
  { value: 'multi_enum', label: 'Select (Multiple)' },
  { value: 'slug', label: 'Slug' },
  { value: 'email', label: 'Email' },
  { value: 'url', label: 'URL' },
  { value: 'color', label: 'Color' },
  { value: 'json', label: 'JSON' },
  { value: 'media', label: 'Media Asset Picker' },
  { value: 'relation', label: 'Relation (Foreign Collection)' },
  { value: 'taxonomy', label: 'Taxonomy (Terms)' },
];

function fieldToTarget(f: CollectionField): Record<string, unknown> {
  const def: Record<string, unknown> = {
    type: f.type,
    label: f.label,
    required: Boolean(f.required),
    is_filterable: Boolean(f.filterable),
    is_sortable: Boolean(f.sortable),
    is_searchable: Boolean(f.searchable),
  };
  const opts = (f.options && typeof f.options === 'object' ? f.options : {}) as Record<string, unknown>;
  if (f.type === 'relation') {
    def.config = { target_collection: opts.target_collection || '' };
  } else if (f.type === 'taxonomy') {
    def.config = {
      taxonomy: opts.taxonomy || f.name,
      multiple: opts.multiple !== false,
    };
  }
  return def;
}

/** Local fields array -> backend `fields` map keyed by field handle. */
function fieldsToTargetMap(fields: CollectionField[]): Record<string, unknown> {
  const map: Record<string, unknown> = {};
  for (const f of fields) {
    if (!f.name) continue;
    map[f.name] = fieldToTarget(f);
  }
  return map;
}

function normalizeCollection(raw: unknown): CollectionSchema {
  const col = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>;
  const handle = String(col.handle || '');
  const singular = String(col.label_singular || col.name_singular || handle);
  const plural = String(col.label_plural || col.name_plural || `${handle}s`);
  let fields: CollectionField[] = [];

  if (Array.isArray(col.fields)) {
    fields = col.fields.map((f: unknown) => {
      const fieldObj = (f && typeof f === 'object' ? f : {}) as Record<string, unknown>;
      return {
        name: String(fieldObj.name || fieldObj.handle || ''),
        label: String(fieldObj.label || fieldObj.name || fieldObj.handle || ''),
        type: String(fieldObj.type || 'text'),
        required: Boolean(fieldObj.required),
        filterable: Boolean(fieldObj.is_filterable ?? fieldObj.filterable),
        sortable: Boolean(fieldObj.is_sortable ?? fieldObj.sortable),
        searchable: Boolean(fieldObj.is_searchable ?? fieldObj.searchable),
        options: fieldObj.config,
      };
    });
  } else if (col.fields && typeof col.fields === 'object') {
    fields = Object.entries(col.fields as Record<string, unknown>).map(([fieldKey, f]) => {
      const fieldObj = (f && typeof f === 'object' ? f : {}) as Record<string, unknown>;
      const config = (fieldObj.config && typeof fieldObj.config === 'object' ? fieldObj.config : {}) as Record<string, unknown>;
      return {
        name: fieldKey,
        label: String(fieldObj.label || fieldKey),
        type: String(fieldObj.type || 'text'),
        required: Boolean(fieldObj.required),
        filterable: Boolean(fieldObj.is_filterable ?? fieldObj.filterable),
        sortable: Boolean(fieldObj.is_sortable ?? fieldObj.sortable),
        searchable: Boolean(fieldObj.is_searchable ?? fieldObj.searchable),
        options: Object.keys(config).length > 0 ? config : fieldObj.options,
      };
    });
  }

  return {
    id: col.id ? String(col.id) : undefined,
    handle,
    name_singular: singular,
    name_plural: plural,
    description: col.description ? String(col.description) : undefined,
    icon: col.icon ? String(col.icon) : undefined,
    route_pattern: col.route_pattern ? String(col.route_pattern) : undefined,
    fields,
    taxonomies: Array.isArray(col.taxonomies) ? (col.taxonomies as string[]) : [],
    relations: Array.isArray(col.relations)
      ? (col.relations as Array<{ target_collection: string; relation_type: string; field_name: string }>)
      : [],
  };
}

interface PlanResult {
  change_id: string;
  sql_up: string[];
  risk_flags: Record<string, unknown>;
  estimated_rows?: number;
}

export const SchemaBuilderView: FC = () => {
  const [collections, setCollections] = useState<CollectionSchema[]>([]);
  const [selectedHandle, setSelectedHandle] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  // Active collection being edited
  const [currentSchema, setCurrentSchema] = useState<CollectionSchema | null>(null);
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [activeTab, setActiveTab] = useState<'fields' | 'taxonomies' | 'preview' | 'json' | 'apply'>('fields');

  // Add field dialog
  const [addFieldOpen, setAddFieldOpen] = useState(false);
  const [newFieldName, setNewFieldName] = useState('');
  const [newFieldLabel, setNewFieldLabel] = useState('');
  const [newFieldType, setNewFieldType] = useState('text');
  const [newFieldRequired, setNewFieldRequired] = useState(false);
  const [newFieldFilterable, setNewFieldFilterable] = useState(false);
  const [newFieldSortable, setNewFieldSortable] = useState(false);
  const [newFieldTarget, setNewFieldTarget] = useState('');

  // New Collection Dialog
  const [createColOpen, setCreateColOpen] = useState(false);
  const [newColHandle, setNewColHandle] = useState('');
  const [newColSingular, setNewColSingular] = useState('');
  const [newColPlural, setNewColPlural] = useState('');
  const [newColDescription, setNewColDescription] = useState('');
  const [creatingCol, setCreatingCol] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);

  // Plan / Apply
  const [planResult, setPlanResult] = useState<PlanResult | null>(null);
  const [planning, setPlanning] = useState(false);
  const [applying, setApplying] = useState(false);
  const [appliedSuccess, setAppliedSuccess] = useState(false);
  const [stepUpOpen, setStepUpOpen] = useState(false);

  // Taxonomies
  const [taxonomies, setTaxonomies] = useState<TaxonomyInfo[]>([]);
  const [taxLoading, setTaxLoading] = useState(false);
  const [newTaxOpen, setNewTaxOpen] = useState(false);
  const [newTaxHandle, setNewTaxHandle] = useState('');
  const [newTaxSingular, setNewTaxSingular] = useState('');
  const [newTaxPlural, setNewTaxPlural] = useState('');
  const [newTaxHierarchical, setNewTaxHierarchical] = useState(false);
  const [creatingTax, setCreatingTax] = useState(false);
  const [attachingHandle, setAttachingHandle] = useState<string | null>(null);

  const loadCollectionDetail = useCallback(async (handle: string) => {
    const res = await apiClient.request<unknown>('GET', `/collections/${handle}`);
    if (!res.ok) {
      problemToast(res.problem, { fallbackMessage: 'Failed to load collection detail' });
    } else if (res.data) {
      setCurrentSchema(normalizeCollection(res.data));
      setDirty(false);
      setPlanResult(null);
      setAppliedSuccess(false);
    }
  }, []);

  const loadTaxonomies = useCallback(async () => {
    setTaxLoading(true);
    try {
      const res = await apiClient.request<unknown[]>('GET', '/taxonomies');
      if (res.ok && Array.isArray(res.data)) {
        setTaxonomies(
          res.data.map((t) => {
            const tax = (t && typeof t === 'object' ? t : {}) as Record<string, unknown>;
            return {
              handle: String(tax.handle || ''),
              label_singular: String(tax.label_singular || tax.handle || ''),
              label_plural: String(tax.label_plural || `${tax.handle}s`),
              hierarchical: Boolean(tax.hierarchical),
              is_system: Boolean(tax.is_system),
            };
          }),
        );
      }
    } finally {
      setTaxLoading(false);
    }
  }, []);

  const loadCollections = useCallback(async () => {
    setLoading(true);
    try {
      const res = await apiClient.request<unknown>('GET', '/collections');
      if (res.ok) {
        let rawList: unknown[] = [];
        if (Array.isArray(res.data)) {
          rawList = res.data;
        } else if (res.data && typeof res.data === 'object' && Array.isArray((res.data as Record<string, unknown>).items)) {
          rawList = (res.data as { items: unknown[] }).items;
        }
        const normalizedList = rawList.map(normalizeCollection);
        setCollections(normalizedList);
        setSelectedHandle((prevSelected) => {
          const keep = prevSelected && normalizedList.some((c) => c.handle === prevSelected)
            ? prevSelected
            : normalizedList[0]?.handle;
          if (keep) {
            void loadCollectionDetail(keep);
          }
          return keep ?? null;
        });
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to load collections' });
      }
    } catch (err) {
      problemToast(err, { fallbackMessage: 'Error connecting to schema service' });
    } finally {
      setLoading(false);
    }
  }, [loadCollectionDetail]);

  useEffect(() => {
    loadCollections();
    loadTaxonomies();
  }, [loadCollections, loadTaxonomies]);

  const handleSelectCollection = (col: CollectionSchema) => {
    setSelectedHandle(col.handle);
    void loadCollectionDetail(col.handle);
  };

  const handleAddField = () => {
    if (!currentSchema || !newFieldName.trim()) return;

    const field: CollectionField = {
      name: newFieldName.trim().toLowerCase().replace(/[^a-z0-9_]/g, '_'),
      label: newFieldLabel || newFieldName,
      type: newFieldType,
      required: newFieldRequired,
      filterable: newFieldFilterable,
      sortable: newFieldSortable,
      options: newFieldType === 'relation' ? { target_collection: newFieldTarget } : undefined,
    };

    setCurrentSchema({
      ...currentSchema,
      fields: [...currentSchema.fields, field],
    });
    setDirty(true);

    setAddFieldOpen(false);
    setNewFieldName('');
    setNewFieldLabel('');
    setNewFieldType('text');
    setNewFieldRequired(false);
    setNewFieldFilterable(false);
    setNewFieldSortable(false);
    setNewFieldTarget('');
  };

  const handleRemoveField = (index: number) => {
    if (!currentSchema) return;
    const updated = [...currentSchema.fields];
    updated.splice(index, 1);
    setCurrentSchema({ ...currentSchema, fields: updated });
    setDirty(true);
  };

  const handleReorderFields = (ids: string[]) => {
    if (!currentSchema) return;
    const byName = new Map(currentSchema.fields.map((f) => [f.name, f]));
    const fields = ids.map((id) => byName.get(id)).filter((f): f is NonNullable<typeof f> => Boolean(f));
    if (fields.length !== currentSchema.fields.length) return;
    setCurrentSchema({ ...currentSchema, fields });
    setDirty(true);
  };

  const collectionTargetBody = (schema: CollectionSchema): Record<string, unknown> => ({
    handle: schema.handle,
    label_singular: schema.name_singular,
    label_plural: schema.name_plural,
    description: schema.description ?? '',
    has_public_pages: true,
    route_pattern: schema.route_pattern || `/${schema.handle}/{slug}`,
    title_field: 'title',
    slug_source: 'title',
    fields: fieldsToTargetMap(schema.fields),
  });

  const handleCreateCollection = async () => {
    if (!newColHandle.trim()) return;
    setCreatingCol(true);
    setCreateError(null);
    const handle = newColHandle.trim().toLowerCase().replace(/[^a-z0-9_]/g, '_');
    try {
      const res = await apiClient.request<unknown>('POST', '/collections', {
        body: collectionTargetBody({
          handle,
          name_singular: newColSingular || newColHandle,
          name_plural: newColPlural || `${newColHandle}s`,
          description: newColDescription || undefined,
          fields: [],
        }),
      });
      if (res.ok) {
        setCreateColOpen(false);
        setNewColHandle('');
        setNewColSingular('');
        setNewColPlural('');
        setNewColDescription('');
        setSelectedHandle(handle);
        await loadCollections();
        await loadCollectionDetail(handle);
      } else {
        setCreateError(res.problem.detail || 'Failed to create collection.');
      }
    } catch {
      setCreateError('A network error occurred while creating the collection.');
    } finally {
      setCreatingCol(false);
    }
  };

  const handleSaveSchema = async () => {
    if (!currentSchema) return;
    setSaving(true);
    try {
      const res = await apiClient.request<unknown>('PUT', `/collections/${currentSchema.handle}`, {
        body: collectionTargetBody(currentSchema),
      });
      if (res.ok) {
        setDirty(false);
        await loadCollections();
        await loadCollectionDetail(currentSchema.handle);
        problemToast(null, { fallbackMessage: 'Schema saved and applied.' });
      } else if (res.status === 409) {
        problemToast(res.problem, {
          fallbackMessage:
            'This change removes data. Use the Apply Plan tab to review and confirm the migration.',
        });
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to save schema.' });
      }
    } catch {
      problemToast(null, { fallbackMessage: 'Network error while saving schema.' });
    } finally {
      setSaving(false);
    }
  };

  const handleRunPlan = async () => {
    if (!currentSchema) return;
    setPlanning(true);
    try {
      const res = await apiClient.request<PlanResult>('POST', '/schema/plan', {
        body: collectionTargetBody(currentSchema),
      });
      if (!res.ok) {
        problemToast(res.problem, { fallbackMessage: 'Failed to generate migration plan.' });
      } else if (res.data) {
        setPlanResult(res.data);
      }
    } catch {
      problemToast(null, { fallbackMessage: 'Error generating migration plan.' });
    } finally {
      setPlanning(false);
    }
  };

  const applyPlan = async (changeId: string) => {
    setApplying(true);
    try {
      const res = await apiClient.request<unknown>('POST', `/schema/apply/${changeId}`);
      if (res.ok) {
        setAppliedSuccess(true);
        setPlanResult(null);
        loadCollections();
      } else if (res.status === 403) {
        setStepUpOpen(true);
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to apply migration plan.' });
      }
    } catch {
      problemToast(null, { fallbackMessage: 'Error applying migration plan.' });
    } finally {
      setApplying(false);
    }
  };

  const handleCreateTaxonomy = async () => {
    if (!newTaxHandle.trim()) return;
    setCreatingTax(true);
    try {
      const handle = newTaxHandle.trim().toLowerCase().replace(/[^a-z0-9_]/g, '_');
      const res = await apiClient.request<unknown>('POST', '/taxonomies', {
        body: {
          handle,
          label_singular: newTaxSingular || newTaxHandle,
          label_plural: newTaxPlural || `${newTaxHandle}s`,
          hierarchical: newTaxHierarchical,
        },
      });
      if (res.ok) {
        setNewTaxOpen(false);
        setNewTaxHandle('');
        setNewTaxSingular('');
        setNewTaxPlural('');
        setNewTaxHierarchical(false);
        await loadTaxonomies();
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to create taxonomy.' });
      }
    } catch {
      problemToast(null, { fallbackMessage: 'Network error while creating taxonomy.' });
    } finally {
      setCreatingTax(false);
    }
  };

  const handleAttachTaxonomy = async (taxHandle: string) => {
    if (!currentSchema) return;
    setAttachingHandle(taxHandle);
    try {
      const res = await apiClient.request<unknown>('POST', `/taxonomies/${taxHandle}/attach`, {
        body: {
          collection_handle: currentSchema.handle,
          field_key: taxHandle,
          allow_multiple: true,
        },
      });
      if (res.ok) {
        // Attaching only writes the registry pivot; the entry repository syncs
        // terms through a taxonomy-type field, so add that to the collection too.
        const taxLabel = taxonomies.find((t) => t.handle === taxHandle)?.label_singular || taxHandle;
        const next: CollectionSchema = {
          ...currentSchema,
          fields: [
            ...currentSchema.fields.filter((f) => f.name !== taxHandle),
            { name: taxHandle, label: taxLabel, type: 'taxonomy', options: { taxonomy: taxHandle, multiple: true } },
          ],
          taxonomies: [...(currentSchema.taxonomies || []), taxHandle],
        };
        const saveRes = await apiClient.request<unknown>('PUT', `/collections/${currentSchema.handle}`, {
          body: collectionTargetBody(next),
        });
        if (saveRes.ok) {
          setCurrentSchema(next);
          setDirty(false);
          problemToast(null, { fallbackMessage: `Taxonomy "${taxHandle}" attached to ${next.name_plural}.` });
        } else {
          problemToast(saveRes.problem, { fallbackMessage: 'Attached, but adding the term field failed.' });
        }
      } else {
        problemToast(res.problem, { fallbackMessage: 'Failed to attach taxonomy.' });
      }
    } catch {
      problemToast(null, { fallbackMessage: 'Network error while attaching taxonomy.' });
    } finally {
      setAttachingHandle(null);
    }
  };

  return (
    <PageContainer width="full" className="space-y-6">
      <Breadcrumbs
        items={[
          { label: 'Admin', href: '/admin/' },
          { label: 'Developer', href: '/admin/developer' },
          { label: 'Schema Builder' },
        ]}
      />

      <PageHeader
        title="Schema Builder"
        description="Design content collections, configure typed fields, attach taxonomies, and apply migrations with a reviewed plan."
        actions={
          <Button
            size="sm"
            onClick={() => setCreateColOpen(true)}
            data-testid="new-collection-btn"
          >
            <Plus className="mr-1.5 h-3.5 w-3.5" /> New Collection
          </Button>
        }
      />

      {loading && collections.length === 0 ? (
        <div className="flex min-h-[300px] flex-col items-center justify-center gap-3">
          <Spinner size={28} />
          <p className="text-13 text-muted-foreground">Loading collection schemas...</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-6 items-start">
          {/* Collection Sidebar List */}
          <Card className="p-3 space-y-1">
            <p className="text-12 font-semibold text-muted-foreground uppercase tracking-wider px-2 py-1">
              Collections
            </p>
            {collections.map((col) => (
              <button
                key={col.handle}
                onClick={() => handleSelectCollection(col)}
                className={`w-full flex items-center justify-between px-3 py-2 text-13 rounded-md transition-colors text-left ${
                  selectedHandle === col.handle
                    ? 'bg-primary text-primary-foreground font-medium'
                    : 'hover:bg-muted text-foreground'
                }`}
              >
                <div className="flex items-center gap-2 truncate">
                  <Database className="h-4 w-4 shrink-0" />
                  <span className="truncate">{col.name_plural || col.handle}</span>
                </div>
                <span className="text-11 opacity-70 font-mono">{col.fields?.length ?? 0}</span>
              </button>
            ))}
          </Card>

          {/* Schema Workspace Area */}
          <div className="md:col-span-3 space-y-4">
            {currentSchema ? (
              <Card>
                <CardHeader className="pb-3 border-b border-border">
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                      <div className="flex items-center gap-2">
                        <CardTitle>{currentSchema.name_plural}</CardTitle>
                        <Badge variant="outline" className="font-mono text-11">
                          {currentSchema.handle}
                        </Badge>
                        {dirty && (
                          <Badge variant="outline" className="text-[10px] text-warning">
                            Unsaved changes
                          </Badge>
                        )}
                      </div>
                      <CardDescription>
                        {currentSchema.description || `Collection schema definition for ${currentSchema.name_plural}.`}
                      </CardDescription>
                    </div>

                    <Tabs value={activeTab} onValueChange={(val) => setActiveTab(val as typeof activeTab)}>
                      <TabsList>
                        <TabsTrigger value="fields">Fields ({currentSchema.fields.length})</TabsTrigger>
                        <TabsTrigger value="taxonomies">Taxonomies</TabsTrigger>
                        <TabsTrigger value="preview">Live Preview</TabsTrigger>
                        <TabsTrigger value="json">Schema Code</TabsTrigger>
                        <TabsTrigger value="apply">Apply Plan</TabsTrigger>
                      </TabsList>
                    </Tabs>
                  </div>
                </CardHeader>

                <CardContent className="pt-4">
                  {/* TAB: FIELDS LIST */}
                  {activeTab === 'fields' && (
                    <div className="space-y-4">
                      <div className="flex items-center justify-between gap-3">
                        <p className="text-13 text-muted-foreground">
                          Order fields for editor presentation. Filterable fields will be indexed for fast query lookups.
                        </p>
                        <div className="flex gap-2">
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setAddFieldOpen(true)}
                            data-testid="add-field-btn"
                          >
                            <Plus className="mr-1.5 h-3.5 w-3.5" /> Add Field
                          </Button>
                          <Button size="sm" loading={saving} disabled={!dirty} onClick={handleSaveSchema}>
                            Save Changes
                          </Button>
                        </div>
                      </div>

                      {currentSchema.fields.length === 0 ? (
                        <EmptyState
                          title="No custom fields yet"
                          description="Add typed fields to extend this collection beyond its built-in title and slug."
                        />
                      ) : (
                        <SortableList
                          className="divide-y divide-border rounded-md border border-border"
                          items={currentSchema.fields.map((f) => f.name)}
                          onReorder={handleReorderFields}
                        >
                          {currentSchema.fields.map((field) => (
                            <SortableItem
                              key={field.name}
                              id={field.name}
                              className="p-3 flex items-center justify-between gap-3 text-13 hover:bg-muted/30 bg-card"
                            >
                              <div className="flex items-center gap-3">
                                <SortableDragHandle label={`Reorder ${field.label}`} />

                                <div>
                                  <div className="flex items-center gap-2">
                                    <span className="font-semibold text-foreground">{field.label}</span>
                                    <code className="text-11 font-mono text-muted-foreground">({field.name})</code>
                                    <Badge variant="secondary" className="text-[10px] uppercase font-mono">
                                      {field.type}
                                    </Badge>
                                    {field.required && (
                                      <Badge variant="outline" className="text-[10px] text-destructive">
                                        Required
                                      </Badge>
                                    )}
                                  </div>
                                  <div className="flex items-center gap-3 text-11 text-muted-foreground pt-0.5">
                                    {field.filterable && <span>• Filterable (Indexed)</span>}
                                    {field.sortable && <span>• Sortable</span>}
                                    {field.searchable && <span>• Searchable</span>}
                                    {field.type === 'relation' && !field.filterable && (field.options as { target_collection?: string } | undefined)?.target_collection && (
                                      <span>• → {(field.options as { target_collection?: string }).target_collection}</span>
                                    )}
                                  </div>
                                </div>
                              </div>

                              <Button
                                variant="ghost"
                                size="sm"
                                className="text-destructive hover:bg-destructive/10"
                                onClick={() => handleRemoveField(currentSchema.fields.findIndex((f) => f.name === field.name))}
                              >
                                <Trash2 className="h-3.5 w-3.5" />
                              </Button>
                            </SortableItem>
                          ))}
                        </SortableList>
                      )}
                    </div>
                  )}

                  {/* TAB: TAXONOMIES */}
                  {activeTab === 'taxonomies' && (
                    <div className="space-y-4">
                      <div className="flex items-center justify-between gap-3">
                        <p className="text-13 text-muted-foreground">
                          Create vocabularies and attach them to <strong>{currentSchema.name_plural}</strong> so entries
                          get term pickers (categories, tags, or custom classifications).
                        </p>
                        <Button size="sm" variant="outline" onClick={() => setNewTaxOpen(true)}>
                          <Plus className="mr-1.5 h-3.5 w-3.5" /> New Taxonomy
                        </Button>
                      </div>

                      {taxLoading ? (
                        <div className="flex justify-center py-8"><Spinner size={22} /></div>
                      ) : (
                        <div className="divide-y divide-border rounded-md border border-border">
                          {taxonomies.map((tax) => {
                            const attached = currentSchema.fields.some(
                              (f) =>
                                f.type === 'taxonomy' &&
                                (f.name === tax.handle ||
                                  (f.options as { taxonomy?: string } | undefined)?.taxonomy === tax.handle),
                            );
                            return (
                              <div key={tax.handle} className="p-3 flex items-center justify-between gap-3 text-13">
                                <div className="flex items-center gap-3">
                                  <Tags className="h-4 w-4 text-muted-foreground" />
                                  <div>
                                    <div className="flex items-center gap-2">
                                      <span className="font-semibold text-foreground">{tax.label_plural}</span>
                                      <code className="text-11 font-mono text-muted-foreground">({tax.handle})</code>
                                      {tax.hierarchical && (
                                        <Badge variant="secondary" className="text-[10px]">Hierarchical</Badge>
                                      )}
                                      {tax.is_system && (
                                        <Badge variant="outline" className="text-[10px]">System</Badge>
                                      )}
                                      {attached && (
                                        <Badge variant="outline" className="text-[10px] text-success">Attached</Badge>
                                      )}
                                    </div>
                                  </div>
                                </div>
                                <Button
                                  size="sm"
                                  variant={attached ? 'ghost' : 'outline'}
                                  disabled={attached || attachingHandle === tax.handle}
                                  loading={attachingHandle === tax.handle}
                                  onClick={() => handleAttachTaxonomy(tax.handle)}
                                >
                                  {attached ? 'Attached' : 'Attach'}
                                </Button>
                              </div>
                            );
                          })}
                        </div>
                      )}
                    </div>
                  )}

                  {/* TAB: LIVE FORM PREVIEW */}
                  {activeTab === 'preview' && (
                    <div className="space-y-4 max-w-lg border border-border p-4 rounded-md bg-card">
                      <div className="flex items-center gap-2 border-b border-border pb-2 text-13 font-semibold text-foreground">
                        <Eye className="h-4 w-4 text-primary" /> Live Editor Form Preview
                      </div>
                      {currentSchema.fields.length === 0 && (
                        <p className="text-13 text-muted-foreground">No custom fields to preview yet.</p>
                      )}
                      {currentSchema.fields.map((f) => (
                        <div key={f.name} className="space-y-1.5">
                          <Label htmlFor={`prev_${f.name}`}>
                            {f.label} {f.required && <span className="text-destructive">*</span>}
                          </Label>
                          {f.type === 'textarea' || f.type === 'markdown' ? (
                            <textarea
                              id={`prev_${f.name}`}
                              className="w-full h-20 rounded-md border border-border bg-background p-2 text-13"
                              placeholder={`Enter ${f.label.toLowerCase()}...`}
                              disabled
                            />
                          ) : f.type === 'boolean' ? (
                            <div className="pt-1">
                              <Switch id={`prev_${f.name}`} disabled />
                            </div>
                          ) : (
                            <Input
                              id={`prev_${f.name}`}
                              placeholder={`Enter ${f.label.toLowerCase()}...`}
                              disabled
                            />
                          )}
                        </div>
                      ))}
                    </div>
                  )}

                  {/* TAB: JSON CODE */}
                  {activeTab === 'json' && (
                    <div className="space-y-2">
                      <div className="flex items-center justify-between text-12 text-muted-foreground">
                        <span>Declarative Schema Definition (JSON / Schema-as-Code)</span>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => navigator.clipboard.writeText(JSON.stringify(currentSchema, null, 2))}
                        >
                          Copy JSON
                        </Button>
                      </div>
                      <pre className="p-4 bg-muted/40 border border-border rounded-md font-mono text-12 max-h-96 overflow-y-auto">
                        {JSON.stringify(currentSchema, null, 2)}
                      </pre>
                    </div>
                  )}

                  {/* TAB: APPLY PLAN */}
                  {activeTab === 'apply' && (
                    <div className="space-y-4">
                      <div>
                        <h4 className="text-14 font-semibold text-foreground">Migration Plan & SQL Diff</h4>
                        <p className="text-12 text-muted-foreground">
                          Inspect SQL mutations and structural index updates before committing changes to production.
                        </p>
                      </div>

                      <div className="flex gap-2">
                        <Button
                          variant="outline"
                          size="sm"
                          loading={planning}
                          onClick={handleRunPlan}
                        >
                          Generate Plan Diff
                        </Button>
                      </div>

                      {planResult && (
                        <div className="space-y-4 rounded-md border border-border p-4 bg-card">
                          <div className="flex items-center gap-2 text-13 font-semibold text-foreground">
                            <CheckCircle2 className="h-4 w-4 text-success" />
                            <span>Plan Generated: {planResult.change_id}</span>
                            {typeof planResult.estimated_rows === 'number' && (
                              <Badge variant="outline" className="text-[10px]">
                                ~{planResult.estimated_rows} rows
                              </Badge>
                            )}
                          </div>

                          {planResult.risk_flags && Object.keys(planResult.risk_flags).length > 0 && (
                            <div className="flex items-start gap-2 rounded bg-warning/10 p-3 text-12 text-warning border border-warning/20">
                              <AlertTriangle className="h-4 w-4 shrink-0 mt-0.5" />
                              <div>
                                {Object.entries(planResult.risk_flags)
                                  .filter(([, v]) => Boolean(v))
                                  .map(([k]) => <p key={k}>Risk: {k.replace(/_/g, ' ')}</p>)}
                              </div>
                            </div>
                          )}

                          <pre className="p-3 bg-muted/40 rounded border border-border font-mono text-12 overflow-x-auto text-foreground">
                            {planResult.sql_up && planResult.sql_up.length > 0
                              ? planResult.sql_up.join('\n')
                              : '-- No DDL alterations required.'}
                          </pre>

                          <div className="flex items-center justify-between pt-2">
                            {appliedSuccess && (
                              <span className="text-13 text-success-text font-medium">
                                Schema migrations successfully executed!
                              </span>
                            )}
                            <Button
                              className="ml-auto"
                              loading={applying}
                              onClick={() => applyPlan(planResult.change_id)}
                            >
                              Confirm & Apply Schema
                            </Button>
                          </div>
                        </div>
                      )}
                    </div>
                  )}
                </CardContent>
              </Card>
            ) : (
              <EmptyState
                title="Select a collection"
                description="Choose an existing collection schema or create a new collection to begin editing."
              />
            )}
          </div>
        </div>
      )}

      {/* Add Field Dialog */}
      <Dialog open={addFieldOpen} onOpenChange={setAddFieldOpen}>
        <DialogContent className="max-w-md">
          <div className="space-y-4">
            <DialogHeader>
              <DialogTitle>Add Schema Field</DialogTitle>
              <DialogDescription>
                Select a field primitive and specify validation / indexing rules.
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-3 py-2">
              <div className="space-y-1.5">
                <Label htmlFor="field_name">Field Name (Machine Key)</Label>
                <Input
                  id="field_name"
                  placeholder="e.g. star_rating"
                  value={newFieldName}
                  onChange={(e) => setNewFieldName(e.target.value)}
                  required
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="field_label">Label (UI Display)</Label>
                <Input
                  id="field_label"
                  placeholder="e.g. Star Rating"
                  value={newFieldLabel}
                  onChange={(e) => setNewFieldLabel(e.target.value)}
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="field_type">Field Type</Label>
                <Select value={newFieldType} onValueChange={setNewFieldType}>
                  <SelectTrigger id="field_type">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {FIELD_TYPES.map((t) => (
                      <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {newFieldType === 'relation' && (
                <div className="space-y-1.5">
                  <Label htmlFor="field_target">Target Collection</Label>
                  <Select value={newFieldTarget} onValueChange={setNewFieldTarget}>
                    <SelectTrigger id="field_target">
                      <SelectValue placeholder="Select collection" />
                    </SelectTrigger>
                    <SelectContent>
                      {collections.map((c) => (
                        <SelectItem key={c.handle} value={c.handle}>{c.name_plural}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              )}

              <div className="space-y-2 pt-2">
                <div className="flex items-center justify-between">
                  <Label htmlFor="req_switch" className="cursor-pointer">Required</Label>
                  <Switch id="req_switch" checked={newFieldRequired} onCheckedChange={setNewFieldRequired} />
                </div>
                <div className="flex items-center justify-between">
                  <Label htmlFor="filt_switch" className="cursor-pointer">Filterable (Generates DB Index)</Label>
                  <Switch id="filt_switch" checked={newFieldFilterable} onCheckedChange={setNewFieldFilterable} />
                </div>
                <div className="flex items-center justify-between">
                  <Label htmlFor="sort_switch" className="cursor-pointer">Sortable</Label>
                  <Switch id="sort_switch" checked={newFieldSortable} onCheckedChange={setNewFieldSortable} />
                </div>
              </div>
            </div>

            <DialogFooter>
              <Button variant="outline" onClick={() => setAddFieldOpen(false)}>
                Cancel
              </Button>
              <Button onClick={handleAddField} disabled={!newFieldName.trim()}>
                Add Field
              </Button>
            </DialogFooter>
          </div>
        </DialogContent>
      </Dialog>

      {/* New Collection Dialog */}
      <Dialog open={createColOpen} onOpenChange={setCreateColOpen}>
        <DialogContent className="max-w-md">
          <div className="space-y-4">
            <DialogHeader>
              <DialogTitle>Create New Collection</DialogTitle>
              <DialogDescription>
                Creates the content type and its database table immediately. Add fields afterwards.
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-3 py-2">
              <div className="space-y-1.5">
                <Label htmlFor="col_handle">Handle (Machine Key)</Label>
                <Input
                  id="col_handle"
                  placeholder="e.g. hotels"
                  value={newColHandle}
                  onChange={(e) => setNewColHandle(e.target.value)}
                  required
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="col_sing">Singular Name</Label>
                <Input
                  id="col_sing"
                  placeholder="e.g. Hotel"
                  value={newColSingular}
                  onChange={(e) => setNewColSingular(e.target.value)}
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="col_plur">Plural Name</Label>
                <Input
                  id="col_plur"
                  placeholder="e.g. Hotels"
                  value={newColPlural}
                  onChange={(e) => setNewColPlural(e.target.value)}
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="col_desc">Description (optional)</Label>
                <Input
                  id="col_desc"
                  placeholder="e.g. Hotel listings with amenities and locations"
                  value={newColDescription}
                  onChange={(e) => setNewColDescription(e.target.value)}
                />
              </div>

              {createError && (
                <p className="text-13 text-destructive" role="alert">{createError}</p>
              )}
            </div>

            <DialogFooter>
              <Button variant="outline" onClick={() => setCreateColOpen(false)}>
                Cancel
              </Button>
              <Button loading={creatingCol} onClick={handleCreateCollection} disabled={!newColHandle.trim()}>
                Create Collection
              </Button>
            </DialogFooter>
          </div>
        </DialogContent>
      </Dialog>

      {/* New Taxonomy Dialog */}
      <Dialog open={newTaxOpen} onOpenChange={setNewTaxOpen}>
        <DialogContent className="max-w-md">
          <div className="space-y-4">
            <DialogHeader>
              <DialogTitle>Create Taxonomy</DialogTitle>
              <DialogDescription>
                A taxonomy is a vocabulary of terms (like Categories or Tags) that can be attached to collections.
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-3 py-2">
              <div className="space-y-1.5">
                <Label htmlFor="tax_handle">Handle (Machine Key)</Label>
                <Input
                  id="tax_handle"
                  placeholder="e.g. amenities"
                  value={newTaxHandle}
                  onChange={(e) => setNewTaxHandle(e.target.value)}
                  required
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="tax_sing">Singular Name</Label>
                <Input
                  id="tax_sing"
                  placeholder="e.g. Amenity"
                  value={newTaxSingular}
                  onChange={(e) => setNewTaxSingular(e.target.value)}
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="tax_plur">Plural Name</Label>
                <Input
                  id="tax_plur"
                  placeholder="e.g. Amenities"
                  value={newTaxPlural}
                  onChange={(e) => setNewTaxPlural(e.target.value)}
                />
              </div>

              <div className="flex items-center justify-between pt-1">
                <Label htmlFor="tax_hier" className="cursor-pointer">
                  Hierarchical (like Categories, with parent/child terms)
                </Label>
                <Switch id="tax_hier" checked={newTaxHierarchical} onCheckedChange={setNewTaxHierarchical} />
              </div>
            </div>

            <DialogFooter>
              <Button variant="outline" onClick={() => setNewTaxOpen(false)}>
                Cancel
              </Button>
              <Button loading={creatingTax} onClick={handleCreateTaxonomy} disabled={!newTaxHandle.trim()}>
                Create Taxonomy
              </Button>
            </DialogFooter>
          </div>
        </DialogContent>
      </Dialog>

      {/* Step-up (re-authentication) for applying migrations */}
      <StepUpDialog
        open={stepUpOpen}
        onOpenChange={setStepUpOpen}
        title="Confirm your identity to apply this migration"
        description="Applying a schema migration is a sensitive action and requires re-authentication."
        onSuccess={() => {
          if (planResult) {
            void applyPlan(planResult.change_id);
          }
        }}
      />
    </PageContainer>
  );
};
