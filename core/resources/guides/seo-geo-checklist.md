# BS CMS SEO & Generative Engine Optimization (GEO) Checklist

This checklist defines the technical and content requirements for maximizing organic search ranking and AI engine citations (Google AI Overviews, Claude, ChatGPT Search, Perplexity).

---

## 1. Traditional SEO Checklist

### On-Page Metadata
- [ ] **Title Tag**: 50–60 characters in length. Place the primary keyword toward the front. Include a brand suffix (e.g., `Architecture Overview | BS CMS`).
- [ ] **Meta Description**: 140–160 characters. Provide a compelling, active-voice summary of the content that encourages click-through.
- [ ] **Canonical URL**: Self-referential, absolute canonical link tag on every public route (`<link rel="canonical" href="https://example.com/blog/my-post" />`).
- [ ] **Robots Directives**: Default to `index, follow, max-image-preview:large`. Set `noindex, nofollow` only for utility or preview pages.
- [ ] **Social Graph Tags**: Include Open Graph (`og:title`, `og:description`, `og:image`, `og:url`, `og:type`) and Twitter Cards (`twitter:card`, `twitter:title`, `twitter:description`).

### Content Structure & Hierarchy
- [ ] **Single H1**: Exactly one `H1` tag per page (the entry title).
- [ ] **Heading Outline**: Proper nesting with `H2` for main sections and `H3` for subsections. Never skip levels.
- [ ] **URL Slugs**: Concise, lowercase, hyphenated slugs matching the primary topic (e.g., `/blog/zero-node-architecture`).
- [ ] **Internal Linking**: Minimum of 2–3 contextual links to related collection items using descriptive anchor text.

---

## 2. Generative Engine Optimization (GEO) Checklist

Generative search engines select sources that exhibit high factual density, authoritative structure, and unambiguous definitions.

### Direct Answer Summaries
- [ ] **First-Paragraph Answer**: Answer the core search question or problem statement within the first 100 words. State the definition or solution clearly using standard declarative syntax (e.g., *"BS CMS is an autonomous headless content management system..."*).
- [ ] **Bullet Summaries**: For complex guides, include a 3-point bulleted summary at the beginning of the article.

### Structured Q&A (`:::faq`)
- [ ] **FAQ Directives**: Add a `:::faq` block containing 3–5 common questions.
- [ ] **JSON-LD Synchronization**: Ensure the Astro frontend renders `FAQPage` schema from the FAQ block to secure rich snippets and AI grounding.

### Citable Facts & Attribution (`:::facts`)
- [ ] **Verifiable Metrics**: Include a `:::facts` block with specific, sourced measurements (e.g., benchmark numbers, adoption statistics, release dates).
- [ ] **Primary Source URLs**: Every fact must point to a legitimate external or internal reference URL. LLM grounding engines heavily favor verifiable claims.

---

## 3. Technical & Performance Verification

- [ ] **Image Accessibility**: 100% of embedded images must include meaningful `alt` text.
- [ ] **Layout Stability**: Images and embeds must define dimensions or aspect ratios (`aspect-ratio: 16 / 9`) to prevent Cumulative Layout Shift (CLS).
- [ ] **Structured Data Syntax**: Run Google Rich Results or schema linters to ensure JSON-LD parses without errors.
- [ ] **Site Health Verification**: Execute the MCP tool `get_site_health` (or `/api/v1/health`) after batch updates to ensure no 404 links, invalid redirects, or orphan media exist.
