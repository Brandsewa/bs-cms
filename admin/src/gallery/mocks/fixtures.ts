/**
 * Gallery fixtures. Import only from admin/src/gallery — this module is
 * excluded from production builds (the gallery route is registered behind
 * import.meta.env.DEV) and must never be referenced from app code.
 */

export const FRUIT_OPTIONS = [
  { value: 'apple', label: 'Apple' },
  { value: 'banana', label: 'Banana' },
  { value: 'cherry', label: 'Cherry' },
  { value: 'durian', label: 'Durian' },
];

export const CATEGORY_OPTIONS = [
  { value: '1', label: 'News' },
  { value: '2', label: 'Guides' },
  { value: '3', label: 'Releases' },
  { value: '4', label: 'Case studies' },
];

export const USER_OPTIONS = [
  { value: 'u1', label: 'Ada Lovelace' },
  { value: 'u2', label: 'Grace Hopper' },
  { value: 'u3', label: 'Alan Turing' },
];

export const TERM_TREE = [
  {
    id: 'news',
    label: 'News',
    children: [
      { id: 'politics', label: 'Politics' },
      {
        id: 'sports',
        label: 'Sports',
        children: [{ id: 'football', label: 'Football' }],
      },
    ],
  },
  { id: 'guides', label: 'Guides' },
  { id: 'releases', label: 'Releases' },
];

/** Static async-search stand-in for the combobox gallery entry. */
export function searchUsers(query: string): Promise<{ value: string; label: string }[]> {
  const needle = query.toLowerCase();
  return Promise.resolve(
    USER_OPTIONS.filter((option) => option.label.toLowerCase().includes(needle))
  );
}

export const MEDIA_FIXTURES = [
  { id: 'm1', filename: 'hero-banner.png', mime: 'image/png', mediaUrl: 'https://images.unsplash.com/photo-1579546929518-9e396f3cc809?w=300&q=80', folderId: 'f1' },
  { id: 'm2', filename: 'team-photo.jpg', mime: 'image/jpeg', mediaUrl: 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?w=300&q=80', folderId: 'f1' },
  { id: 'm3', filename: 'brand-logo.svg', mime: 'image/svg+xml', folderId: 'f1' },
  { id: 'm4', filename: 'annual-report-2026.pdf', mime: 'application/pdf', folderId: 'f2' },
  { id: 'm5', filename: 'product-spec.pdf', mime: 'application/pdf', folderId: 'f2' },
  { id: 'm6', filename: 'podcast-ep1.mp3', mime: 'audio/mpeg', folderId: null },
];

export const MEDIA_FOLDERS = [
  { id: 'f1', label: 'Images' },
  { id: 'f2', label: 'Documents' },
];

export const PICKER_USERS = [
  { id: 'u1', name: 'Ada Lovelace' },
  { id: 'u2', name: 'Grace Hopper' },
  { id: 'u3', name: 'Alan Turing' },
  { id: 'u4', name: 'Margaret Hamilton' },
];

export function searchPickerUsers(query: string): Promise<typeof PICKER_USERS> {
  const needle = query.toLowerCase();
  return Promise.resolve(
    PICKER_USERS.filter((user) => user.name.toLowerCase().includes(needle))
  );
}

