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
