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
  type: 'text' | 'textarea' | 'number' | 'select' | 'richtext' | 'repeater';
  required?: boolean;
  default?: any;
  options?: string[];
}

export interface BlockDefinition {
  id: string;
  name: string;
  category?: string;
  description?: string;
  fields: BlockFieldDefinition[];
}

export interface BlockInstance {
  id: string; // unique instance id
  block_id: string; // reference to block definition
  name?: string;
  data: Record<string, any>;
}

export interface BlocksFieldEditorProps {
  value: BlockInstance[];
  onChange: (blocks: BlockInstance[]) => void;
  disabled?: boolean;
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
        const res = await apiClient.request<BlockDefinition[]>('GET', '/blocks');
        if (res.ok && Array.isArray(res.data)) {
          setDefinitions(res.data);
        } else {
          // Fallback static starter blocks
          setDefinitions([
            {
              id: 'hero',
              name: 'Hero Section',
              category: 'header',
              description: 'Large hero banner with headline, CTA and background.',
              fields: [
                { name: 'title', label: 'Headline', type: 'text', required: true },
                { name: 'subtitle', label: 'Subhead', type: 'textarea' },
                { name: 'cta_text', label: 'CTA Text', type: 'text' },
                { name: 'cta_url', label: 'CTA URL', type: 'text' },
              ],
            },
            {
              id: 'features-grid',
              name: 'Features Grid',
              category: 'content',
              description: 'Grid cards with icons and descriptions.',
              fields: [
                { name: 'headline', label: 'Section Heading', type: 'text' },
                { name: 'columns', label: 'Number of Columns', type: 'number', default: 3 },
              ],
            },
            {
              id: 'cta-banner',
              name: 'Call to Action Banner',
              category: 'conversion',
              description: 'High conversion prompt strip.',
              fields: [
                { name: 'headline', label: 'Headline', type: 'text', required: true },
                { name: 'button_text', label: 'Button Text', type: 'text' },
                { name: 'button_url', label: 'Button Target', type: 'text' },
              ],
            },
          ]);
        }
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
      block_id: def.id,
      name: def.name,
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
    search ? d.name.toLowerCase().includes(search.toLowerCase()) || d.description?.toLowerCase().includes(search.toLowerCase()) : true
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
            const def = definitions.find((d) => d.id === blk.block_id);
            const blockName = def?.name || blk.name || blk.block_id;

            return (
              <Card key={blk.id} className="border-border bg-card">
                <CardHeader className="p-3 bg-surface-100 border-b border-border flex flex-row items-center justify-between space-y-0">
                  <div className="flex items-center gap-2">
                    <span className="flex h-5 w-5 items-center justify-center rounded bg-surface-200 text-[10px] font-mono text-muted-foreground font-semibold">
                      {idx + 1}
                    </span>
                    <span className="text-xs font-semibold text-foreground">
                      {blockName}
                    </span>
                    <Badge variant="outline" className="text-[10px] font-mono py-0">
                      {blk.block_id}
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
                  {def ? (
                    def.fields.map((field) => (
                      <div key={field.name} className="space-y-1">
                        <Label className="text-xs font-medium text-foreground-light">
                          {field.label} {field.required && <span className="text-destructive">*</span>}
                        </Label>
                        {field.type === 'textarea' ? (
                          <Textarea
                            value={blk.data[field.name] || ''}
                            onChange={(e) => handleUpdateBlockData(idx, field.name, e.target.value)}
                            rows={2}
                            disabled={disabled}
                            className="text-xs resize-none"
                          />
                        ) : field.type === 'select' && field.options ? (
                          <Select
                            value={blk.data[field.name] || field.options[0]}
                            onValueChange={(val) => handleUpdateBlockData(idx, field.name, val)}
                            disabled={disabled}
                          >
                            <SelectTrigger className="text-xs h-8">
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              {field.options.map((opt) => (
                                <SelectItem key={opt} value={opt}>
                                  {opt}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        ) : (
                          <Input
                            type={field.type === 'number' ? 'number' : 'text'}
                            value={blk.data[field.name] || ''}
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
