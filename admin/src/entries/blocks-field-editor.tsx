import { useState, useEffect } from 'react';
import {
  Card,
  CardHeader,
  CardContent,
  Button,
  Input,
  Label,
  Textarea,
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
  Spinner,
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  Badge,
} from '@bs/ui';
import {
  Plus,
  Trash2,
  ChevronUp,
  ChevronDown,
  Copy,
  Layers,
  Layout,
  Sparkles,
} from 'lucide-react';
import { apiClient } from '../lib/api';

export interface BlockFieldDefinition {
  name: string;
  label: string;
  type: string;
  required?: boolean;
  default?: any;
  options?: string[];
  description?: string;
}

export interface BlockDefinition {
  id: string; // block name (e.g. 'hero', 'callout', 'faq', 'text')
  name: string; // display label
  category?: string;
  description?: string;
  icon?: string;
  fields: BlockFieldDefinition[];
}

export interface BlockInstance {
  id: string; // unique client-side key for rendering
  type: string; // matches def.id / registry name
  data: Record<string, any>;
  [key: string]: any;
}

export interface BlocksFieldEditorProps {
  value: BlockInstance[];
  onChange: (blocks: BlockInstance[]) => void;
  disabled?: boolean;
}

/**
 * Normalizes raw registry definitions from GET /api/v1/blocks into BlockDefinition list.
 */
function normalizeRegistryBlocks(raw: unknown): BlockDefinition[] {
  let list: unknown[] = [];
  if (Array.isArray(raw)) {
    list = raw;
  } else if (raw && typeof raw === 'object' && Array.isArray((raw as any).data)) {
    list = (raw as any).data;
  }

  const defs: BlockDefinition[] = [];
  for (const item of list) {
    if (!item || typeof item !== 'object') continue;
    const obj = item as Record<string, any>;
    const blockKey = String(obj.name || obj.id || '');
    if (!blockKey) continue;

    const label = String(obj.label || obj.name || blockKey);
    const category = obj.category ? String(obj.category) : undefined;
    const description = obj.description ? String(obj.description) : undefined;
    const icon = obj.icon ? String(obj.icon) : undefined;

    const fields: BlockFieldDefinition[] = [];
    if (obj.fields && typeof obj.fields === 'object') {
      if (Array.isArray(obj.fields)) {
        for (const f of obj.fields) {
          if (!f || typeof f !== 'object') continue;
          fields.push({
            name: f.name || f.handle || '',
            label: f.label || f.name || '',
            type: f.type || 'text',
            required: Boolean(f.required),
            default: f.default,
            options: Array.isArray(f.options) ? f.options : Array.isArray(f.enum) ? f.enum : undefined,
            description: f.description,
          });
        }
      } else {
        // Map of fieldName -> fieldConfig
        for (const [fName, fConf] of Object.entries(obj.fields)) {
          if (!fConf || typeof fConf !== 'object') continue;
          const fc = fConf as Record<string, any>;
          fields.push({
            name: fName,
            label: fc.label || fName,
            type: fc.type || 'text',
            required: Boolean(fc.required),
            default: fc.default,
            options: Array.isArray(fc.options) ? fc.options : Array.isArray(fc.enum) ? fc.enum : undefined,
            description: fc.description,
          });
        }
      }
    }

    defs.push({
      id: blockKey,
      name: label,
      category,
      description,
      icon,
      fields,
    });
  }

  return defs;
}

