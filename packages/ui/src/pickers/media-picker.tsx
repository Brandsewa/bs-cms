import { Search, Upload, FolderOpen } from 'lucide-react';
import { useId, useMemo, useRef, useState } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { Button } from '../components/button';
import { Input } from '../components/input';
import { Progress } from '../components/progress';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../components/select';
import { Spinner } from '../components/spinner';
import { EmptyState } from '../layout/states';

export interface PickerMediaItem {
  id: string;
  filename: string;
  mediaUrl?: string;
  mime: string;
  folderId?: string | null;
}

export interface PickerFolder {
  id: string;
  label: string;
}

export interface UploadHandlerResult {
  id: string;
  filename: string;
  mime: string;
  mediaUrl?: string;
  folderId?: string | null;
}

export interface MediaPickerProps {
  items: PickerMediaItem[];
  folders: PickerFolder[];
  selectedId?: string | null;
  onSelect: (item: PickerMediaItem) => void;
  /**
   * Uploads a file (screens wire this to uploadChunked from @bs/client).
   * When absent the upload button is hidden.
   */
  uploader?: (file: File) => Promise<UploadHandlerResult>;
  /** Restrict the grid to these MIME prefixes, e.g. ['image/']. */
  mimeFilter?: string[];
  className?: string;
  'aria-label'?: string;
}

/**
 * Media picker (docs/02-ui-spec.md 4.6 picker form): folder filter, search by
 * filename, thumbnail grid with single select, optional upload with
 * progress. Grid items are buttons — fully keyboard navigable.
 */
export function MediaPicker({
  items,
  folders,
  selectedId,
  onSelect,
  uploader,
  mimeFilter,
  className,
  'aria-label': ariaLabel,
}: MediaPickerProps) {
  const { t } = useI18n();
  const searchId = useId();
  const [query, setQuery] = useState('');
  const [activeFolder, setActiveFolder] = useState<string | 'all'>('all');
  const [uploading, setUploading] = useState<{ done: number; total: number } | null>(null);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const visible = useMemo(() => {
    return items.filter((item) => {
      if (mimeFilter && !mimeFilter.some((prefix) => item.mime.startsWith(prefix))) return false;
      if (activeFolder !== 'all' && (item.folderId ?? null) !== activeFolder) return false;
      if (query && !item.filename.toLowerCase().includes(query.toLowerCase())) return false;
      return true;
    });
  }, [items, mimeFilter, activeFolder, query]);

  const handleFiles = async (files: FileList | null) => {
    if (!uploader || !files || files.length === 0) return;
    setUploadError(null);
    setUploading({ done: 0, total: files.length });
    try {
      for (const file of Array.from(files)) {
        const uploaded = await uploader(file);
        setUploading((previous) =>
          previous ? { done: previous.done + 1, total: previous.total } : previous
        );
        onSelect({ ...uploaded, folderId: uploaded.folderId ?? activeFolder });
      }
    } catch (error) {
      setUploadError(error instanceof Error ? error.message : String(error));
    } finally {
      setUploading(null);
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
    }
  };

  return (
    <div className={cn('flex flex-col gap-2', className)} role="group" aria-label={ariaLabel}>
      <div className="flex items-center gap-2">
        <div className="relative min-w-0 flex-1">
          <Search
            className="pointer-events-none absolute left-2 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
          />
          <Input
            id={searchId}
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder={t('pickers.mediaSearch')}
            aria-label={t('pickers.mediaSearch')}
            className="pl-8"
          />
        </div>
        {folders.length > 0 ? (
          <Select value={activeFolder} onValueChange={setActiveFolder}>
            <SelectTrigger aria-label={t('pickers.mediaFolder')} className="w-40">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t('pickers.mediaAllFolders')}</SelectItem>
              {folders.map((folder) => (
                <SelectItem key={folder.id} value={folder.id}>
                  {folder.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        ) : null}
        {uploader ? (
          <>
            <input
              ref={fileInputRef}
              type="file"
              multiple
              className="sr-only"
              aria-label={t('pickers.mediaUpload')}
              onChange={(event) => void handleFiles(event.target.files)}
            />
            <Button
              size="sm"
              variant="outline"
              disabled={uploading !== null}
              onClick={() => fileInputRef.current?.click()}
            >
              {uploading ? <Spinner size={14} /> : <Upload aria-hidden="true" />}
              {t('pickers.mediaUpload')}
            </Button>
          </>
        ) : null}
      </div>

      {uploading ? (
        <div
          role="status"
          aria-label={t('pickers.mediaUploading', { done: uploading.done, total: uploading.total })}
          className="space-y-1"
        >
          <Progress
            value={Math.round((uploading.done / Math.max(1, uploading.total)) * 100)}
            aria-label={t('pickers.mediaUpload')}
          />
          <p className="text-xs text-muted-foreground">
            {t('pickers.mediaUploading', { done: uploading.done, total: uploading.total })}
          </p>
        </div>
      ) : null}
      {uploadError ? (
        <p role="alert" className="text-xs text-destructive-text">
          {uploadError}
        </p>
      ) : null}

      {visible.length === 0 ? (
        <EmptyState icon={<FolderOpen />} title={t('pickers.mediaEmpty')} className="py-8" />
      ) : (
        <ul
          role="listbox"
          aria-label={ariaLabel}
          className="grid max-h-72 grid-cols-[repeat(auto-fill,minmax(96px,1fr))] gap-2 overflow-y-auto rounded-md border border-border p-2"
        >
          {visible.map((item) => {
            const isSelected = item.id === selectedId;
            return (
              <li
                key={item.id}
                role="option"
                aria-selected={isSelected}
                tabIndex={0}
                onClick={() => onSelect(item)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onSelect(item);
                  }
                }}
                className={cn(
                  'flex w-full cursor-pointer flex-col items-center gap-1 rounded-md border p-1.5 text-xs transition-colors',
                  'hover:bg-surface-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                  isSelected && 'border-primary bg-accent'
                )}
              >
                  {item.mediaUrl ? (
                    <img
                      src={item.mediaUrl}
                      alt=""
                      width={80}
                      height={56}
                      loading="lazy"
                      className="h-14 w-full rounded-sm object-cover"
                    />
                  ) : (
                    <span
                      aria-hidden="true"
                      className="flex h-14 w-full items-center justify-center rounded-sm bg-surface-200 text-muted-foreground"
                    >
                      <FolderOpen className="h-5 w-5" />
                    </span>
                  )}
                  <span className="w-full truncate text-muted-foreground">{item.filename}</span>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
