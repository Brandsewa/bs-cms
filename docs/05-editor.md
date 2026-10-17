# 05. Blog post editor and page editing (BS CMS)

Status: draft for implementation. Goal: a **Ghost / Notion-like writing experience for blog posts**, light enough for a dashboard that must stay fast. Pages are mostly code-defined and need only a simple editor.

## 1. Where each editor is used

| Content | Editing approach |
|---|---|
| **Blog posts** (`posts.body`) | Full block-style article editor: slash menu, bubble toolbar, drag handles, media, embeds, FAQ and callout blocks (profile `article`) |
| **Pages** (`pages`) | Layout and sections are generated in code (Astro components). The dashboard shows the page's defined fields; text regions use the same editor library in a small `minimal` profile (bold, italic, link, lists, headings) so there is only one editor dependency in the bundle |
| Other custom collections | Field type `richtext` with profile chosen in the schema builder (`minimal` or `article`) |
| Term descriptions, author bios, captions | `minimal` profile or plain textarea |

## 2. Candidates compared

Facts below come from the searches unless marked **(unverified)**; confirm licences and bundle sizes in the Phase 0 editor spike before locking in.

| Editor | Licence | Model / foundation | Notion-like out of the box? | Weight and performance | Fit for BS CMS |
|---|---|---|---|---|---|
| **Tiptap** | Core editor MIT and free; [Cloud features are paid](https://tiptap.dev/feature-comparison) (AI generation, real-time collaboration, comments, document history, webhooks). Several former Pro extensions are [now MIT](https://tiptap.dev/blog/release-notes/were-open-sourcing-more-of-tiptap): Details, Emoji, DragHandle, FileHandler, InvisibleCharacters, Mathematics, TableOfContents, UniqueID. One source still lists a "Cloud Pro Drag Handle" as Pro, so confirm which DragHandle is free **(verify)** | Headless, built on ProseMirror, 100+ extensions, framework-agnostic | No: you build slash menu, bubble menu and block UI on its primitives (it has a Suggestion utility; drag handle now free) | Modular and tree-shakable. Size claims conflict: one source says base editor about 45 KB gzipped, another says smaller than Lexical, Quill and Slate; no reliable figure found, check Bundlephobia in the spike | **Recommended**: largest ecosystem, JSON document model, free core, full control of look (works with shadcn/Tailwind) |
| **BlockNote** | Core [MPL-2.0](https://github.com/TypeCellOS/BlockNote) (commercial and closed-source use allowed; changes to its own files must be published). `xl-*` packages are **GPL-3.0** or commercial licence | Built on ProseMirror and Tiptap; has a shadcn / Tailwind / Radix UI package | **Yes**: slash menu, drag handles, block side menu, formatting toolbar | Heavier because it bundles its own UI and block model | Strong Notion-like starting point. Risks: MPL obligations if we modify it, GPL traps in `xl-*` (AI, multi-column, docx/pdf export), block model is BlockNote's own |
| **Lexical** (Meta) | MIT, fully open source, no paid tier | Own framework-agnostic engine, immutable editor state, plugin architecture; [powers Ghost's current editor](https://ghost.org/changelog/new-editor/) | No: lower-level than Tiptap; slash menu, drag handle and block UI are yours to build | Core reported at about 22 KB (one comparison; unverified what that measures); designed for performance on large documents; Ghost cited faster performance and better handling of very large posts when it moved | Best raw performance and the closest technical match to Ghost's experience, but more engineering and a smaller extension ecosystem |
| **Novel** | Apache-2.0 reported (sources conflict with MIT) **(verify)** | Notion-style editor on Tiptap with AI autocompletion | Yes | Tiptap + extras | Useful as a **reference implementation** of slash menu and bubble menu on Tiptap; the project looked inactive in one source, so do not depend on it |
| **Plate** | MIT **(unverified, not covered by search)** | Built on Slate; shadcn-style components | Close | Slate-based, heavier | Alternative if we want Slate; not evaluated |
| **Quill** | BSD-3-Clause **(unverified)** | Delta document format | No: classic toolbar editor | Light and simple | Fine for simple needs, wrong feel for a Notion-style experience |
| **ProseMirror** (direct) | MIT **(unverified)** | The toolkit under Tiptap and BlockNote | No | Smallest dependency set | Too low-level to build directly |
| **Editor.js** | Apache-2.0 **(unverified)** | Block JSON output | Block-based but dated look | Light | Possible, but weaker rich-text behaviour and ecosystem |

Not recommended: CKEditor, TinyMCE (licence/commercial constraints, heavy), Draft.js (legacy).

## 3. Recommendation

**Use Tiptap (MIT core) with our own block UX layer**, for these reasons:
1. Free core, no cloud dependency (collaboration is not a v1 goal and the real-time backend needs a Node process that shared hosting cannot run).
2. ProseMirror-based, so the stored document is a standard JSON tree that can be validated, transformed and rendered outside the browser.
3. Modular: the `minimal` profile for pages and the `article` profile for posts come from the same dependency, loaded only on editor routes.
4. Largest ecosystem and documentation, and the free extensions we need (drag handle, details, table of contents, file handler, unique ID, tables, tasks, images, links, code block).
5. Keeps the editor look under our control (shadcn / Tailwind), consistent with the dashboard design system.

**Why not BlockNote:** it is the fastest route to a Notion feel, but its block model and MPL/GPL licence split add constraints on a product that may later be sold or open-sourced; we would rather build the UX layer once on Tiptap. **Why not Lexical:** lighter and proven by Ghost, but we would build more from scratch and have fewer ready extensions.

**Decision: Tiptap is final** (no comparison spike against Lexical). Phase 0 only includes a short Tiptap check: pin versions, confirm which extensions are MIT (especially DragHandle), and measure the gzipped size of the editor chunk, time to interactive and typing latency in a 10,000-word document, to set the performance budget in section 7.

## 4. Document model and storage (important)

- **Canonical storage:** ProseMirror/Tiptap JSON in the `richtext` field (`posts.data.body`), restricted to a documented **node and mark allow-list** (our "BS document schema"). The server validates incoming documents against the allow-list (unknown nodes or attributes rejected) and sanitises URLs and attributes.
- **AI-friendly input:** agents write Markdown, not editor JSON. The API and MCP tools accept either `body` (document JSON) or `body_markdown`; the server converts Markdown to the document JSON using a CommonMark parser (e.g. `league/commonmark`) plus a mapper to our node set, and returns both forms on read. Round-trip tests ensure Markdown -> JSON -> Markdown is stable for supported nodes. Custom blocks (callout, FAQ, key facts, embed, bookmark) have a Markdown syntax (fenced directive blocks) documented in the agent briefing pack.
- **Rendering:** the public HTML is rendered in Astro from the JSON using one shared renderer (static renderer or a custom node-to-component map). Markdown views and `llms-full.txt` are produced from the same JSON so HTML, markdown and JSON-LD never disagree. The PHP side never renders HTML for the public site.
- **Derived fields computed on save (server):** plain-text excerpt, reading time, word count, heading outline (for table of contents), list of image ids (for `media_usage`), list of internal links (for broken-link checks and orphan detection), extracted FAQ items (for `FAQPage` JSON-LD), plain `search_text`.
- **Versioning:** each save creates an `entry_versions` snapshot (autosaves throttled and pruned); diff view compares block-level changes.
- **Migration safety:** every document stores `schema_version`; upgrades run as explicit migrations so old posts keep rendering.

## 5. Article editor (profile `article`) - feature checklist

Implementation is complete only when every item exists and has tests.

**Writing experience**
- [ ] Title field above the body (large, plain, auto-grow), with the slug and excerpt controls in the sidebar; body starts with a prompt ("Type / for blocks").
- [ ] **Slash menu** (`/`): filterable, grouped, keyboard navigable, with icons and descriptions; includes Paragraph, Heading 2/3/4, Bulleted list, Numbered list, Task list, Quote, Callout, Divider, Code block, Image, Gallery, Video / embed, Bookmark (link card), File, Table, Toggle (details), FAQ, Key facts, Table of contents, Button, HTML-free "Custom block" registry for theme/plugin blocks.
- [ ] **Bubble toolbar** on selection: bold, italic, underline, strike, inline code, link (with entry search for internal links and "open in new tab"), highlight, text colour (limited palette), heading toggle, clear formatting; keyboard shortcuts shown in tooltips.
- [ ] **Floating "+" insert button** on empty lines and a **drag handle with block menu** (turn into, duplicate, delete, move up/down, copy link to block); drag-and-drop block reordering with drop indicator.
- [ ] Markdown shortcuts (`#`, `##`, `-`, `1.`, `[]`, `>`, `---`, triple backtick, `**bold**`, `_italic_`, backticks).
- [ ] H1 is reserved for the title: body offers H2 to H4 only; pasting H1 converts to H2.
- [ ] Paste handling: clean paste from Word / Google Docs / web (strips styles, keeps structure), paste Markdown as formatted content, paste image uploads it, paste URL on selection makes a link, paste video URL offers embed.
- [ ] Drag-and-drop and paste image upload with progress, direct into the media library, alt-text prompt (required by lint), caption, alignment (inline, wide, full width), focal point not required in editor.
- [ ] Undo / redo with grouping; word count, character count and reading time in a footer; focus (distraction-free) mode; typewriter scroll optional.
- [ ] Autosave every 30 s and on blur; "Saved" indicator; conflict detection with another editor session; local backup of unsaved content in browser storage.
- [ ] Full keyboard operation and screen-reader labels; works on mobile / touch (long-press drag, large tap targets).

**Blocks**
- [ ] Paragraph, headings (H2-H4), lists (bulleted, numbered, task, nested), blockquote with optional citation, callout (info, tip, warning, note; icon + colour), divider.
- [ ] Code block with language selector and syntax highlighting (lightweight highlighter loaded on demand), copy button on the public site.
- [ ] Image (with alt, caption, link, size / alignment), gallery (grid with captions), file download, button.
- [ ] Embeds with a **provider allow-list** (YouTube, Vimeo, X / Twitter, Spotify, maps, etc.) rendered as privacy-friendly click-to-load iframes in Astro; any other URL becomes a **bookmark card** (title, description, image fetched server-side with SSRF protection and size / time limits).
- [ ] Table (header row, add / remove / reorder rows and columns, cell merge optional), responsive on the public site.
- [ ] Toggle (details / summary), table of contents (auto from headings), footnotes *(later)*.
- [ ] **FAQ block** (question + rich answer, reorder) feeding `FAQPage` JSON-LD; **Key facts** block (statement, value, source URL) for citable facts; **Author note / reviewed-by** block optional.
- [ ] Custom blocks registered by the theme or plugins through a block registry (name, schema, editor component, Astro renderer), so e.g. a Donate button block from a plugin appears in the slash menu.

**Links and SEO helpers**
- [ ] Internal link picker searching all collections; broken internal link detection on save.
- [ ] SEO side panel reads the live document: heading outline, missing alt text, answer-summary prompt, link counts, readability hints (heuristics, never blocking).
- [ ] "Insert related posts" and "Insert table of contents" helpers.

**Review and AI (optional, off by default)**
- [ ] Comments / suggestions *(later)*. Real-time collaboration is out of scope for v1.
- [ ] Inline AI assist (rewrite, shorten, translate, generate alt text) using the owner's configured provider key, through the job/API layer, never required.
- [ ] Changes made by AI agents are marked in version history and appear in the pending-changes diff viewer as block-level diffs.

## 6. Minimal profile (`minimal`) - for pages and short fields
- [ ] Inline marks only: bold, italic, link, optional underline / highlight; optional lists and H2 / H3 where the field config allows; no slash menu, no drag handle, no embeds; single-paragraph variant for headings and captions.
- [ ] Field config chooses allowed nodes; the same server-side allow-list validation applies.
- [ ] Page structure itself (sections, hero, columns) is code: Astro components with typed props; the dashboard form is generated from those prop definitions (text, link, image, repeater), not from a visual builder (visual builder is a later phase).

## 7. Performance rules for the editor
- Editor code is a separate lazy chunk loaded only on routes that need it; show a skeleton shaped like the editor while it loads; prefetch on hover of "New post" / row.
- Editor extensions for rarely used blocks (code highlighting, tables, embeds, gallery) load on demand when first inserted.
- No editor code in the public site bundle: Astro renders static HTML from JSON; only interactive blocks hydrate (islands) such as click-to-load embeds.
- Budget (targets to confirm in the spike): editor route chunk as small as achievable (spike will set the number), typing latency under 16 ms per keystroke in a 10,000-word document, no layout shift while loading.
- Large documents: virtualisation is not available in rich text, so warn above a configurable size (e.g. 50,000 words) and suggest splitting.

## 8. Security rules for the editor
- Sanitise all URLs (`http`, `https`, `mailto`, `tel` only), strip event handler attributes, never store raw HTML; an "HTML embed" block is available to developers only and renders in a sandboxed iframe.
- Embed allow-list is configurable by admins; bookmark fetching protects against SSRF (block private IP ranges, redirects limit, content-type and size limits).
- Uploaded files validated by content, not extension; images re-encoded; SVG sanitised or disallowed by default.

## 9. Open questions for this area
1. Confirm which Tiptap extensions are truly MIT today (especially DragHandle) and pin versions.
2. Decide whether to support Markdown directive syntax for every custom block in v1 or only callout, FAQ and key facts.
3. Collaboration: confirm it stays out of scope (it needs a persistent Node service).
4. (Settled: Tiptap is the editor; no Lexical comparison.)