export function BlocksFieldEditor({
  value = [],
  onChange,
  disabled = false,
}: BlocksFieldEditorProps) {
  const [definitions, setDefinitions] = useState<BlockDefinition[]>([]);
  const [loading, setLoading] = useState(false);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [search, setSearch] = useState('');

  useEffect(() => {
    async function loadDefinitions() {
      setLoading(true);
      try {
        const res = await apiClient.request<unknown>('GET', '/blocks');
        if (res.ok && res.data) {
          const parsed = normalizeRegistryBlocks(res.data);
          if (parsed.length > 0) {
            setDefinitions(parsed);
            return;
          }
        }

        // Fallback default definitions matching core/resources/blocks
        setDefinitions([
          {
            id: 'hero',
            name: 'Hero Section',
            category: 'layout',
            description: 'Large banner with heading, text, and call-to-action',
            fields: [
              { name: 'heading', label: 'Heading', type: 'string', required: true },
              { name: 'text', label: 'Text', type: 'string' },
              { name: 'cta_label', label: 'Button Label', type: 'string' },
              { name: 'cta_url', label: 'Button URL', type: 'string' },
            ],
          },
          {
            id: 'callout',
            name: 'Callout Box',
            category: 'content',
            description: 'Highlighted notice with icon and variant styling',
            fields: [
              { name: 'variant', label: 'Variant', type: 'select', options: ['info', 'tip', 'warning', 'note'], default: 'info' },
              { name: 'title', label: 'Title', type: 'string' },
              { name: 'content', label: 'Message', type: 'string', required: true },
            ],
          },
          {
            id: 'text',
            name: 'Rich Text Block',
            category: 'content',
            description: 'Standard rich text or markdown paragraph',
            fields: [
              { name: 'content', label: 'Content', type: 'textarea', required: true },
            ],
          },
          {
            id: 'faq',
            name: 'FAQ Block',
            category: 'content',
            description: 'Expandable questions and answers feeding FAQPage structured data',
            fields: [
              { name: 'items', label: 'Questions & Answers (JSON array)', type: 'textarea' },
            ],
          },
        ]);
      } catch {
        // Fallback default
      } finally {
        setLoading(false);
      }
    }
    loadDefinitions();
  }, []);

  const handleAddBlock = (def: BlockDefinition) => {
    const defaultData: Record<string, any> = {};
    for (const f of def.fields) {
      defaultData[f.name] = f.default !== undefined ? f.default : '';
    }

    const newInstance: BlockInstance = {
      id: `blk_${Date.now()}_${Math.random().toString(36).substring(2, 7)}`,
      type: def.id,
      data: defaultData,
    };

    onChange([...value, newInstance]);
    setPickerOpen(false);
  };

  const handleUpdateBlockData = (index: number, fieldName: string, val: any) => {
    const updated = [...value];
    const target = updated[index];
    if (target) {
      updated[index] = {
        ...target,
        data: {
          ...target.data,
          [fieldName]: val,
        },
      };
      onChange(updated);
    }
  };

  const handleDeleteBlock = (index: number) => {
    onChange(value.filter((_, i) => i !== index));
  };

  const handleMoveUp = (index: number) => {
    if (index === 0) return;
    const copy = [...value];
    const temp = copy[index];
    const prev = copy[index - 1];
    if (temp && prev) {
      copy[index] = prev;
      copy[index - 1] = temp;
      onChange(copy);
    }
  };

  const handleMoveDown = (index: number) => {
    if (index >= value.length - 1) return;
    const copy = [...value];
    const temp = copy[index];
    const next = copy[index + 1];
    if (temp && next) {
      copy[index] = next;
      copy[index + 1] = temp;
      onChange(copy);
    }
  };

  const handleDuplicate = (index: number) => {
    const item = value[index];
    if (!item) return;
    const copy: BlockInstance = {
      ...item,
      id: `blk_${Date.now()}_${Math.random().toString(36).substring(2, 7)}`,
      data: { ...item.data },
    };
    const nextList = [...value];
    nextList.splice(index + 1, 0, copy);
    onChange(nextList);
  };

  const filteredDefinitions = definitions.filter((d) =>
    search ? d.name.toLowerCase().includes(search.toLowerCase()) || d.description?.toLowerCase().includes(search.toLowerCase()) || d.id.toLowerCase().includes(search.toLowerCase()) : true
  );

  return (
    <div className="space-y-4">
      {/* Header and Add Button */}
      <div className="flex items-center justify-between">
        <div>
          <span className="text-xs font-semibold uppercase tracking-wider text-foreground-lighter flex items-center gap-1.5">
            <Layers className="h-3.5 w-3.5 text-muted-foreground" />
            Page Section Blocks ({value.length})
          </span>
          <p className="text-[11px] text-muted-foreground">
            Compose and reorder dynamic page modules.
          </p>
        </div>
        <Button
          type="button"
          size="sm"
          onClick={() => setPickerOpen(true)}
          disabled={disabled}
          className="text-xs h-7"
        >
          <Plus className="h-3.5 w-3.5 mr-1" />
          Add Section Block
        </Button>
      </div>

      {/* Block List */}
      {value.length === 0 ? (
        <div className="flex flex-col items-center justify-center p-8 border border-dashed border-border rounded-lg text-center bg-surface-50 text-muted-foreground space-y-2">
          <Layout className="h-8 w-8 stroke-1" />
          <p className="text-xs font-medium">No page blocks added yet</p>
          <p className="text-[11px]">Add Hero, Feature, or CTA sections to build this page.</p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => setPickerOpen(true)}
            className="text-xs h-7 mt-2"
          >
            Browse Block Registry
          </Button>
        </div>
      ) : (
        <div className="space-y-3">
          {value.map((blk, idx) => {
            const blockType = blk.type || blk.block_id || '';
            const def = definitions.find((d) => d.id === blockType);
            const blockName = def?.name || blk.name || blockType;

            return (
              <Card key={blk.id || `blk-${idx}`} className="border-border bg-card">
                <CardHeader className="p-3 bg-surface-100 border-b border-border flex flex-row items-center justify-between space-y-0">
                  <div className="flex items-center gap-2">
                    <span className="flex h-5 w-5 items-center justify-center rounded bg-surface-200 text-[10px] font-mono text-muted-foreground font-semibold">
                      {idx + 1}
                    </span>
                    <span className="text-xs font-semibold text-foreground">
                      {blockName}
                    </span>
                    <Badge variant="outline" className="text-[10px] font-mono py-0">
                      {blockType}
                    </Badge>
                  </div>

                  <div className="flex items-center gap-1">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => handleMoveUp(idx)}
                      disabled={idx === 0 || disabled}
                      className="h-6 w-6 p-0 text-muted-foreground hover:text-foreground"
                      aria-label="Move up"
                    >
                      <ChevronUp className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => handleMoveDown(idx)}
                      disabled={idx === value.length - 1 || disabled}
                      className="h-6 w-6 p-0 text-muted-foreground hover:text-foreground"
                      aria-label="Move down"
                    >
                      <ChevronDown className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => handleDuplicate(idx)}
                      disabled={disabled}
                      className="h-6 w-6 p-0 text-muted-foreground hover:text-foreground"
                      aria-label="Duplicate"
                    >
                      <Copy className="h-3 w-3" />
                    </Button>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => handleDeleteBlock(idx)}
                      disabled={disabled}
                      className="h-6 w-6 p-0 text-destructive"
                      aria-label="Delete"
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                </CardHeader>

                <CardContent className="p-4 space-y-3">
                  {def && def.fields.length > 0 ? (
                    def.fields.map((field) => (
                      <div key={field.name} className="space-y-1">
                        <Label className="text-xs font-medium text-foreground-light">
                          {field.label} {field.required && <span className="text-destructive">*</span>}
                        </Label>
                        {field.type === 'textarea' || field.type === 'richtext' || (field.name === 'items' && !field.options) ? (
                          <Textarea
                            value={
                              typeof blk.data[field.name] === 'object'
                                ? JSON.stringify(blk.data[field.name], null, 2)
                                : blk.data[field.name] || ''
                            }
                            onChange={(e) => {
                              let val: any = e.target.value;
                              if (field.name === 'items') {
                                try {
                                  val = JSON.parse(e.target.value);
                                } catch {
                                  // Keep string while editing
                                }
                              }
                              handleUpdateBlockData(idx, field.name, val);
                            }}
                            rows={field.name === 'items' ? 4 : 2}
                            disabled={disabled}
                            className="text-xs resize-none"
                          />
                        ) : field.type === 'select' || (field.options && field.options.length > 0) ? (
                          <Select
                            value={String(blk.data[field.name] || (field.options ? field.options[0] : ''))}
                            onValueChange={(val) => handleUpdateBlockData(idx, field.name, val)}
                            disabled={disabled}
                          >
                            <SelectTrigger className="text-xs h-8">
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              {(field.options || []).map((opt) => (
                                <SelectItem key={opt} value={opt}>
                                  {opt}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        ) : (
                          <Input
                            type={field.type === 'number' ? 'number' : 'text'}
                            value={blk.data[field.name] ?? ''}
                            onChange={(e) => handleUpdateBlockData(idx, field.name, e.target.value)}
                            disabled={disabled}
                            className="text-xs h-8"
                          />
                        )}
                      </div>
                    ))
                  ) : (
                    <div className="space-y-1">
                      <Label className="text-xs font-medium text-foreground-light">Block JSON Data</Label>
                      <Textarea
                        value={JSON.stringify(blk.data, null, 2)}
                        onChange={(e) => {
                          try {
                            const parsed = JSON.parse(e.target.value);
                            const updated = [...value];
                            const cur = updated[idx];
                            if (cur) {
                              updated[idx] = { ...cur, data: parsed };
                              onChange(updated);
                            }
                          } catch {
                            // Ignored
                          }
                        }}
                        rows={3}
                        className="font-mono text-xs"
                      />
                    </div>
                  )}
                </CardContent>
              </Card>
            );
          })}
        </div>
      )}

      {/* Block Picker Modal */}
      <Dialog open={pickerOpen} onOpenChange={setPickerOpen}>
        <DialogContent className="max-w-2xl bg-background">
          <DialogHeader>
            <DialogTitle className="text-sm font-semibold flex items-center gap-2">
              <Sparkles className="h-4 w-4 text-primary" />
              <span>Registered Section Blocks</span>
            </DialogTitle>
          </DialogHeader>

          <div className="py-2 space-y-3">
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Filter blocks by name or description..."
              className="text-xs h-8"
            />

            {loading ? (
              <div className="flex justify-center p-6">
                <Spinner className="h-6 w-6 text-primary" />
              </div>
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5 max-h-[380px] overflow-y-auto pr-1">
                {filteredDefinitions.map((def) => (
                  <div
                    key={def.id}
                    onClick={() => handleAddBlock(def)}
                    className="p-3 border border-border hover:border-primary rounded-md bg-surface-50 hover:bg-surface-100 cursor-pointer transition-colors space-y-1"
                  >
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-semibold text-foreground">{def.name}</span>
                      {def.category && (
                        <Badge variant="secondary" className="text-[10px] capitalize py-0">
                          {def.category}
                        </Badge>
                      )}
                    </div>
                    <p className="text-[11px] text-muted-foreground line-clamp-2">
                      {def.description || 'Custom structured block definition.'}
                    </p>
                  </div>
                ))}
              </div>
            )}
          </div>

          <DialogFooter>
            <Button size="sm" variant="outline" onClick={() => setPickerOpen(false)} className="text-xs">
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
