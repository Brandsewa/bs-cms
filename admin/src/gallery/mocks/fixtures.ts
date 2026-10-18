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
