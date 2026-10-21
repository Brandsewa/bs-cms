import { useState, useEffect } from 'react';
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetFooter,
  Button,
  Input,
  Label,
  Textarea,
  Separator,
  Badge,
  Spinner,
  errorToast,
  successToast,
} from '@bs/ui';
import {
  Copy,
  Trash2,
  Download,
  Crosshair,
  Sparkles,
  Link2,
  FileText,
} from 'lucide-react';
import { apiClient } from '../lib/api';
import type { MediaItem, MediaFolder } from './media-picker-modal';

export interface MediaDetailSheetProps {
  item: MediaItem | null;
  open: boolean;
  onClose: () => void;
  onUpdate: (updated: MediaItem) => void;
  onDelete: (id: string) => void;
  folders: MediaFolder[];
}

export function MediaDetailSheet({
  item,
  open,
  onClose,
  onUpdate,
  onDelete,
  folders,
}: MediaDetailSheetProps) {
  const [alt, setAlt] = useState('');
  const [caption, setCaption] = useState('');
  const [title, setTitle] = useState('');
  const [folderId, setFolderId] = useState<string | null>(null);
  const [focalPoint, setFocalPoint] = useState<{ x: number; y: number }>({ x: 50, y: 50 });
  const [saving, setSaving] = useState(false);
  const [usageList, setUsageList] = useState<Array<{ id: string; title: string; collection: string }>>([]);
  const [loadingUsage, setLoadingUsage] = useState(false);

  useEffect(() => {
    if (item) {
      setAlt(item.alt || '');
      setCaption(item.caption || '');
      setTitle(item.name || item.original_name || '');
      setFolderId(item.folder_id || null);
      if ((item as any).focal_point) {
        setFocalPoint((item as any).focal_point);
      } else {
        setFocalPoint({ x: 50, y: 50 });
      }

      // Fetch usage
      async function loadUsage() {
        setLoadingUsage(true);
        try {
          const res = await apiClient.request<Array<{ id: string; title: string; collection: string }>>(
            'GET',
            `/media/${item?.id}/usage`
          );
          if (res.ok && Array.isArray(res.data)) {
            setUsageList(res.data);
          } else {
            setUsageList([]);
          }
        } catch {
          setUsageList([]);
        } finally {
          setLoadingUsage(false);
        }
      }
      loadUsage();
    }
  }, [item]);

  if (!item) return null;

  const isImage = item.mime_type?.startsWith('image/');
  const fileUrl = item.url || `/api/v1/public/media/${item.id}/file`;

  const handleSave = async () => {
    setSaving(true);
    try {
      const res = await apiClient.request<MediaItem>('PATCH', `/media/${item.id}`, {
        body: {
          name: title.trim(),
          alt: alt.trim(),
          caption: caption.trim(),
          folder_id: folderId,
          focal_point: focalPoint,
        },
      });

      if (res.ok && res.data) {
        successToast('Media asset metadata saved.');
        onUpdate(res.data);
        onClose();
      } else {
        errorToast('Failed to save media metadata.');
      }
    } catch {
      errorToast('Failed to save media metadata.');
    } finally {
      setSaving(false);
    }
  };

  const handleCopyUrl = () => {
    const full = window.location.origin + fileUrl;
    navigator.clipboard.writeText(full);
    successToast('Asset URL copied to clipboard.');
  };

  const handleFocalClick = (e: React.MouseEvent<HTMLDivElement>) => {
    const rect = e.currentTarget.getBoundingClientRect();
    const x = Math.round(((e.clientX - rect.left) / rect.width) * 100);
    const y = Math.round(((e.clientY - rect.top) / rect.height) * 100);
    setFocalPoint({ x, y });
  };

  return (
    <Sheet open={open} onOpenChange={(val) => !val && onClose()}>
      <SheetContent className="w-full sm:max-w-md overflow-y-auto bg-background p-0 flex flex-col">
        <SheetHeader className="p-4 border-b border-border">
          <SheetTitle className="text-sm font-semibold flex items-center justify-between">
            <span>Asset Details</span>
            <Badge variant="outline" className="text-[10px] font-mono">
              {item.mime_type}
            </Badge>
          </SheetTitle>
        </SheetHeader>

        <div className="p-4 space-y-4 flex-1">
          {/* Asset Preview */}
          <div className="relative rounded-md border border-border bg-surface-100 overflow-hidden flex items-center justify-center min-h-[180px]">
            {isImage ? (
              <div
                className="relative w-full h-48 cursor-crosshair group"
                onClick={handleFocalClick}
                title="Click anywhere on the preview to adjust focal point"
              >
                <img
                  src={fileUrl}
                  alt={alt || item.original_name || 'preview'}
                  className="w-full h-full object-contain"
                />
                {/* Focal point indicator */}
                <div
                  className="absolute -translate-x-1/2 -translate-y-1/2 w-5 h-5 rounded-full border-2 border-primary bg-primary/30 flex items-center justify-center shadow pointer-events-none"
                  style={{ left: `${focalPoint.x}%`, top: `${focalPoint.y}%` }}
                >
                  <Crosshair className="h-3 w-3 text-primary stroke-[3]" />
                </div>
              </div>
            ) : (
              <div className="flex flex-col items-center justify-center p-6 text-muted-foreground">
                <FileText className="h-10 w-10 mb-2 stroke-1" />
                <span className="text-xs">{item.original_name}</span>
              </div>
            )}
          </div>

          {isImage && (
            <p className="text-[11px] text-muted-foreground flex items-center justify-between">
              <span>Focal point: ({focalPoint.x}%, {focalPoint.y}%)</span>
              <button
                type="button"
                onClick={() => setFocalPoint({ x: 50, y: 50 })}
                className="text-primary hover:underline text-[10px]"
              >
                Reset Center
              </button>
            </p>
          )}

          {/* Quick Actions Bar */}
          <div className="flex gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={handleCopyUrl}
              className="flex-1 text-xs h-7"
            >
              <Copy className="h-3 w-3 mr-1" />
              Copy URL
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              asChild
              className="flex-1 text-xs h-7"
            >
              <a href={fileUrl} target="_blank" rel="noopener noreferrer" download>
                <Download className="h-3 w-3 mr-1" />
                Download
              </a>
            </Button>
          </div>

          <Separator className="bg-border" />

          {/* Metadata Form */}
          <div className="space-y-3">
            {/* Title / Name */}
            <div className="space-y-1">
              <Label className="text-xs font-medium">Asset Title / File Name</Label>
              <Input
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="Asset title..."
                className="text-xs h-8"
              />
            </div>

            {/* Alt Text Prominent (docs/02-ui-spec.md 4.6 required prompt) */}
            <div className="space-y-1 rounded-md border border-info/30 bg-info/5 p-3">
              <div className="flex items-center justify-between">
                <Label className="text-xs font-semibold text-foreground flex items-center gap-1.5">
                  <Sparkles className="h-3.5 w-3.5 text-primary" />
                  Alternative Text (Accessibility & SEO)
                </Label>
                <span className="text-[10px] text-muted-foreground">Required</span>
              </div>
              <Textarea
                value={alt}
                onChange={(e) => setAlt(e.target.value)}
                placeholder="Describe this image for screen readers and search engines..."
                rows={2}
                className="text-xs resize-none"
              />
              <p className="text-[10px] text-muted-foreground">
                Screen readers announce this text for visually impaired readers.
              </p>
            </div>

            {/* Caption */}
            <div className="space-y-1">
              <Label className="text-xs font-medium">Caption</Label>
              <Input
                value={caption}
                onChange={(e) => setCaption(e.target.value)}
                placeholder="Optional figure caption..."
                className="text-xs h-8"
              />
            </div>

            {/* Folder selection */}
            <div className="space-y-1">
              <Label className="text-xs font-medium">Folder Organization</Label>
              <select
                value={folderId || 'none'}
                onChange={(e) => setFolderId(e.target.value === 'none' ? null : e.target.value)}
                className="w-full text-xs h-8 rounded-md border border-border bg-background px-2"
              >
                <option value="none">Root (No Folder)</option>
                {folders.map((f) => (
                  <option key={f.id} value={f.id}>
                    {f.name}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <Separator className="bg-border" />

          {/* Usage List */}
          <div className="space-y-2">
            <span className="text-xs font-semibold uppercase tracking-wider text-foreground-lighter flex items-center gap-1.5">
              <Link2 className="h-3.5 w-3.5 text-muted-foreground" />
              Usage in Content ({usageList.length})
            </span>
            {loadingUsage ? (
              <div className="flex justify-center p-3">
                <Spinner className="h-4 w-4 text-primary" />
              </div>
            ) : usageList.length === 0 ? (
              <p className="text-[11px] text-muted-foreground italic">
                This asset is not currently referenced by any posts or pages. Safe to delete.
              </p>
            ) : (
              <div className="space-y-1 max-h-32 overflow-y-auto pr-1">
                {usageList.map((u) => (
                  <div
                    key={u.id}
                    className="flex items-center justify-between p-1.5 rounded border border-border bg-surface-50 text-xs"
                  >
                    <span className="font-medium truncate max-w-[200px]">{u.title}</span>
                    <Badge variant="outline" className="text-[10px] py-0">
                      {u.collection}
                    </Badge>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        <SheetFooter className="p-3 border-t border-border bg-surface-50 flex items-center justify-between sm:justify-between">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => {
              if (usageList.length > 0) {
                errorToast(`Cannot delete: asset is used in ${usageList.length} entries.`);
                return;
              }
              if (window.confirm('Are you sure you want to delete this media asset permanently?')) {
                onDelete(item.id);
                onClose();
              }
            }}
            className="text-xs text-destructive hover:text-destructive h-8"
          >
            <Trash2 className="h-3.5 w-3.5 mr-1" />
            Delete Asset
          </Button>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={onClose} className="text-xs h-8">
              Cancel
            </Button>
            <Button size="sm" onClick={handleSave} disabled={saving} className="text-xs h-8">
              {saving ? 'Saving...' : 'Save Changes'}
            </Button>
          </div>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  );
}
