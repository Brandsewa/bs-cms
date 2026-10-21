import { useState } from 'react';
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
  Undo,
  Redo,
  ImageIcon,
  Link as LinkIcon,
  Video,
  Table,
  FileCode,
} from 'lucide-react';
import {
  Button,
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  Input,
  Label,
  Textarea,
  cn,
} from '@bs/ui';
import {
  CalloutNode,
  FaqNode,
  KeyFactsNode,
  ButtonNode,
  EmbedNode,
  TableBlockNode,
} from './tiptap-directive-nodes';
import { MediaPickerModal, type MediaItem } from '../media/media-picker-modal';

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
  // Modal states
  const [mediaPickerOpen, setMediaPickerOpen] = useState(false);
  const [linkDialogOpen, setLinkDialogOpen] = useState(false);
  const [linkUrl, setLinkUrl] = useState('');
  const [linkOpenInNewTab, setLinkOpenInNewTab] = useState(false);

  // Embed Dialog
  const [embedDialogOpen, setEmbedDialogOpen] = useState(false);
  const [embedUrl, setEmbedUrl] = useState('');
  const [embedCaption, setEmbedCaption] = useState('');

  // Source toggle (Rich Text vs Markdown Source)
  const [sourceMode, setSourceMode] = useState<'visual' | 'markdown'>('visual');
  const [markdownSource, setMarkdownSource] = useState('');

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
      CalloutNode,
      FaqNode,
      KeyFactsNode,
      ButtonNode,
      EmbedNode,
      TableBlockNode,
    ],
    content: (value as Record<string, unknown> | string) || {
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
      handlePaste: (_view, event) => {
        const text = event.clipboardData?.getData('text/plain');
        if (text && (text.startsWith('#') || text.includes('**') || text.includes('- '))) {
          // Markdown paste detection: let standard paste insert it, or custom logic
          return false;
        }
        return false;
      },
    },
    editable: !disabled,
    onUpdate: ({ editor }) => {
      const json = editor.getJSON();
      onChange?.(json);
    },
  });

  const insertDirective = (type: 'note' | 'tip' | 'warning') => {
    if (!editor) return;
    editor
      .chain()
      .focus()
      .insertContent({
        type: 'callout',
        attrs: { variant: type },
        content: [{ type: 'paragraph', content: [{ type: 'text', text: `${type.toUpperCase()}: Enter callout details...` }] }],
      })
      .run();
  };

  const insertFaq = () => {
    if (!editor) return;
    editor
      .chain()
      .focus()
      .insertContent({
        type: 'faq',
        attrs: {
          items: [
            { question: 'What is this topic?', answer: 'Comprehensive answer describing this point clearly.' },
            { question: 'How can users get started?', answer: 'Step-by-step guidance on how to begin.' },
          ],
        },
      })
      .run();
  };

  const insertKeyFacts = () => {
    if (!editor) return;
    editor
      .chain()
      .focus()
      .insertContent({
        type: 'keyFacts',
        attrs: {
          facts: [
            { statement: 'Total Impact', value: '99.9%' },
            { statement: 'Response Latency', value: '< 20ms' },
          ],
        },
      })
      .run();
  };

  const insertButton = () => {
    if (!editor) return;
    editor
      .chain()
      .focus()
      .insertContent({
        type: 'button',
        attrs: {
          text: 'Get Started Today',
          url: 'https://example.com',
        },
      })
      .run();
  };

  const insertTable = () => {
    if (!editor) return;
    editor
      .chain()
      .focus()
      .insertContent({
        type: 'tableBlock',
        attrs: {
          rows: [
            ['Feature', 'Standard', 'Enterprise'],
            ['Audit Logging', '30 Days', 'Unlimited'],
            ['Custom Domains', '1', 'Unlimited'],
          ],
        },
      })
      .run();
  };

  const openLinkModal = () => {
    if (!editor) return;
    const previousUrl = editor.getAttributes('link').href;
    setLinkUrl(previousUrl || '');
    setLinkOpenInNewTab(editor.getAttributes('link').target === '_blank');
    setLinkDialogOpen(true);
  };

  const applyLink = () => {
    if (!editor) return;
    if (!linkUrl.trim()) {
      editor.chain().focus().unsetLink().run();
    } else {
      editor
        .chain()
        .focus()
        .setLink({
          href: linkUrl.trim(),
          target: linkOpenInNewTab ? '_blank' : null,
        })
        .run();
    }
    setLinkDialogOpen(false);
  };

  const insertEmbed = () => {
    if (!editor || !embedUrl.trim()) return;
    editor
      .chain()
      .focus()
      .insertContent({
        type: 'embed',
        attrs: {
          url: embedUrl.trim(),
          caption: embedCaption.trim() || undefined,
        },
      })
      .run();
    setEmbedDialogOpen(false);
    setEmbedUrl('');
    setEmbedCaption('');
  };

  const handleMediaSelected = (item: MediaItem) => {
    if (!editor) return;
    const url = item.url || `/api/v1/public/media/${item.id}/file`;
    editor
      .chain()
      .focus()
      .setImage({
        src: url,
        alt: item.alt || item.original_name || '',
        title: item.caption || item.original_name || '',
      })
      .run();
  };

  if (!editor) {
    return (
      <div className="flex h-64 items-center justify-center rounded-md border border-border bg-card p-4 text-sm text-muted-foreground animate-pulse">
        Loading editor...
      </div>
    );
  }

  return (
    <div className={cn('relative rounded-md border border-border bg-card shadow-xs', className)}>
      {/* Editor Control Header */}
      <div className="flex items-center justify-between border-b border-border bg-surface-100 p-1.5 flex-wrap gap-1">
        {/* Formatting actions */}
        <div className="flex flex-wrap items-center gap-0.5">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => editor.chain().focus().toggleBold().run()}
            onMouseDown={(e) => e.preventDefault()}
            className={cn('h-7 w-7 p-0', editor.isActive('bold') && 'bg-surface-300 text-primary font-bold')}
            aria-label="Bold"
          >
            <Bold className="h-3.5 w-3.5" />
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => editor.chain().focus().toggleItalic().run()}
            onMouseDown={(e) => e.preventDefault()}
            className={cn('h-7 w-7 p-0', editor.isActive('italic') && 'bg-surface-300 text-primary italic')}
            aria-label="Italic"
          >
            <Italic className="h-3.5 w-3.5" />
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => editor.chain().focus().toggleStrike().run()}
            onMouseDown={(e) => e.preventDefault()}
            className={cn('h-7 w-7 p-0', editor.isActive('strike') && 'bg-surface-300 text-primary line-through')}
            aria-label="Strikethrough"
          >
            <Strikethrough className="h-3.5 w-3.5" />
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => editor.chain().focus().toggleCode().run()}
            onMouseDown={(e) => e.preventDefault()}
            className={cn('h-7 w-7 p-0', editor.isActive('code') && 'bg-surface-300 text-primary font-mono')}
            aria-label="Inline code"
          >
            <Code className="h-3.5 w-3.5" />
          </Button>

          <div className="mx-1 h-4 w-px bg-border" />

          {/* Headings */}
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}
            onMouseDown={(e) => e.preventDefault()}
            className={cn('h-7 w-7 p-0', editor.isActive('heading', { level: 2 }) && 'bg-surface-300 text-primary font-bold')}
            aria-label="Heading 2"
          >
            <Heading2 className="h-3.5 w-3.5" />
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}
            onMouseDown={(e) => e.preventDefault()}
            className={cn('h-7 w-7 p-0', editor.isActive('heading', { level: 3 }) && 'bg-surface-300 text-primary font-bold')}
            aria-label="Heading 3"
          >
            <Heading3 className="h-3.5 w-3.5" />
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => editor.chain().focus().toggleHeading({ level: 4 }).run()}
            onMouseDown={(e) => e.preventDefault()}
            className={cn('h-7 w-7 p-0', editor.isActive('heading', { level: 4 }) && 'bg-surface-300 text-primary font-bold')}
            aria-label="Heading 4"
          >
            <Heading4 className="h-3.5 w-3.5" />
          </Button>

          <div className="mx-1 h-4 w-px bg-border" />

          {/* Lists & Quotes */}
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => editor.chain().focus().toggleBulletList().run()}
            onMouseDown={(e) => e.preventDefault()}
            className={cn('h-7 w-7 p-0', editor.isActive('bulletList') && 'bg-surface-300 text-primary')}
            aria-label="Bullet list"
          >
            <List className="h-3.5 w-3.5" />
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => editor.chain().focus().toggleOrderedList().run()}
            onMouseDown={(e) => e.preventDefault()}
            className={cn('h-7 w-7 p-0', editor.isActive('orderedList') && 'bg-surface-300 text-primary')}
            aria-label="Ordered list"
          >
            <ListOrdered className="h-3.5 w-3.5" />
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => editor.chain().focus().toggleBlockquote().run()}
            onMouseDown={(e) => e.preventDefault()}
            className={cn('h-7 w-7 p-0', editor.isActive('blockquote') && 'bg-surface-300 text-primary')}
            aria-label="Quote"
          >
            <Quote className="h-3.5 w-3.5" />
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => editor.chain().focus().toggleCodeBlock().run()}
            onMouseDown={(e) => e.preventDefault()}
            className={cn('h-7 w-7 p-0', editor.isActive('codeBlock') && 'bg-surface-300 text-primary')}
            aria-label="Code block"
          >
            <FileCode className="h-3.5 w-3.5" />
          </Button>

          <div className="mx-1 h-4 w-px bg-border" />

          {/* Link & Media Picker */}
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={openLinkModal}
            onMouseDown={(e) => e.preventDefault()}
            className={cn('h-7 px-1.5 text-xs flex items-center gap-1', editor.isActive('link') && 'bg-surface-300 text-primary font-medium')}
            aria-label="Insert Link"
          >
            <LinkIcon className="h-3.5 w-3.5" />
            <span className="hidden sm:inline">Link</span>
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => setMediaPickerOpen(true)}
            onMouseDown={(e) => e.preventDefault()}
            className="h-7 px-1.5 text-xs flex items-center gap-1"
            aria-label="Insert Image from Media Library"
          >
            <ImageIcon className="h-3.5 w-3.5 text-primary" />
            <span className="hidden sm:inline">Media</span>
          </Button>

          {/* Custom Directive Blocks */}
          <div className="mx-1 h-4 w-px bg-border" />

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => insertDirective('note')}
            onMouseDown={(e) => e.preventDefault()}
            className="h-7 px-1.5 text-[11px] text-muted-foreground hover:text-foreground"
          >
            Callout
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={insertFaq}
            onMouseDown={(e) => e.preventDefault()}
            className="h-7 px-1.5 text-[11px] text-muted-foreground hover:text-foreground"
          >
            FAQ
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={insertKeyFacts}
            onMouseDown={(e) => e.preventDefault()}
            className="h-7 px-1.5 text-[11px] text-muted-foreground hover:text-foreground"
          >
            Facts
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={insertTable}
            onMouseDown={(e) => e.preventDefault()}
            className="h-7 px-1.5 text-[11px] text-muted-foreground hover:text-foreground flex items-center gap-1"
          >
            <Table className="h-3 w-3" />
            <span className="hidden sm:inline">Table</span>
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => setEmbedDialogOpen(true)}
            onMouseDown={(e) => e.preventDefault()}
            className="h-7 px-1.5 text-[11px] text-muted-foreground hover:text-foreground flex items-center gap-1"
          >
            <Video className="h-3 w-3" />
            <span className="hidden sm:inline">Embed</span>
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={insertButton}
            onMouseDown={(e) => e.preventDefault()}
            className="h-7 px-1.5 text-[11px] text-muted-foreground hover:text-foreground"
          >
            Button
          </Button>
        </div>

        {/* Source Toggle & Undo/Redo */}
        <div className="flex items-center gap-1">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => {
              if (sourceMode === 'visual') {
                setMarkdownSource(JSON.stringify(editor.getJSON(), null, 2));
                setSourceMode('markdown');
              } else {
                try {
                  const parsed = JSON.parse(markdownSource);
                  editor.commands.setContent(parsed);
                } catch {
                  // Keep as is
                }
                setSourceMode('visual');
              }
            }}
            className="h-7 text-[11px] px-2 font-mono"
          >
            {sourceMode === 'visual' ? 'Markdown Source' : 'Visual View'}
          </Button>

          <div className="mx-1 h-4 w-px bg-border" />

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => editor.chain().focus().undo().run()}
            disabled={!editor.can().undo()}
            className="h-7 w-7 p-0"
            aria-label="Undo"
          >
            <Undo className="h-3.5 w-3.5" />
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => editor.chain().focus().redo().run()}
            disabled={!editor.can().redo()}
            className="h-7 w-7 p-0"
            aria-label="Redo"
          >
            <Redo className="h-3.5 w-3.5" />
          </Button>
        </div>
      </div>

      {/* Editor Content Area */}
      {sourceMode === 'visual' ? (
        <div className="p-4 min-h-[360px] prose dark:prose-invert max-w-none text-sm focus:outline-none">
          <EditorContent editor={editor} />
        </div>
      ) : (
        <div className="p-4 min-h-[360px]">
          <Textarea
            value={markdownSource}
            onChange={(e) => setMarkdownSource(e.target.value)}
            className="w-full h-80 font-mono text-xs resize-none"
            placeholder="Document JSON or Markdown AST..."
          />
        </div>
      )}

      {/* Media Picker Modal */}
      <MediaPickerModal
        open={mediaPickerOpen}
        onClose={() => setMediaPickerOpen(false)}
        onSelect={handleMediaSelected}
      />

      {/* Link Dialog */}
      <Dialog open={linkDialogOpen} onOpenChange={setLinkDialogOpen}>
        <DialogContent className="max-w-md bg-background">
          <DialogHeader>
            <DialogTitle className="text-sm font-semibold">Insert or Edit Link</DialogTitle>
          </DialogHeader>
          <div className="space-y-3 py-2">
            <div className="space-y-1">
              <Label className="text-xs">Destination URL or Internal Path</Label>
              <Input
                value={linkUrl}
                onChange={(e) => setLinkUrl(e.target.value)}
                placeholder="https://example.com or /posts/my-post"
                className="text-xs h-8"
              />
            </div>
            <div className="flex items-center gap-2 pt-1">
              <input
                type="checkbox"
                id="link-new-tab"
                checked={linkOpenInNewTab}
                onChange={(e) => setLinkOpenInNewTab(e.target.checked)}
                className="rounded border-border"
              />
              <Label htmlFor="link-new-tab" className="text-xs font-normal cursor-pointer">
                Open in new window / tab
              </Label>
            </div>
          </div>
          <DialogFooter className="gap-2">
            <Button variant="outline" size="sm" onClick={() => setLinkDialogOpen(false)} className="text-xs">
              Cancel
            </Button>
            <Button size="sm" onClick={applyLink} className="text-xs">
              Apply Link
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Embed Dialog */}
      <Dialog open={embedDialogOpen} onOpenChange={setEmbedDialogOpen}>
        <DialogContent className="max-w-md bg-background">
          <DialogHeader>
            <DialogTitle className="text-sm font-semibold">Insert Embed (YouTube, Vimeo, etc.)</DialogTitle>
          </DialogHeader>
          <div className="space-y-3 py-2">
            <div className="space-y-1">
              <Label className="text-xs">Embed URL</Label>
              <Input
                value={embedUrl}
                onChange={(e) => setEmbedUrl(e.target.value)}
                placeholder="https://www.youtube.com/watch?v=..."
                className="text-xs h-8"
              />
            </div>
            <div className="space-y-1">
              <Label className="text-xs">Optional Caption</Label>
              <Input
                value={embedCaption}
                onChange={(e) => setEmbedCaption(e.target.value)}
                placeholder="Descriptive figure caption..."
                className="text-xs h-8"
              />
            </div>
          </div>
          <DialogFooter className="gap-2">
            <Button variant="outline" size="sm" onClick={() => setEmbedDialogOpen(false)} className="text-xs">
              Cancel
            </Button>
            <Button size="sm" onClick={insertEmbed} disabled={!embedUrl.trim()} className="text-xs">
              Insert Embed
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
