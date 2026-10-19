import { useState } from 'react';
import {
  MediaPicker,
  TermPicker,
  RelationPicker,
  UserPicker,
  SlugInput,
  type PickerMediaItem,
  type PickerUser,
} from '@bs/ui';
import {
  MEDIA_FIXTURES,
  MEDIA_FOLDERS,
  TERM_TREE,
  PICKER_USERS,
  searchPickerUsers,
} from '../mocks/fixtures';
import type { GallerySection } from '../registry';

function MediaPickerShowcase() {
  const [selectedId, setSelectedId] = useState<string | null>('m1');
  const [items, setItems] = useState<PickerMediaItem[]>(MEDIA_FIXTURES);

  const mockUploader = async (file: File) => {
    // Simulate upload delay
    await new Promise((resolve) => setTimeout(resolve, 300));
    const newItem: PickerMediaItem = {
      id: `m-upload-${Date.now()}`,
      filename: file.name,
      mime: file.type || 'application/octet-stream',
      mediaUrl: file.type.startsWith('image/')
        ? 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=300&q=80'
        : undefined,
      folderId: 'f1',
    };
    setItems((prev) => [newItem, ...prev]);
    return newItem;
  };

  return (
    <div className="max-w-xl">
      <MediaPicker
        items={items}
        folders={MEDIA_FOLDERS}
        selectedId={selectedId}
        onSelect={(item) => setSelectedId(item.id)}
        uploader={mockUploader}
        aria-label="Media picker gallery"
      />
    </div>
  );
}

function TermPickerShowcase() {
  const [selected, setSelected] = useState<string[]>(['politics']);
  const [includeSub, setIncludeSub] = useState(false);

  return (
    <div className="max-w-md">
      <TermPicker
        tree={TERM_TREE}
        value={selected}
        onChange={setSelected}
        includeSubcategories={includeSub}
        onIncludeSubcategoriesChange={setIncludeSub}
        aria-label="Term picker gallery"
      />
    </div>
  );
}

function RelationPickerShowcase() {
  const [selected, setSelected] = useState<PickerUser[]>(PICKER_USERS[0] ? [PICKER_USERS[0]] : []);

  return (
    <div className="max-w-md">
      <RelationPicker
        search={searchPickerUsers}
        value={selected}
        onChange={setSelected}
        placeholder="Search authors or related entries…"
        aria-label="Relation picker gallery"
      />
    </div>
  );
}

function UserPickerShowcase() {
  const [selectedUser, setSelectedUser] = useState<string | null>('u2');

  return (
    <div className="max-w-md">
      <UserPicker
        users={PICKER_USERS}
        value={selectedUser}
        onChange={setSelectedUser}
        placeholder="Select user…"
        aria-label="User picker gallery"
      />
    </div>
  );
}

function SlugInputShowcase() {
  const [title, setTitle] = useState('Getting Started with BS CMS');
  const [slug, setSlug] = useState('getting-started-with-bs-cms');

  const mockValidate = async (val: string) => {
    await new Promise((resolve) => setTimeout(resolve, 200));
    if (val === 'taken' || val === 'admin') return 'taken';
    return 'available';
  };

  return (
    <div className="max-w-md space-y-4">
      <div>
        <label htmlFor="gallery-slug-source" className="block text-xs font-medium text-muted-foreground mb-1">
          Source Title
        </label>
        <input
          id="gallery-slug-source"
          type="text"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          className="flex h-8 w-full rounded-md border border-input bg-transparent px-2.5 py-1 text-sm shadow-xs"
        />
      </div>
      <SlugInput
        value={slug}
        onChange={setSlug}
        source={title}
        validate={mockValidate}
        urlPrefix="https://example.com/posts/"
        aria-label="Slug input gallery"
      />
    </div>
  );
}

export const pickersSection: GallerySection = {
  id: 'pickers',
  name: 'Pickers',
  entries: [
    {
      id: 'media-picker',
      name: 'Media Picker',
      description: 'Folder filter, search by filename, thumbnail grid with single select, and upload handler with progress.',
      render: () => <MediaPickerShowcase />,
      props: [
        { name: 'items', type: 'PickerMediaItem[]', description: 'Available media items.' },
        { name: 'folders', type: 'PickerFolder[]', description: 'Folder list for filtering.' },
        { name: 'selectedId', type: 'string | null', description: 'Controlled selection ID.' },
        { name: 'onSelect', type: '(item: PickerMediaItem) => void', description: 'Selection callback.' },
        { name: 'uploader', type: '(file: File) => Promise<UploadHandlerResult>', description: 'Optional upload handler (chunked upload).' },
      ],
    },
    {
      id: 'term-picker',
      name: 'Term Picker',
      description: 'Searchable tree view with multi-select chips and "include subcategories" cascade.',
      render: () => <TermPickerShowcase />,
      props: [
        { name: 'tree', type: 'PickerTermNode[]', description: 'Nested taxonomy terms.' },
        { name: 'value', type: 'string[]', description: 'Selected term ids.' },
        { name: 'onChange', type: '(ids: string[]) => void', description: 'Selection change callback.' },
        { name: 'includeSubcategories', type: 'boolean', description: 'Whether to target subtrees.' },
      ],
    },
    {
      id: 'relation-picker',
      name: 'Relation Picker',
      description: 'Debounced async search with keyboard-navigable result listbox and removable chips.',
      render: () => <RelationPickerShowcase />,
      props: [
        { name: 'search', type: '(query: string) => Promise<PickerUser[]>', description: 'Debounced async search callback.' },
        { name: 'value', type: 'PickerUser[]', description: 'Selected items in order.' },
        { name: 'onChange', type: '(items: PickerUser[]) => void', description: 'Change callback.' },
      ],
    },
    {
      id: 'user-picker',
      name: 'User Picker',
      description: 'Searchable combobox with avatar rendering and single-select clear.',
      render: () => <UserPickerShowcase />,
      props: [
        { name: 'users', type: 'PickerUser[]', description: 'List of users.' },
        { name: 'value', type: 'string | null', description: 'Selected user id.' },
        { name: 'onChange', type: '(id: string | null) => void', description: 'Change callback.' },
      ],
    },
    {
      id: 'slug-input',
      name: 'Slug Input',
      description: 'Auto-derives from source title until unlocked, with debounced uniqueness validation and URL preview.',
      render: () => <SlugInputShowcase />,
      props: [
        { name: 'value', type: 'string', description: 'Current slug string.' },
        { name: 'onChange', type: '(slug: string) => void', description: 'Slug update callback.' },
        { name: 'source', type: 'string', description: 'Source title string for auto-derivation.' },
        { name: 'validate', type: '(slug: string) => Promise<SlugAvailability>', description: 'Debounced uniqueness check callback.' },
        { name: 'urlPrefix', type: 'string', description: 'URL preview prefix.' },
      ],
    },
  ],
};
