import { useState, useEffect, useCallback, useRef } from 'react';
import {
  PageContainer,
  PageHeader,
  Button,
  Input,
  Spinner,
  Badge,
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  Label,
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
  Image as ImageIcon,
  Upload,
  Folder as FolderIcon,
  Search,
  Check,
  FileText,
  RefreshCw,
  LayoutGrid,
  List as ListIcon,
  Trash2,
  FolderPlus,
} from 'lucide-react';
import { apiClient } from '../lib/api';
import { uploadChunked } from '@bs/client';
import { MediaDetailSheet } from './media-detail-sheet';
import { unwrapMediaList, type MediaItem, type MediaFolder } from './media-picker-modal';

export function MediaLibraryRoute() {
  const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
  const [items, setItems] = useState<MediaItem[]>([]);
  const [folders, setFolders] = useState<MediaFolder[]>([]);
  const [selectedFolderId, setSelectedFolderId] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [mimeFilter, setMimeFilter] = useState<string>('all');
  const [loading, setLoading] = useState(false);

  // Selection
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
  const [detailItem, setDetailItem] = useState<MediaItem | null>(null);

  // New folder dialog
  const [folderDialogOpen, setFolderDialogOpen] = useState(false);
  const [newFolderName, setNewFolderName] = useState('');
  const [creatingFolder, setCreatingFolder] = useState(false);

  // Upload
  const [uploadProgress, setUploadProgress] = useState<number | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const loadFolders = useCallback(async () => {
    try {
      const res = await apiClient.request<MediaFolder[]>('GET', '/media/folders');
      if (res.ok && Array.isArray(res.data)) {
        setFolders(res.data);
      }
    } catch {
      // Ignored
    }
  }, []);

  const loadMedia = useCallback(async () => {
    setLoading(true);
    try {
      const query = new URLSearchParams();
      if (selectedFolderId) query.set('folder_id', selectedFolderId);
      if (search.trim()) query.set('search', search.trim());
      const qs = query.toString() ? `?${query.toString()}` : '';

      const res = await apiClient.request<MediaItem[] | { data: MediaItem[] }>('GET', `/media${qs}`);
      if (res.ok && res.data) {
        const list = unwrapMediaList(res.data);
        if (mimeFilter === 'image') {
          setItems(list.filter((m: MediaItem) => m.mime_type?.startsWith('image/')));
        } else if (mimeFilter === 'document') {
          setItems(list.filter((m: MediaItem) => !m.mime_type?.startsWith('image/')));
        } else {
          setItems(list);
        }
      }
    } catch {
      setItems([]);
    } finally {
      setLoading(false);
    }
  }, [selectedFolderId, search, mimeFilter]);

  useEffect(() => {
    loadFolders();
  }, [loadFolders]);

  useEffect(() => {
    loadMedia();
  }, [loadMedia]);

  const handleFileUpload = async (files: FileList | null) => {
    if (!files || files.length === 0) return;
    const file = files[0];
    if (!file) return;

    setUploadProgress(0);
    try {
      const result = await uploadChunked(apiClient, file, {
        folderId: selectedFolderId || undefined,
        onProgress: (p) => {
          setUploadProgress(Math.round(p.fraction * 100));
        },
      });

      if (result.ok && result.media) {
        successToast('File uploaded successfully.');
        loadMedia();
      } else {
        errorToast(result.error || 'Upload failed.');
      }
    } catch {
      errorToast('Upload failed.');
    } finally {
      setUploadProgress(null);
    }
  };

  const handleCreateFolder = async () => {
    if (!newFolderName.trim()) return;
    setCreatingFolder(true);
    try {
      const res = await apiClient.request<MediaFolder>('POST', '/media/folders', {
        body: {
          name: newFolderName.trim(),
          parent_id: selectedFolderId,
        },
      });
      if (res.ok && res.data) {
        successToast('Folder created.');
        setFolders((prev) => [...prev, res.data!]);
        setNewFolderName('');
        setFolderDialogOpen(false);
      } else {
        errorToast('Failed to create folder.');
      }
    } catch {
      errorToast('Failed to create folder.');
    } finally {
      setCreatingFolder(false);
    }
  };

  const handleToggleSelect = (id: string) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  const handleBulkDelete = async () => {
    if (selectedIds.size === 0) return;
    if (!window.confirm(`Delete ${selectedIds.size} selected items?`)) return;

    try {
      await apiClient.request('POST', '/media/bulk', {
        body: {
          action: 'delete',
          ids: Array.from(selectedIds),
        },
      });
      successToast('Deleted selected assets.');
      setSelectedIds(new Set());
      loadMedia();
    } catch {
      errorToast('Failed to delete selected media.');
    }
  };

  return (
    <PageContainer width="full" className="space-y-4">
      <div className="flex items-center justify-between border-b border-border pb-4">
        <PageHeader
          title="Media Library"
          description="Upload, inspect, crop, tag, and organize media assets across all site content."
        />
        <div className="flex items-center gap-2">
          <input
            type="file"
            ref={fileInputRef}
            onChange={(e) => handleFileUpload(e.target.files)}
            className="hidden"
          />
          <Button
            size="sm"
            variant="outline"
            onClick={() => setFolderDialogOpen(true)}
            className="text-xs h-8 gap-1.5"
          >
            <FolderPlus className="h-4 w-4" />
            New Folder
          </Button>
          <Button
            size="sm"
            onClick={() => fileInputRef.current?.click()}
            className="text-xs h-8 gap-1.5"
          >
            <Upload className="h-4 w-4" />
            Upload File
          </Button>
        </div>
      </div>

      {uploadProgress !== null && (
        <div className="p-3 bg-primary/10 border border-primary/20 rounded-md space-y-1">
          <div className="flex justify-between text-xs font-medium text-primary">
            <span>Uploading chunked media asset...</span>
            <span>{uploadProgress}%</span>
          </div>
          <div className="h-1.5 w-full bg-primary/20 rounded-full overflow-hidden">
            <div
              className="h-full bg-primary transition-all duration-150"
              style={{ width: `${uploadProgress}%` }}
            />
          </div>
        </div>
      )}

      {/* Main split: Folders Tree Left, Assets Grid/List Right */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 min-h-[500px]">
        {/* Left Column: Folders */}
        <div className="lg:col-span-3 rounded-md border border-border bg-card p-3 space-y-2">
          <div className="flex items-center justify-between pb-2 border-b border-border">
            <span className="text-xs font-semibold uppercase tracking-wider text-foreground-lighter">
              Folders
            </span>
            <Button
              size="sm"
              variant="ghost"
              onClick={loadFolders}
              className="h-6 w-6 p-0 text-muted-foreground"
            >
              <RefreshCw className="h-3 w-3" />
            </Button>
          </div>

          <div className="space-y-1">
            <button
              type="button"
              onClick={() => setSelectedFolderId(null)}
              className={cn(
                'flex items-center gap-2 w-full px-2.5 py-1.5 text-xs rounded-md text-left transition-colors',
                selectedFolderId === null
                  ? 'bg-primary text-primary-foreground font-medium'
                  : 'text-foreground hover:bg-surface-100'
              )}
            >
              <FolderIcon className="h-3.5 w-3.5" />
              <span>All Media Assets</span>
            </button>

            {folders.map((f) => (
              <button
                key={f.id}
                type="button"
                onClick={() => setSelectedFolderId(f.id)}
                className={cn(
                  'flex items-center gap-2 w-full px-2.5 py-1.5 text-xs rounded-md text-left transition-colors truncate',
                  selectedFolderId === f.id
                    ? 'bg-primary text-primary-foreground font-medium'
                    : 'text-foreground hover:bg-surface-100'
                )}
              >
                <FolderIcon className="h-3.5 w-3.5 shrink-0" />
                <span className="truncate">{f.name}</span>
              </button>
            ))}

            {folders.length === 0 && (
              <p className="text-[11px] text-muted-foreground italic px-2 py-4 text-center">
                No folders created yet.
              </p>
            )}
          </div>
        </div>

        {/* Right Column: Assets Grid / List */}
        <div className="lg:col-span-9 flex flex-col space-y-3">
          {/* Controls bar */}
          <div className="flex flex-wrap items-center justify-between gap-2 p-2 rounded-md border border-border bg-card">
            <div className="flex items-center gap-2 flex-1 max-w-sm">
              <div className="relative flex-1">
                <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                <Input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Search assets by title, alt or filename..."
                  className="pl-8 text-xs h-8"
                />
              </div>

              <Select value={mimeFilter} onValueChange={(val) => setMimeFilter(val)}>
                <SelectTrigger className="text-xs h-8 w-[140px]">
                  <SelectValue placeholder="All Types" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Types</SelectItem>
                  <SelectItem value="image">Images Only</SelectItem>
                  <SelectItem value="document">Documents & Files</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="flex items-center gap-2">
              {selectedIds.size > 0 && (
                <div className="flex items-center gap-2 mr-2">
                  <Badge variant="secondary" className="text-xs">
                    {selectedIds.size} selected
                  </Badge>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={handleBulkDelete}
                    className="h-8 text-xs text-destructive hover:text-destructive"
                  >
                    <Trash2 className="h-3.5 w-3.5 mr-1" />
                    Delete
                  </Button>
                </div>
              )}

              {/* View toggle */}
              <div className="flex rounded-md border border-border p-0.5 bg-surface-100">
                <button
                  type="button"
                  onClick={() => setViewMode('grid')}
                  className={cn(
                    'p-1.5 rounded transition-colors',
                    viewMode === 'grid' ? 'bg-background shadow-xs text-foreground' : 'text-muted-foreground'
                  )}
                  aria-label="Grid view"
                >
                  <LayoutGrid className="h-3.5 w-3.5" />
                </button>
                <button
                  type="button"
                  onClick={() => setViewMode('list')}
                  className={cn(
                    'p-1.5 rounded transition-colors',
                    viewMode === 'list' ? 'bg-background shadow-xs text-foreground' : 'text-muted-foreground'
                  )}
                  aria-label="List view"
                >
                  <ListIcon className="h-3.5 w-3.5" />
                </button>
              </div>

              <Button
                size="sm"
                variant="outline"
                onClick={loadMedia}
                disabled={loading}
                className="h-8 text-xs"
              >
                <RefreshCw className={cn('h-3.5 w-3.5 mr-1', loading && 'animate-spin')} />
                Refresh
              </Button>
            </div>
          </div>

          {/* Media Items */}
          {loading ? (
            <div className="flex-1 flex items-center justify-center min-h-[300px]">
              <Spinner className="h-8 w-8 text-primary" />
            </div>
          ) : items.length === 0 ? (
            <div className="flex-1 flex flex-col items-center justify-center border border-dashed border-border rounded-md text-center p-12 text-muted-foreground bg-card">
              <ImageIcon className="h-10 w-10 mb-2 stroke-1" />
              <p className="text-sm font-medium">No media assets found</p>
              <p className="text-xs mt-1">Upload a file or choose another folder.</p>
              <Button
                size="sm"
                variant="outline"
                onClick={() => fileInputRef.current?.click()}
                className="text-xs mt-4"
              >
                Upload First Asset
              </Button>
            </div>
          ) : viewMode === 'grid' ? (
            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
              {items.map((item) => {
                const isSelected = selectedIds.has(item.id);
                const isImage = item.mime_type?.startsWith('image/');
                const previewUrl = item.url || `/api/v1/public/media/${item.id}/file`;

                return (
                  <div
                    key={item.id}
                    onClick={() => setDetailItem(item)}
                    className={cn(
                      'group relative aspect-square rounded-md border text-left overflow-hidden cursor-pointer transition-all bg-card flex flex-col items-center justify-center p-1',
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
                        <FileText className="h-8 w-8 mb-1" />
                        <span className="text-[10px] truncate max-w-full">
                          {item.original_name || item.id}
                        </span>
                      </div>
                    )}

                    {/* Select checkbox */}
                    <div
                      onClick={(e) => {
                        e.stopPropagation();
                        handleToggleSelect(item.id);
                      }}
                      className={cn(
                        'absolute top-2 left-2 h-5 w-5 rounded border border-border bg-background/90 flex items-center justify-center shadow transition-opacity',
                        isSelected ? 'opacity-100 bg-primary text-primary-foreground border-primary' : 'opacity-0 group-hover:opacity-100'
                      )}
                    >
                      {isSelected && <Check className="h-3 w-3 stroke-[3]" />}
                    </div>

                    <div className="absolute inset-x-0 bottom-0 bg-background/85 backdrop-blur-xs p-1.5 text-[10px] truncate opacity-0 group-hover:opacity-100 transition-opacity flex justify-between items-center">
                      <span className="truncate">{item.name || item.original_name}</span>
                      <span className="font-mono text-muted-foreground">
                        {item.size ? `${Math.round(item.size / 1024)} KB` : ''}
                      </span>
                    </div>
                  </div>
                );
              })}
            </div>
          ) : (
            <div className="rounded-md border border-border bg-card overflow-hidden">
              <table className="w-full text-xs text-left">
                <thead className="bg-surface-100 border-b border-border text-foreground font-semibold">
                  <tr>
                    <th className="p-2.5 w-8">
                      <input
                        type="checkbox"
                        checked={items.length > 0 && selectedIds.size === items.length}
                        onChange={(e) => {
                          if (e.target.checked) {
                            setSelectedIds(new Set(items.map((i) => i.id)));
                          } else {
                            setSelectedIds(new Set());
                          }
                        }}
                        className="rounded border-border"
                      />
                    </th>
                    <th className="p-2.5">Asset</th>
                    <th className="p-2.5">Type</th>
                    <th className="p-2.5">Size</th>
                    <th className="p-2.5">Alt Text</th>
                    <th className="p-2.5 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {items.map((item) => {
                    const isSelected = selectedIds.has(item.id);
                    return (
                      <tr
                        key={item.id}
                        onClick={() => setDetailItem(item)}
                        className={cn('hover:bg-surface-50 cursor-pointer', isSelected && 'bg-primary/5')}
                      >
                        <td className="p-2.5" onClick={(e) => e.stopPropagation()}>
                          <input
                            type="checkbox"
                            checked={isSelected}
                            onChange={() => handleToggleSelect(item.id)}
                            className="rounded border-border"
                          />
                        </td>
                        <td className="p-2.5 font-medium text-foreground truncate max-w-xs">
                          {item.name || item.original_name}
                        </td>
                        <td className="p-2.5 text-muted-foreground font-mono">{item.mime_type}</td>
                        <td className="p-2.5 text-muted-foreground font-mono">
                          {item.size ? `${Math.round(item.size / 1024)} KB` : '—'}
                        </td>
                        <td className="p-2.5 text-muted-foreground truncate max-w-xs">
                          {item.alt || <span className="italic text-amber-500">Missing alt text</span>}
                        </td>
                        <td className="p-2.5 text-right" onClick={(e) => e.stopPropagation()}>
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setDetailItem(item)}
                            className="h-7 text-xs"
                          >
                            Details
                          </Button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {/* Media Detail Sheet */}
      <MediaDetailSheet
        item={detailItem}
        open={Boolean(detailItem)}
        onClose={() => setDetailItem(null)}
        onUpdate={(updated) => {
          setItems((prev) => prev.map((i) => (i.id === updated.id ? updated : i)));
        }}
        onDelete={(id) => {
          setItems((prev) => prev.filter((i) => i.id !== id));
        }}
        folders={folders}
      />

      {/* New Folder Dialog */}
      <Dialog open={folderDialogOpen} onOpenChange={setFolderDialogOpen}>
        <DialogContent className="max-w-md bg-background">
          <DialogHeader>
            <DialogTitle className="text-sm font-semibold flex items-center gap-2">
              <FolderPlus className="h-4 w-4 text-primary" />
              <span>Create Media Folder</span>
            </DialogTitle>
          </DialogHeader>
          <div className="py-2 space-y-3">
            <div className="space-y-1">
              <Label className="text-xs">Folder Name</Label>
              <Input
                value={newFolderName}
                onChange={(e) => setNewFolderName(e.target.value)}
                placeholder="e.g. Blog Images, Team, Headers"
                className="text-xs h-8"
                autoFocus
              />
            </div>
          </div>
          <DialogFooter className="gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setFolderDialogOpen(false)}
              className="text-xs"
            >
              Cancel
            </Button>
            <Button
              size="sm"
              onClick={handleCreateFolder}
              disabled={creatingFolder || !newFolderName.trim()}
              className="text-xs"
            >
              {creatingFolder ? 'Creating...' : 'Create Folder'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </PageContainer>
  );
}
