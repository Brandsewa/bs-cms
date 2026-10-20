# BS CMS Markdown Directives Guide

BS CMS provides native support for structured content blocks in rich text fields through standard CommonMark extended with **fenced directive syntax** (`:::directive`). When creating or updating entries via the API or MCP tools (`create_entry`, `update_entry`), agents can supply `<field>_markdown` (for example `body_markdown`). The backend parses these directives into canonical ProseMirror / Tiptap document nodes and serializes them back to clean Markdown upon read.

---

## 1. Callout Blocks

Callouts highlight tips, warnings, or notes to guide the reader.

### Syntax
```markdown
:::callout <type> [<optional title>]
Content inside the callout paragraph.
:::
```

### Supported Types
- `info`: General context or informational hints (default).
- `tip`: Pro-tips, recommendations, or best practices.
- `warning`: Cautions, breaking changes, or dangerous actions.
- `note`: Background details or ancillary notes.

### Example
```markdown
:::callout tip [Production Deployment]
Always verify database migrations before promoting a release to live servers.
:::
```

---

## 2. FAQ (Frequently Asked Questions)

FAQ blocks capture question-and-answer pairs. When rendered on the frontend, FAQ blocks automatically generate Schema.org `FAQPage` JSON-LD structured data for rich search snippets and LLM search indexing.

### Syntax
```markdown
:::faq
### First Question?
Detailed answer to the first question with clear, concise sentences.

### Second Question?
Detailed answer to the second question.
:::
```

### Formatting Rules
- Questions must use `###` (H3 level).
- Answers follow immediately as standard paragraphs.
- Keep answers self-contained and objective to assist LLM answer extraction.

---

## 3. Key Facts (Structured Claims & Citations)

The Key Facts block allows editors and AI agents to record verifiable claims, metrics, and data points with authoritative source links. This structure is heavily utilized by Generative Engine Optimization (GEO) algorithms to extract citable snippets.

### Syntax
```markdown
:::facts
- <Statement or Metric> | <Value> | <Authoritative Source URL>
- <Statement or Metric> | <Value> | <Authoritative Source URL>
:::
```

### Example
```markdown
:::facts
- Response Time | 14ms TTFB | https://benchmark.example.com/2026/ttfb
- Memory Usage | < 32MB Peak | https://benchmark.example.com/2026/memory
- Supported Protocols | MCP 2025-06-18 | https://modelcontextprotocol.io
:::
```

---

## 4. Media & Embeds

### Standard Images
Always provide descriptive, contextual alt text for all images.
```markdown
![Architecture diagram showing MCP client connecting via HTTP POST to BS CMS](/media/01HTERM000000000000000001.webp)
```

### Embeds
Embeds for video, audio, or interactive media use the `:::embed` directive. In the Astro starter, embeds render as lightweight, privacy-respecting click-to-load placeholders that satisfy strict CSP rules (`script-src 'self'`).

```markdown
:::embed https://www.youtube.com/watch?v=dQw4w9WgXcQ
:::
```

---

## 5. Buttons and Call-to-Actions (CTAs)

Interactive buttons for downloads, links, or signups.

### Syntax
```markdown
:::button [<Button Label>] <Target URL>
:::
```

### Example
```markdown
:::button [Download Release v1.0] https://github.com/Brandsewa/bs-cms/releases
:::
```

---

## 6. Standard Elements & Hierarchy Rules

- **Headings**: The entry title occupies `H1`. Body content must use `##` (H2) for top-level sections, `###` (H3) for subsections, and `####` (H4) for nested points. Never skip heading levels (e.g. do not jump from H2 directly to H4).
- **Lists**: Use unordered (`-`) and ordered (`1.`) lists for scannability.
- **Tables**: Use standard GitHub-flavored Markdown tables with clean header rows.
- **Code Blocks**: Fenced with triple backticks and language identifiers (` ```php `, ` ```bash `, ` ```json `).
- **Internal Links**: Use root-relative paths matching existing collection routes (e.g., `/blog/getting-started`).

---

## 7. Derived Fields & Automatic Synchronization

When an entry containing rich text is saved:
1. **Excerpt**: The first non-heading paragraph is extracted and trimmed to 160 characters.
2. **Word Count & Reading Time**: Calculated automatically based on a standard 200 words-per-minute pace.
3. **Outline**: Headings (H2-H4) are parsed into a table of contents with kebab-cased slugs.
4. **Media IDs & Links**: All referenced media assets and internal paths are indexed for referential integrity.
