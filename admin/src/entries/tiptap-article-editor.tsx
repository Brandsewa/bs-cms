import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import Image from '@tiptap/extension-image';
import {
  Bold,
  Italic,
  Strikethrough,
  Code,
  Heading2,
  Heading3,
  Heading4,
  List,
  ListOrdered,
  Quote,
  Minus,
  Undo,
  Redo,
  AlertCircle,
  HelpCircle,
} from 'lucide-react';
import { cn } from '@bs/ui';

export interface TiptapArticleEditorProps {
  value?: unknown;
  onChange?: (json: unknown, markdown?: string) => void;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
}

export function TiptapArticleEditor({
  value,
  onChange,
  placeholder = 'Write your article story...',
  disabled = false,
  className,
}: TiptapArticleEditorProps) {
  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        heading: {
          levels: [2, 3, 4], // H1 is reserved for the post title
        },
      }),
      Link.configure({
        openOnClick: false,
        HTMLAttributes: {
          class: 'text-primary underline underline-offset-2',
        },
      }),
      Placeholder.configure({
        placeholder,
      }),
      Image.configure({
        inline: true,
        allowBase64: true,
        HTMLAttributes: {
          class: 'rounded-md my-4 max-w-full',
        },
      }),
    ],
    content: (value as any) || {
      type: 'doc',
      content: [
        {
          type: 'paragraph',
        },
      ],
    },
    editorProps: {
      attributes: {
        'aria-label': 'Article rich text body',
        role: 'textbox',
      },
    },
    editable: !disabled,
    onUpdate: ({ editor }) => {
      const json = editor.getJSON();
      onChange?.(json);
    },
  });

  if (!editor) {
    return (
      <div className="flex h-64 items-center justify-center rounded-md border border-border bg-card p-4 text-sm text-muted-foreground animate-pulse">
        Loading editor...
      </div>
    );
  }

  const insertDirective = (type: 'note' | 'tip' | 'warning') => {
    editor
      .chain()
      .focus()
      .insertContent({
        type: 'blockquote',
        content: [
          {
            type: 'paragraph',
            content: [
              {
                type: 'text',
                text: `[${type.toUpperCase()}] `,
              },
            ],
          },
        ],
      })
      .run();
  };

  return (
    <div
      data-testid="tiptap-article-editor"
      className={cn(
        'rounded-md border border-border bg-card shadow-xs transition-colors focus-within:border-ring focus-within:ring-1 focus-within:ring-ring',
        className
      )}
    >
      {/* Editor Toolbar */}
      <div
        role="toolbar"
        aria-label="Editor formatting"
        className="flex flex-wrap items-center gap-0.5 border-b border-border bg-surface-100 p-1.5 text-foreground-lighter"
      >
        <button
          type="button"
          onClick={() => editor.chain().focus().toggleBold().run()}
          disabled={!editor.can().chain().focus().toggleBold().run()}
          aria-label="Bold"
          aria-pressed={editor.isActive('bold')}
          className={cn(
            'inline-flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium transition-colors hover:bg-surface-200 hover:text-foreground',
            editor.isActive('bold') && 'bg-accent text-foreground'
          )}
        >
          <Bold className="h-4 w-4" />
        </button>

        <button
          type="button"
          onClick={() => editor.chain().focus().toggleItalic().run()}
          disabled={!editor.can().chain().focus().toggleItalic().run()}
          aria-label="Italic"
          aria-pressed={editor.isActive('italic')}
          className={cn(
            'inline-flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium transition-colors hover:bg-surface-200 hover:text-foreground',
            editor.isActive('italic') && 'bg-accent text-foreground'
          )}
        >
          <Italic className="h-4 w-4" />
        </button>

        <button
          type="button"
          onClick={() => editor.chain().focus().toggleStrike().run()}
          disabled={!editor.can().chain().focus().toggleStrike().run()}
          aria-label="Strikethrough"
          aria-pressed={editor.isActive('strike')}
          className={cn(
            'inline-flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium transition-colors hover:bg-surface-200 hover:text-foreground',
            editor.isActive('strike') && 'bg-accent text-foreground'
          )}
        >
          <Strikethrough className="h-4 w-4" />
        </button>

        <button
          type="button"
          onClick={() => editor.chain().focus().toggleCode().run()}
          disabled={!editor.can().chain().focus().toggleCode().run()}
          aria-label="Inline code"
          aria-pressed={editor.isActive('code')}
          className={cn(
            'inline-flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium transition-colors hover:bg-surface-200 hover:text-foreground',
            editor.isActive('code') && 'bg-accent text-foreground'
          )}
        >
          <Code className="h-4 w-4" />
        </button>

        <div className="mx-1 h-5 w-px bg-border" />

        <button
          type="button"
          onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}
          aria-label="Heading 2"
          aria-pressed={editor.isActive('heading', { level: 2 })}
          className={cn(
            'inline-flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium transition-colors hover:bg-surface-200 hover:text-foreground',
            editor.isActive('heading', { level: 2 }) && 'bg-accent text-foreground'
          )}
        >
          <Heading2 className="h-4 w-4" />
        </button>

        <button
          type="button"
          onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}
          aria-label="Heading 3"
          aria-pressed={editor.isActive('heading', { level: 3 })}
          className={cn(
            'inline-flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium transition-colors hover:bg-surface-200 hover:text-foreground',
            editor.isActive('heading', { level: 3 }) && 'bg-accent text-foreground'
          )}
        >
          <Heading3 className="h-4 w-4" />
        </button>

        <button
          type="button"
          onClick={() => editor.chain().focus().toggleHeading({ level: 4 }).run()}
          aria-label="Heading 4"
          aria-pressed={editor.isActive('heading', { level: 4 })}
          className={cn(
            'inline-flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium transition-colors hover:bg-surface-200 hover:text-foreground',
            editor.isActive('heading', { level: 4 }) && 'bg-accent text-foreground'
          )}
        >
          <Heading4 className="h-4 w-4" />
        </button>

        <div className="mx-1 h-5 w-px bg-border" />

        <button
          type="button"
          onClick={() => editor.chain().focus().toggleBulletList().run()}
          aria-label="Bullet list"
          aria-pressed={editor.isActive('bulletList')}
          className={cn(
            'inline-flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium transition-colors hover:bg-surface-200 hover:text-foreground',
            editor.isActive('bulletList') && 'bg-accent text-foreground'
          )}
        >
          <List className="h-4 w-4" />
        </button>

        <button
          type="button"
          onClick={() => editor.chain().focus().toggleOrderedList().run()}
          aria-label="Numbered list"
          aria-pressed={editor.isActive('orderedList')}
          className={cn(
            'inline-flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium transition-colors hover:bg-surface-200 hover:text-foreground',
            editor.isActive('orderedList') && 'bg-accent text-foreground'
          )}
        >
          <ListOrdered className="h-4 w-4" />
        </button>

        <button
          type="button"
          onClick={() => editor.chain().focus().toggleBlockquote().run()}
          aria-label="Blockquote"
          aria-pressed={editor.isActive('blockquote')}
          className={cn(
            'inline-flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium transition-colors hover:bg-surface-200 hover:text-foreground',
            editor.isActive('blockquote') && 'bg-accent text-foreground'
          )}
        >
          <Quote className="h-4 w-4" />
        </button>

        <button
          type="button"
          onClick={() => editor.chain().focus().setHorizontalRule().run()}
          aria-label="Horizontal rule"
          className="inline-flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium transition-colors hover:bg-surface-200 hover:text-foreground"
        >
          <Minus className="h-4 w-4" />
        </button>

        <div className="mx-1 h-5 w-px bg-border" />

        {/* Callout Directives */}
        <button
          type="button"
          onClick={() => insertDirective('note')}
          aria-label="Insert Note callout"
          title="Insert Note callout"
          className="inline-flex h-8 items-center gap-1 px-2 rounded-md text-xs font-medium text-info transition-colors hover:bg-surface-200"
        >
          <HelpCircle className="h-3.5 w-3.5" />
          <span>Note</span>
        </button>

        <button
          type="button"
          onClick={() => insertDirective('warning')}
          aria-label="Insert Warning callout"
          title="Insert Warning callout"
          className="inline-flex h-8 items-center gap-1 px-2 rounded-md text-xs font-medium text-warning transition-colors hover:bg-surface-200"
        >
          <AlertCircle className="h-3.5 w-3.5" />
          <span>Warning</span>
        </button>

        <div className="ml-auto flex items-center gap-0.5">
          <button
            type="button"
            onClick={() => editor.chain().focus().undo().run()}
            disabled={!editor.can().undo()}
            aria-label="Undo"
            className="inline-flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium transition-colors hover:bg-surface-200 disabled:opacity-40"
          >
            <Undo className="h-4 w-4" />
          </button>
          <button
            type="button"
            onClick={() => editor.chain().focus().redo().run()}
            disabled={!editor.can().redo()}
            aria-label="Redo"
            className="inline-flex h-8 w-8 items-center justify-center rounded-md text-xs font-medium transition-colors hover:bg-surface-200 disabled:opacity-40"
          >
            <Redo className="h-4 w-4" />
          </button>
        </div>
      </div>

      {/* Editor Content Area */}
      <div className="prose prose-sm dark:prose-invert max-w-none p-4 min-h-[320px] focus:outline-none">
        <EditorContent editor={editor} />
      </div>
    </div>
  );
}
