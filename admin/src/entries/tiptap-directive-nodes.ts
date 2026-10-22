import { Node, mergeAttributes } from '@tiptap/core';

export const CalloutNode = Node.create({
  name: 'callout',
  group: 'block',
  content: 'block+',
  defining: true,

  addAttributes() {
    return {
      variant: {
        default: 'note',
        parseHTML: (element) => element.getAttribute('data-variant') || element.getAttribute('data-type') || 'note',
        renderHTML: (attributes) => ({
          'data-variant': attributes.variant || 'note',
        }),
      },
      type: {
        default: undefined,
        parseHTML: (element) => element.getAttribute('data-type') || undefined,
      },
      title: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-title'),
        renderHTML: (attributes) => (attributes.title ? { 'data-title': attributes.title } : {}),
      },
    };
  },

  parseHTML() {
    return [
      {
        tag: 'div.callout',
        getAttrs: (element) => {
          if (typeof element === 'string') return false;
          return {
            variant: element.getAttribute('data-variant') || 'note',
            title: element.getAttribute('data-title'),
          };
        },
      },
      {
        tag: 'div[data-type="callout"]',
      },
    ];
  },

  renderHTML({ node, HTMLAttributes }) {
    const variant = node.attrs.variant || node.attrs.type || 'note';
    return [
      'div',
      mergeAttributes(HTMLAttributes, {
        class: `callout callout-${variant} my-4 rounded-md border-l-4 border-info bg-surface-100 p-4 text-foreground`,
        'data-type': 'callout',
        'data-variant': variant,
      }),
      0,
    ];
  },
});

