import { useState } from 'react';
import {
  Combobox,
  InputOTP,
  MultiSelect,
  TagInput,
  TreeView,
  type TreeNode,
} from '@bs/ui';
import { CATEGORY_OPTIONS, FRUIT_OPTIONS, TERM_TREE, searchUsers } from '../mocks/fixtures';
import type { GallerySection } from '../registry';

function ComboboxShowcase() {
  const [fruit, setFruit] = useState<string | null>('banana');
  const [user, setUser] = useState<string | null>(null);
  return (
    <div className="grid max-w-md gap-4">
      <Combobox
        options={FRUIT_OPTIONS}
        value={fruit}
        onChange={setFruit}
        aria-label="Fruit"
      />
      <Combobox
        onSearch={searchUsers}
        value={user}
        onChange={setUser}
        placeholder="Search users…"
        aria-label="Assignee (async)"
      />
      <Combobox
        options={FRUIT_OPTIONS}
        value={null}
        onChange={() => {}}
        allowCreate
        onCreate={(label) => ({ value: `new-${label}`, label })}
        aria-label="Create-capable"
      />
    </div>
  );
}

function MultiSelectShowcase() {
  const [selected, setSelected] = useState<string[]>(['1', '3']);
  return (
    <div className="max-w-md">
      <MultiSelect
        options={CATEGORY_OPTIONS}
        value={selected}
        onChange={setSelected}
        aria-label="Categories"
      />
    </div>
  );
}

function TagInputShowcase() {
  const [tags, setTags] = useState<string[]>(['seo', 'launch']);
  const [rejected, setRejected] = useState<string[]>(['seo', 'bad-tag']);
  return (
    <div className="grid max-w-md gap-4">
      <TagInput value={tags} onChange={setTags} aria-label="Tags (valid)" />
      <TagInput
        value={rejected}
        onChange={setRejected}
        aria-label="Tags (no duplicates)"
      />
    </div>
  );
}

function OtpShowcase() {
  const [code, setCode] = useState('12');
  return (
    <div className="space-y-2">
      <InputOTP value={code} onChange={setCode} aria-label="Verification code" />
      <p className="text-xs text-muted-foreground">Auto-advance, paste, arrow keys.</p>
    </div>
  );
}

function TreeShowcase() {
  const [selected, setSelected] = useState<string | null>('football');
  const nodes: TreeNode[] = TERM_TREE;
  return (
    <div className="max-w-sm rounded-md border border-border p-2">
      <TreeView nodes={nodes} selectedId={selected} onSelect={setSelected} aria-label="Categories tree" />
    </div>
  );
}

export const controlsSection: GallerySection = {
  id: 'controls',
  name: 'Searchable controls',
  entries: [
    {
      id: 'combobox',
      name: 'Combobox',
      description: 'Static list, async search (debounced, stale responses discarded) and inline create.',
      render: () => <ComboboxShowcase />,
      props: [
        { name: 'options / onSearch', type: 'ComboboxOption[] | (query) => Promise<ComboboxOption[]>', description: 'Static list or async source; async debounces at 250 ms.' },
        { name: 'allowCreate + onCreate', type: 'boolean + (label) => ComboboxOption', description: 'Offers "Create …" when no exact match exists.' },
        { name: 'value / onChange', type: 'string | null', description: 'null clears the selection.' },
      ],
    },
    {
      id: 'multi-select',
      name: 'Multi Select',
      description: 'Chips with remove buttons, search, select all / clear all, +N overflow.',
      render: () => <MultiSelectShowcase />,
      props: [
        { name: 'value / onChange', type: 'string[]', description: 'Selected ids in selection order.' },
        { name: 'maxChips', type: 'number', defaultValue: '3', description: 'Visible chips before the "+N more" badge.' },
      ],
    },
    {
      id: 'tag-input',
      name: 'Tag Input',
      description: 'Enter to add, duplicates rejected inline, Backspace removes the last tag.',
      render: () => <TagInputShowcase />,
      props: [
        { name: 'value / onChange', type: 'string[]', description: 'Tag list.' },
        { name: 'validate', type: '(tag) => string | null', description: 'Return an error message to reject a tag.' },
      ],
    },
    {
      id: 'input-otp',
      name: 'Input OTP',
      description: 'One-time code cells with auto-advance, paste and full arrow navigation (2FA challenge).',
      render: () => <OtpShowcase />,
      props: [
        { name: 'length', type: 'number', defaultValue: '6', description: 'Number of digits.' },
        { name: 'numericOnly', type: 'boolean', defaultValue: 'true', description: 'Digits only for TOTP codes.' },
      ],
    },
    {
      id: 'tree-view',
      name: 'Tree View',
      description: 'WAI-ARIA tree: roving focus, Right/Left expand-collapse, Enter selects; used by the term picker.',
      render: () => <TreeShowcase />,
      props: [
        { name: 'nodes', type: 'TreeNode[]', description: 'Nested { id, label, children? }.' },
        { name: 'selectedId', type: 'string | null', description: 'Controlled selection; ancestors auto-expand.' },
      ],
    },
  ],
};
