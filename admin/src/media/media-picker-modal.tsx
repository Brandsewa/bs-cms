import { useState, useEffect, useCallback, useRef } from 'react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  Button,
  Input,
  Spinner,
  ScrollArea,
  Badge,
  Tabs,
  TabsList,
  TabsTrigger,
  errorToast,
  successToast,
  cn,
} from '@bs/ui';
import {
  Image as ImageIcon,
  Upload,
  Folder as FolderIcon,
  Search,
  Check,
  FileText,
  RefreshCw,
} from 'lucide-react';
import { apiClient } from '../lib/api';
import { uploadChunked } from '@bs/client';

export interface MediaItem {
  id: string;
  name?: string;
  original_name?: string;
  mime_type?: string;
  size?: number;
  url?: string;
  path?: string;
  alt?: string;
  caption?: string;
  folder_id?: string | null;
  created_at?: string;
  width?: number;
  height?: number;
}

export interface MediaFolder {
  id: string;
  name: string;
  parent_id?: string | null;
}

export interface MediaPickerModalProps {
  open: boolean;
  onClose: () => void;
  onSelect: (item: MediaItem) => void;
  allowedMimeTypes?: string[];
  title?: string;
}

export function MediaPickerModal({
  open,
  onClose,
  onSelect,
  allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'],
  title = 'Select Media Asset',
}: MediaPickerModalProps) {
  const [activeTab, setActiveTab] = useState<'library' | 'upload'>('library');
  const [items, setItems] = useState<MediaItem[]>([]);
  const [folders, setFolders] = useState<MediaFolder[]>([]);
  const [selectedFolderId, setSelectedFolderId] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState('');
  const [selectedItem, setSelectedItem] = useState<MediaItem | null>(null);

  // Upload state
  const [uploadProgress, setUploadProgress] = useState<number | null>(null);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const loadMedia = useCallback(async () => {
    setLoading(true);
    try {
      const query = new URLSearchParams();
      if (selectedFolderId) query.set('folder_id', selectedFolderId);
      if (search.trim()) query.set('search', search.trim());
      const qs = query.toString() ? `?${query.toString()}` : '';

      const res = await apiClient.request<MediaItem[] | { data: MediaItem[] }>('GET', `/media${qs}`);
      if (res.ok && res.data) {
        const list = Array.isArray(res.data) ? res.data : (res.data as { data: MediaItem[] }).data || [];
        // Filter by mime types if specified
        if (allowedMimeTypes.length > 0) {
          setItems(
            list.filter((m) =>
              allowedMimeTypes.some((type) => {
                if (type.endsWith('/*')) {
                  const prefix = type.replace('/*', '');
                  return m.mime_type?.startsWith(prefix);
                }
                return m.mime_type === type;
              })
            )
          );
        } else {
          setItems(list);
        }
      }
    } catch {
      // Fallback safe state
    } finally {
      setLoading(false);
    }
  }, [selectedFolderId, search, allowedMimeTypes]);

  const loadFolders = useCallback(async () => {
    try {
      const res = await apiClient.request<MediaFolder[]>('GET', '/media/folders');
      if (res.ok && Array.isArray(res.data)) {
        setFolders(res.data);
      }
    } catch {
      // Ignore
    }
  }, []);

  useEffect(() => {
    if (open) {
      loadFolders();
      loadMedia();
      setSelectedItem(null);
      setUploadProgress(null);
      setUploadError(null);
    }
  }, [open, loadFolders, loadMedia]);

  const handleFileUpload = async (files: FileList | null) => {
    if (!files || files.length === 0) return;
    const file = files[0];
    if (!file) return;
    setUploadProgress(0);
    setUploadError(null);

    try {
      const result = await uploadChunked(apiClient, file, {
        folderId: selectedFolderId || undefined,
        onProgress: (p) => {
          setUploadProgress(Math.round(p.fraction * 100));
        },
      });

      if (result.ok && result.media) {
        successToast('Image uploaded successfully.');
        const uploaded = result.media as unknown as MediaItem;
        setSelectedItem(uploaded);
        loadMedia();
        setActiveTab('library');
      } else {
        setUploadError(result.error || 'Upload failed.');
        errorToast(result.error || 'Upload failed.');
      }
    } catch (err: any) {
      setUploadError(err?.message || 'Error during file upload.');
      errorToast('Upload failed.');
    } finally {
      setUploadProgress(null);
    }
  };

  const handleConfirmSelect = () => {
    if (selectedItem) {
      onSelect(selectedItem);
      onClose();
    }
  };

  return (
    <Dialog open={open} onOpenChange={(val) => !val && onClose()}>
      <DialogContent className="max-w-4xl max-h-[85vh] flex flex-col p-0 overflow-hidden bg-background">
        <DialogHeader className="p-4 border-b border-border">
          <DialogTitle className="text-sm font-semibold flex items-center justify-between">
            <span>{title}</span>
            <Tabs
              value={activeTab}
              onValueChange={(v) => setActiveTab(v as 'library' | 'upload')}
              className="w-auto"
            >
              <TabsList className="h-8">
                <TabsTrigger value="library" className="text-xs px-3">
                  Media Library
                </TabsTrigger>
                <TabsTrigger value="upload" className="text-xs px-3">
                  Upload File
                </TabsTrigger>
              </TabsList>
            </Tabs>
          </DialogTitle>
        </DialogHeader>

        {activeTab === 'library' && (
          <div className="flex flex-1 min-h-[420px] overflow-hidden">
            {/* Sidebar folders */}
            <div className="w-48 border-r border-border p-3 space-y-1 bg-surface-50 flex flex-col">
              <span className="text-[11px] font-semibold text-muted-foreground uppercase px-2 py-1">
                Folders
              </span>
              <button
                type="button"
                onClick={() => setSelectedFolderId(null)}
                className={cn(
                  'flex items-center gap-2 w-full px-2 py-1.5 text-xs rounded-md text-left transition-colors',
                  selectedFolderId === null
                    ? 'bg-primary text-primary-foreground font-medium'
                    : 'text-foreground hover:bg-surface-200'
                )}
              >
                <FolderIcon className="h-3.5 w-3.5" />
                <span>All Assets</span>
              </button>
              {folders.map((f) => (
                <button
                  key={f.id}
                  type="button"
                  onClick={() => setSelectedFolderId(f.id)}
                  className={cn(
                    'flex items-center gap-2 w-full px-2 py-1.5 text-xs rounded-md text-left transition-colors truncate',
                    selectedFolderId === f.id
                      ? 'bg-primary text-primary-foreground font-medium'
                      : 'text-foreground hover:bg-surface-200'
                  )}
                >
                  <FolderIcon className="h-3.5 w-3.5 shrink-0" />
                  <span className="truncate">{f.name}</span>
                </button>
              ))}
            </div>

            {/* Main content grid */}
            <div className="flex-1 flex flex-col p-4 space-y-3 overflow-hidden">
              <div className="flex items-center justify-between gap-2">
                <div className="relative flex-1 max-w-xs">
                  <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                  <Input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search media by name or alt..."
                    className="pl-8 text-xs h-8"
                  />
                </div>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => loadMedia()}
                  disabled={loading}
                  className="h-8 text-xs"
                >
                  <RefreshCw className={cn('h-3.5 w-3.5 mr-1.5', loading && 'animate-spin')} />
                  Refresh
                </Button>
              </div>

              {loading ? (
                <div className="flex-1 flex items-center justify-center">
                  <Spinner className="h-6 w-6 text-primary" />
                </div>
              ) : items.length === 0 ? (
                <div className="flex-1 flex flex-col items-center justify-center border border-dashed border-border rounded-md text-center p-6 text-muted-foreground">
                  <ImageIcon className="h-8 w-8 mb-2 stroke-1" />
                  <p className="text-xs font-medium">No media assets found</p>
                  <p className="text-[11px]">Upload a file or choose another folder.</p>
                </div>
              ) : (
                <ScrollArea className="flex-1 pr-3">
                  <div className="grid grid-cols-4 sm:grid-cols-5 gap-3">
                    {items.map((item) => {
                      const isSelected = selectedItem?.id === item.id;
                      const isImage = item.mime_type?.startsWith('image/');
                      const previewUrl = item.url || `/api/v1/public/media/${item.id}/file`;

                      return (
                        <div
                          key={item.id}
                          onClick={() => setSelectedItem(item)}
                          className={cn(
                            'group relative aspect-square rounded-md border text-left overflow-hidden cursor-pointer transition-all bg-surface-100 flex flex-col items-center justify-center p-1',
                            isSelected
                              ? 'border-primary ring-2 ring-primary ring-offset-1 ring-offset-background'
                              : 'border-border hover:border-foreground-muted'
                          )}
                        >
                          {isImage ? (
                            <img
                              src={previewUrl}
                              alt={item.alt || item.original_name || 'media thumbnail'}
                              className="h-full w-full object-cover rounded"
                              loading="lazy"
                            />
                          ) : (
                            <div className="flex flex-col items-center justify-center p-2 text-center text-muted-foreground">
                              <FileText className="h-6 w-6 mb-1" />
                              <span className="text-[10px] truncate max-w-full">
                                {item.original_name || item.id}
                              </span>
                            </div>
                          )}

                          {isSelected && (
                            <div className="absolute top-1.5 right-1.5 h-5 w-5 rounded-full bg-primary text-primary-foreground flex items-center justify-center shadow">
                              <Check className="h-3 w-3 stroke-[3]" />
                            </div>
                          )}

                          <div className="absolute inset-x-0 bottom-0 bg-background/80 backdrop-blur-xs p-1 text-[10px] truncate opacity-0 group-hover:opacity-100 transition-opacity">
                            {item.original_name || item.name}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </ScrollArea>
              )}
            </div>
          </div>
        )}

        {activeTab === 'upload' && (
          <div className="p-8 flex flex-col items-center justify-center min-h-[350px]">
            <input
              type="file"
              ref={fileInputRef}
              onChange={(e) => {
                if (e.target.files && e.target.files.length > 0) {
                  handleFileUpload(e.target.files);
                }
              }}
              accept={allowedMimeTypes.join(',')}
              className="hidden"
            />
            <div
              onClick={() => fileInputRef.current?.click()}
              onDragOver={(e) => e.preventDefault()}
              onDrop={(e) => {
                e.preventDefault();
                if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                  handleFileUpload(e.dataTransfer.files);
                }
              }}
              className="w-full max-w-md border-2 border-dashed border-border hover:border-primary rounded-lg p-8 flex flex-col items-center justify-center cursor-pointer transition-colors bg-surface-50 text-center"
            >
              <Upload className="h-10 w-10 text-muted-foreground mb-3 stroke-1" />
              <p className="text-sm font-medium text-foreground">Click or drag file to upload</p>
              <p className="text-xs text-muted-foreground mt-1">
                Supports chunked uploads for large media assets.
              </p>
              {allowedMimeTypes.length > 0 && (
                <div className="flex flex-wrap gap-1 mt-3 justify-center">
                  {allowedMimeTypes.map((t) => (
                    <Badge key={t} variant="secondary" className="text-[10px]">
                      {t}
                    </Badge>
                  ))}
                </div>
              )}
            </div>

            {uploadProgress !== null && (
              <div className="w-full max-w-md mt-4 space-y-1">
                <div className="flex justify-between text-xs text-foreground">
                  <span>Uploading chunked file...</span>
                  <span>{uploadProgress}%</span>
                </div>
                <div className="h-2 w-full bg-surface-200 rounded-full overflow-hidden">
                  <div
                    className="h-full bg-primary transition-all duration-200"
                    style={{ width: `${uploadProgress}%` }}
                  />
                </div>
              </div>
            )}

            {uploadError && (
              <p className="text-xs text-destructive mt-3">{uploadError}</p>
            )}
          </div>
        )}

        <DialogFooter className="p-3 border-t border-border bg-surface-50 flex items-center justify-between sm:justify-between">
          <div className="text-xs text-muted-foreground">
            {selectedItem ? (
              <span>
                Selected: <strong>{selectedItem.original_name || selectedItem.name}</strong>
              </span>
            ) : (
              <span>No asset selected</span>
            )}
          </div>
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={onClose} className="text-xs">
              Cancel
            </Button>
            <Button
              size="sm"
              onClick={handleConfirmSelect}
              disabled={!selectedItem}
              className="text-xs"
            >
              Insert Selected Asset
            </Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