export const FaqNode = Node.create({
  name: 'faq',
  group: 'block',
  atom: true,

  addAttributes() {
    return {
      items: {
        default: [],
        parseHTML: (element) => {
          try {
            const raw = element.getAttribute('data-items');
            return raw ? JSON.parse(raw) : [];
          } catch {
            return [];
          }
        },
        renderHTML: (attributes) => ({
          'data-items': JSON.stringify(attributes.items || []),
        }),
      },
    };
  },

  parseHTML() {
    return [{ tag: 'div.faq-group' }, { tag: 'div[data-type="faq"]' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    const items = (node.attrs.items as Array<{ question?: string; answer?: string }>) || [];
    return [
      'div',
      mergeAttributes(HTMLAttributes, {
        class: 'faq-group my-4 rounded-md border border-border bg-surface-100 p-3 text-sm',
        'data-type': 'faq',
      }),
      [
        'div',
        { class: 'font-semibold text-xs text-foreground uppercase tracking-wider mb-2' },
        `FAQ (${items.length} items)`,
      ],
      ...items.map((it) => [
        'div',
        { class: 'border-t border-border pt-1.5 mt-1.5' },
        ['div', { class: 'font-medium text-foreground' }, it.question || 'Question'],
        ['div', { class: 'text-xs text-muted-foreground mt-0.5' }, it.answer || ''],
      ]),
    ];
  },
});

export const KeyFactsNode = Node.create({
  name: 'keyFacts',
  group: 'block',
  atom: true,

  addAttributes() {
    return {
      facts: {
        default: [],
        parseHTML: (element) => {
          try {
            const raw = element.getAttribute('data-facts');
            return raw ? JSON.parse(raw) : [];
          } catch {
            return [];
          }
        },
        renderHTML: (attributes) => ({
          'data-facts': JSON.stringify(attributes.facts || []),
        }),
      },
    };
  },

  parseHTML() {
    return [{ tag: 'dl.key-facts' }, { tag: 'div[data-type="keyFacts"]' }, { tag: 'div[data-type="facts"]' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    const facts = (node.attrs.facts as Array<{ statement?: string; value?: string; source_url?: string }>) || [];
    return [
      'div',
      mergeAttributes(HTMLAttributes, {
        class: 'key-facts my-4 rounded-md border border-border bg-surface-100 p-3 text-sm',
        'data-type': 'keyFacts',
      }),
      [
        'div',
        { class: 'font-semibold text-xs text-foreground uppercase tracking-wider mb-2' },
        `Key Facts (${facts.length})`,
      ],
      ...facts.map((f) => [
        'div',
        { class: 'flex justify-between text-xs py-1 border-t border-border' },
        ['span', { class: 'text-muted-foreground' }, f.statement || ''],
        ['span', { class: 'font-semibold text-foreground' }, f.value || ''],
      ]),
    ];
  },
});

export const ButtonNode = Node.create({
  name: 'button',
  group: 'block',
  atom: true,

  addAttributes() {
    return {
      text: {
        default: 'Click Here',
        parseHTML: (el) => el.getAttribute('data-text') || el.textContent || 'Click Here',
        renderHTML: (attrs) => ({ 'data-text': attrs.text || attrs.label || 'Click Here' }),
      },
      label: {
        default: undefined,
      },
      url: {
        default: '#',
        parseHTML: (el) => el.getAttribute('data-url') || el.getAttribute('href') || '#',
        renderHTML: (attrs) => ({ 'data-url': attrs.url || '#' }),
      },
    };
  },

  parseHTML() {
    return [{ tag: 'a.button' }, { tag: 'div[data-type="button"]' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    const text = node.attrs.text || node.attrs.label || 'Click Here';
    return [
      'div',
      mergeAttributes(HTMLAttributes, {
        class: 'button-block my-4 inline-block',
        'data-type': 'button',
      }),
      [
        'span',
        { class: 'inline-flex items-center px-4 py-2 rounded-md bg-primary text-primary-foreground text-sm font-medium shadow-xs' },
        text,
      ],
    ];
  },
});

export const EmbedNode = Node.create({
  name: 'embed',
  group: 'block',
  atom: true,

  addAttributes() {
    return {
      url: {
        default: '',
        parseHTML: (el) => el.getAttribute('data-url') || '',
        renderHTML: (attrs) => ({ 'data-url': attrs.url }),
      },
      caption: {
        default: '',
        parseHTML: (el) => el.getAttribute('data-caption') || '',
        renderHTML: (attrs) => ({ 'data-caption': attrs.caption }),
      },
    };
  },

  parseHTML() {
    return [{ tag: 'div[data-type="embed"]' }, { tag: 'figure.embed' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    const url = node.attrs.url || '';
    const caption = node.attrs.caption || '';
    return [
      'div',
      mergeAttributes(HTMLAttributes, {
        class: 'embed-block my-4 rounded-md border border-border bg-surface-100 p-4 text-center',
        'data-type': 'embed',
        'data-url': url,
      }),
      [
        'div',
        { class: 'text-xs text-muted-foreground flex items-center justify-center gap-2 mb-2' },
        ['span', { class: 'font-semibold uppercase tracking-wider text-foreground' }, 'Embed (Click-to-load)'],
        url ? `— ${url}` : '',
      ],
      [
        'div',
        { class: 'rounded bg-surface-200 border border-border/50 py-6 px-4 text-xs font-mono text-muted-foreground truncate' },
        url || 'No embed URL provided',
      ],
      caption ? ['div', { class: 'text-xs text-muted-foreground mt-2 italic' }, caption] : '',
    ];
  },
});

export const TableBlockNode = Node.create({
  name: 'tableBlock',
  group: 'block',
  atom: true,

  addAttributes() {
    return {
      rows: {
        default: [
          ['Header 1', 'Header 2', 'Header 3'],
          ['Data 1', 'Data 2', 'Data 3'],
          ['Data 4', 'Data 5', 'Data 6'],
        ],
        parseHTML: (el) => {
          try {
            const raw = el.getAttribute('data-rows');
            return raw ? JSON.parse(raw) : [];
          } catch {
            return [];
          }
        },
        renderHTML: (attrs) => ({
          'data-rows': JSON.stringify(attrs.rows || []),
        }),
      },
    };
  },

  parseHTML() {
    return [{ tag: 'div[data-type="tableBlock"]' }, { tag: 'table.article-table' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    const rows = (node.attrs.rows as string[][]) || [];
    return [
      'div',
      mergeAttributes(HTMLAttributes, {
        class: 'table-block my-4 overflow-x-auto rounded-md border border-border',
        'data-type': 'tableBlock',
      }),
      [
        'table',
        { class: 'w-full text-left text-xs border-collapse' },
        [
          'thead',
          { class: 'bg-surface-200 border-b border-border text-foreground font-semibold' },
          [
            'tr',
            {},
            ...(rows[0] || []).map((col) => ['th', { class: 'p-2 border-r border-border last:border-r-0' }, col]),
          ],
        ],
        [
          'tbody',
          {},
          ...rows.slice(1).map((row) => [
            'tr',
            { class: 'border-b border-border last:border-b-0 hover:bg-surface-50' },
            ...row.map((cell) => ['td', { class: 'p-2 border-r border-border last:border-r-0' }, cell]),
          ]),
        ],
      ],
    ];
  },
});
