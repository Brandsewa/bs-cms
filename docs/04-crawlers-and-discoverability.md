# 04. Crawler policy and discoverability (BS CMS)

Status: draft for implementation. This document replaces the loose crawler notes in `PLAN.md` section 6.7 and gives the detailed requirements for SEO, GEO/AEO and agentic indexing. These sites may run in production for real businesses, so the rule is: **safe by default, nothing that can silently remove a site from search**.

## 1. What the research established (and what remains unverified)

| Finding | Source | Confidence |
|---|---|---|
| OpenAI runs three bots: **OAI-SearchBot** (surfaces sites in ChatGPT search; blocking removes you from those answers), **GPTBot** (content that may be used for training; disallowing signals no training), **ChatGPT-User** (user-initiated visits; "robots.txt rules may not apply"; not an automatic crawler). Each setting is independent. IP ranges are published as JSON for each bot. Changes take about 24 hours. | [OpenAI bots docs](https://developers.openai.com/api/docs/bots) | High (primary source) |
| Anthropic: bots honour robots.txt and `Crawl-delay`; to block, add a `User-agent: ClaudeBot` group; **Anthropic does not publish IP ranges** and warns IP-blocking can interfere with reading robots.txt. Secondary sources list three bots: ClaudeBot (training), Claude-SearchBot (search index), Claude-User (user-triggered fetch). | [Anthropic support article](https://support.claude.com/en/articles/8896518-what-is-claudebot) (primary for the first part); roles of the three bots from secondary sources | High / Medium |
| **Google-Extended is a control token, not a crawler.** It governs use of content for Gemini training/grounding, and does **not** remove a site from AI Overviews or Search. To limit AI Overviews / AI Mode use of a page, snippet controls (`nosnippet`, `max-snippet`) apply, at the cost of snippets in Search generally. Page-level rules only work if the page is crawlable, and directives should be in the initial HTML. | Secondary sources ([Marie Haynes](https://www.mariehaynes.com/should-you-use-google-extended-in-robots-txt/), [ppc.land on nosnippet](https://ppc.land/nosnippet/)) | Medium (verify on Google Search Central) |
| A user-agent string proves nothing; verify bots by **forward-confirmed reverse DNS** or the operator's **published IP ranges**, using the crawler-specific list (not a broad cloud list). Range lists change, so refresh regularly. | [DEV Community guide](https://dev.to/inxprncd/how-to-verify-an-ai-crawler-is-who-it-says-it-is-470e), [Tenten](https://geo.tenten.co/en/blog/verify-ai-crawlers-server-logs) | Medium |
| A blanket block of AI bots can remove a site from AI search products. | Several secondary sources | Medium |
| **llms.txt:** adoption grew, but 97% of files got zero requests in May 2026 and major AI crawlers mostly fetch HTML directly. Google reportedly does not support it. | [Ahrefs-based analysis](https://ppc.land/llms-txt-adoption-rises-8-8x-but-97-of-files-get-zero-ai-requests/) | Medium |
| **Markdown for agents:** serving markdown when `Accept: text/markdown` is sent (with `Vary: Accept`) is documented by Cloudflare; their test shows about 5x fewer tokens. SEO commentary warns of cloaking if markdown differs from HTML. | [Cloudflare docs](https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents) | High for mechanism |
| **IndexNow** notifies Bing, Yandex and others but not Google. Google's Indexing API is limited to job-posting and livestream content. For Google use sitemaps with honest `lastmod`; Google ignores `changefreq` and `priority`. | [Agility CMS doc](https://agilitycms.com/docs/developers/faster-indexing-with-xml-sitemaps-and-indexnow) and others | Medium |
| **Other bot tokens** (CCBot, Applebot-Extended, PerplexityBot, Perplexity-User, Meta-ExternalAgent, Bytespider, cohere-ai) audited against published vendor documentation. | Vendor documentation links (Meta, Apple, Common Crawl, Perplexity, Cohere) | Verified where official vendor docs exist; Bytespider marked unverified (no published vendor docs on bytedance.com); Cohere confirmed inactive. |
| No major AI vendor requires cryptographic request signing yet. | secondary | Low-Medium |

## 2. Principles

1. **Never touch Googlebot or Bingbot rules by accident.** Search engines are always allowed in every preset except an explicit, confirmed "Private" mode.
2. **Decide per purpose, not per brand:** search/retrieval bots, user-triggered agents, training bots.
3. **robots.txt is a request, not security.** Real enforcement happens at the edge (CDN/WAF/host), which the CMS cannot do for a static site. The CMS generates policy and gives copy-paste edge rules.
4. **Don't depend on IP blocking for vendors that don't publish IPs** (Anthropic). Use robots.txt for them.
5. **Staging must never be indexable; production must never be accidentally blocked.** Environment-aware safeguards below.
6. **Every change is versioned, diffed, previewed, and reversible.**
7. **Re-review on a schedule**: bot lists change.

## 3. Bot catalogue (data, not code)

Stored as a versioned JSON file shipped with the CMS and updatable without a core release. Each entry:

```json
{ "token": "OAI-SearchBot", "vendor": "OpenAI", "purpose": "search", "respects_robots": true,
  "ip_list_url": "https://openai.com/searchbot.json", "verification": "ip_list", "source": "https://developers.openai.com/api/docs/bots",
  "verified_on": "2026-10-17", "notes": "Blocking removes site from ChatGPT search answers" }
```

Purposes: `search_engine`, `ai_search`, `user_agent` (user-triggered), `training`, `other`.

| Token | Vendor | Purpose | Default (Balanced) | Verification | Status |
|---|---|---|---|---|---|
| Googlebot | Google | search_engine | **Allow, locked** | reverse DNS ([Google Search Central](https://developers.google.com/search/docs/crawling-indexing/googlebot)) | **sourced / verified** |
| Bingbot | Microsoft | search_engine | **Allow, locked** | reverse DNS / published ranges ([Bing Webmaster Tools](https://www.bing.com/webmasters/help/which-crawlers-does-bing-use-8c184ec0), [`bing.com/toolbox/bingbot.json`](https://www.bing.com/toolbox/bingbot.json)) | **sourced / verified** |
| DuckDuckBot, Applebot, YandexBot, Baiduspider | various | search_engine | Allow | per vendor docs ([Applebot](https://support.apple.com/en-us/119829), [DuckDuckBot](https://duckduckgo.com/duckduckgo-help-pages/results/duckduckbot/)) | **sourced** |
| OAI-SearchBot | OpenAI | ai_search | Allow | [`openai.com/searchbot.json`](https://openai.com/searchbot.json) ([OpenAI docs](https://platform.openai.com/docs/bots)) | **sourced** |
| ChatGPT-User | OpenAI | user_agent | Allow | [`openai.com/chatgpt-user.json`](https://openai.com/chatgpt-user.json) ([OpenAI docs](https://platform.openai.com/docs/bots)) | **sourced** |
| GPTBot | OpenAI | training | **Disallow** | [`openai.com/gptbot.json`](https://openai.com/gptbot.json) ([OpenAI docs](https://platform.openai.com/docs/bots)) | **sourced** |
| Claude-SearchBot | Anthropic | ai_search | Allow | no published IPs ([Anthropic support](https://support.claude.com/en/articles/8896518-what-is-claudebot)) | secondary |
| Claude-User | Anthropic | user_agent | Allow | no published IPs ([Anthropic support](https://support.claude.com/en/articles/8896518-what-is-claudebot)) | secondary |
| ClaudeBot | Anthropic | training | **Disallow** | no published IPs ([Anthropic support](https://support.claude.com/en/articles/8896518-what-is-claudebot)) | **sourced** |
| PerplexityBot | Perplexity | ai_search | Allow | [`docs.perplexity.ai/docs/perplexitybot`](https://docs.perplexity.ai/docs/perplexitybot) / [`perplexity.ai/perplexitybot.json`](https://www.perplexity.ai/perplexitybot.json) | **sourced / verified** |
| Perplexity-User | Perplexity | user_agent | Allow | [`docs.perplexity.ai/docs/perplexitybot`](https://docs.perplexity.ai/docs/perplexitybot) | **sourced / verified** |
| Google-Extended | Google | training (control token) | **Disallow** | n/a (control token only, [Google docs](https://developers.google.com/search/docs/crawling-indexing/google-extended)) | **sourced / verified** |
| Applebot-Extended | Apple | training (control token) | Disallow | n/a (control token only, [Apple support 119829](https://support.apple.com/en-us/119829)) | **sourced / verified** |
| CCBot | Common Crawl | training | Disallow | [`commoncrawl.org/ccbot`](https://commoncrawl.org/ccbot) / [`index.commoncrawl.org/ccbot.json`](https://index.commoncrawl.org/ccbot.json) | **sourced / verified** |
| Meta-ExternalAgent | Meta | training | Disallow | [Meta Web Crawlers documentation](https://developers.facebook.com/docs/sharing/webmasters/web-crawlers/) | **sourced / verified** (Meta's crawler for training AI foundation models; respects robots.txt) |
| Bytespider | ByteDance | training | Disallow | No official vendor crawler docs or published IP list on bytedance.com (User-Agent string references `https://zhanzhang.toutiao.com/` and `spider-feedback@bytedance.com`) | **unverified / vendor unpublished** |
| cohere-ai | Cohere | training | Disallow | [Cohere Web Crawlers documentation](https://cohere.com/web-crawlers) (*"We do not use Cohere bots or user agents for the purpose of crawling or scraping web content to train generative AI foundation models at this time."*) | **verified inactive (vendor explicitly states no active training crawler)** |

Rules:
- A row marked "Allow, locked" cannot be changed except via the Private preset with a type-to-confirm dialog.
- Unknown/new bots default to the preset's "unknown bot" setting (Balanced: allow, because robots.txt `User-agent: *` governs them).
- Bots flagged `unverified` show a warning icon in the policy UI until a maintainer verifies them.

## 4. Presets

| Preset | Search engines | AI search + user-triggered | Training bots | Use case |
|---|---|---|---|---|
| **Balanced (default)** | Allow | Allow | Disallow | Most business/NGO sites: visibility in classic and AI search, no training use |
| Open | Allow | Allow | Allow | Sites that want maximum reach and don't mind training use |
| Private | Allow (or Disallow if "block everything" is explicitly chosen) | Disallow | Disallow | Sensitive content; blocks AI search visibility, so a confirmation explains the trade-off |
| Custom | per bot | per bot | per bot | Anything else |

The setting is changeable at any time (Settings > SEO and discoverability > AI crawlers). Policy can be exported and imported as JSON so the same policy can be applied across several production sites.

## 5. Safety rails (production safety)

1. **Environment flag** in `site_settings.environment`:
   - `staging`: the Astro build outputs `Disallow: /` robots, `<meta name="robots" content="noindex, nofollow">`, `X-Robots-Tag: noindex` header, no sitemap, and the dashboard shows an amber banner. Recommended in addition: HTTP auth or IP allow-list at the host (robots/noindex are advisory).
   - `production`: normal output.
   - Switching an environment requires step-up re-auth and type-to-confirm.
2. **Pre-publish lint on every robots.txt/policy change** (blocking errors):
   - `User-agent: *` must not contain `Disallow: /` in production.
   - Googlebot/Bingbot must not be disallowed (unless Private + confirmation).
   - Sitemap URLs must be absolute, same host, and resolvable.
   - robots.txt must be under 500 KiB, valid syntax, no conflicting rules (preview shows how each bot is resolved).
3. **Diff + preview + confirm** screen for every policy change; **version history** (`crawler_policy_versions`) with one-click rollback.
4. **Canary check after publish:** the CMS fetches the live `/robots.txt` and a sample page as Googlebot/OAI-SearchBot user agents (simulated, using robots.txt rules logic) and reports "Googlebot allowed: yes" etc.; alerts if the live file differs from the stored version.
5. **Alert if the live site returns `noindex` on pages that should be indexed** (header or meta) during site-health checks.
6. **Admin/API host** always serves `Disallow: /` and `X-Robots-Tag: noindex, nofollow` (these are not public content). Only the Astro public site is indexed.
7. **Change notification:** owners get an email when crawler policy or environment changes.
8. **No automatic updates of bot behaviour without review:** catalogue updates arrive as a proposal ("2 new bots, 1 changed") that an admin accepts; default decisions for new training bots are "disallow", for new search bots "allow only after review".

## 6. Generated outputs

### 6.1 robots.txt (Balanced preset, production)
```
# Generated by BS CMS (policy v12). Do not edit by hand; change it in the dashboard.
User-agent: *
Allow: /
Disallow: /preview/
Disallow: /_astro-internal/

# AI search and user-triggered access: allowed
User-agent: OAI-SearchBot
User-agent: ChatGPT-User
User-agent: Claude-SearchBot
User-agent: Claude-User
User-agent: PerplexityBot
Allow: /

# AI training: not allowed
User-agent: GPTBot
User-agent: ClaudeBot
User-agent: Google-Extended
User-agent: Applebot-Extended
User-agent: CCBot
Disallow: /

Sitemap: https://example.com/sitemap-index.xml
```
Notes: group syntax follows RFC 9309; keep the file small; optional `Content-Signal` line (`search=yes, ai-input=yes, ai-train=no`) is a voluntary convention and is off by default until verified **(verify)**.

### 6.2 Page-level controls
- `<meta name="robots" content="...">` and `X-Robots-Tag` from the entry's `seo` field (`noindex`, `nofollow`, `max-snippet`, `max-image-preview`, `nosnippet`). Default: `max-image-preview:large`, no snippet restrictions. A per-entry "limit AI/snippet use" switch sets `nosnippet`/`max-snippet` and explains it also reduces normal search snippets.
- Directives are rendered in the initial HTML (no JavaScript injection).

### 6.3 Edge rules export (optional helper)
For sites behind Cloudflare/other CDNs the dashboard can export: a rate-limit suggestion for unverified bots claiming a known user agent, and (for vendors with published IP lists) a "verified bots only" rule. Anthropic bots are never IP-restricted. The CMS does not enforce these itself.

### 6.4 Bot visibility
- A static site has no PHP in the request path, so the CMS cannot log crawls itself. Options (all optional): upload/paste access logs for analysis; read CDN analytics via API *(later)*; log only the dynamic endpoints (markdown API, public MCP, search) into `bot_log`, aggregated daily.
- Verified vs unverified: when an IP list exists, bots are labelled verified/unverified in the report.

## 7. Sitemaps
- `sitemap-index.xml` with one sitemap per collection/taxonomy archive, max 50,000 URLs or 50 MB each.
- `<lastmod>` only from the entry's real `updated_at` of **substantive** content changes (title, body, key fields), not cache clears or build times; omit when unknown. No `changefreq`/`priority`.
- Only indexable, 200-status, canonical URLs; exclude `noindex`, redirects, drafts, private pages.
- Image sitemap entries for featured images; hreflang entries when localization arrives.
- Regenerated at build time and on publish; `Sitemap:` line in robots.txt; submit-in-Search-Console guidance in the dashboard.

## 8. Change notification
- On publish/update/unpublish/delete the job runner collects changed URLs and sends an **IndexNow** batch (Bing, Yandex and participating engines), debounced (e.g. every 5 minutes), with a key file served from the site root. Log in `index_notifications`.
- Google: rely on sitemap + fast, accurate `lastmod`; show a "Request indexing in Search Console" helper link on publish (manual).
- Failures never block publishing.

## 9. Structured data (JSON-LD) mapping
| Content | Type(s) | Required properties checked by lint |
|---|---|---|
| Site | `WebSite` (+ `SearchAction` only if site search exists), `Organization` | name, url, logo, sameAs |
| Page | `WebPage`, `BreadcrumbList` | name, url |
| Blog post | `BlogPosting` (or `Article`), `BreadcrumbList`, author `Person`/`Organization` | headline, datePublished, dateModified, image, author, publisher |
| FAQ block | `FAQPage` | question, acceptedAnswer. Rich-result eligibility for FAQ is limited by Google; still useful as machine-readable Q&A **(verify)** |
| How-to steps | `HowTo` | name, step |
| Author page | `Person` | name, url, sameAs, jobTitle |
| Category/tag archive | `CollectionPage`, `BreadcrumbList` | name, url |
| Campaign/event (custom) | `Event` or `Organization` + `DonateAction` per collection mapping | per mapping |
Rules: JSON-LD is generated from stored fields only; it must match visible content; one `@graph` per page with stable `@id` values so entities stay consistent site-wide; validated in CI and in the editor.

## 10. Markdown views, llms.txt, feeds, public read-only access
- `/{path}.md` and `Accept: text/markdown` return the page rendered from the same stored content as the HTML (same renderer, same headings, links and alt text) with front matter (title, description, canonical, updated). Always `Vary: Accept`. Cached.
- `llms.txt` (summary + links) and `llms-full.txt` (full content of key pages): generated automatically; low expected impact (see section 1), so cost must stay near zero.
- RSS/Atom and JSON Feed per collection; category feeds.
- Public read-only content API (cacheable, ETag) and optional public read-only MCP (`search_content`, `get_page`) behind a setting, rate limited, separate from the authenticated management MCP.

## 11. Site health lint (SEO, GEO/AEO, technical) - rule list
Each rule has an id, severity (error/warn/info), message, fix link, and is available in the dashboard, CLI and MCP tool `get_site_health`.

Content/SEO: `seo.title.missing`, `seo.title.too_long`, `seo.title.duplicate`, `seo.description.missing`, `seo.description.too_long`, `seo.description.duplicate`, `seo.h1.missing`, `seo.h1.multiple`, `seo.headings.skipped_level`, `seo.canonical.invalid`, `seo.noindex.unexpected`, `seo.slug.too_long`, `seo.image.alt.missing`, `seo.image.dimensions.missing`, `seo.link.broken_internal`, `seo.link.redirect_chain`, `seo.page.orphan`, `seo.page.thin_content`, `seo.og.image.missing`.
GEO/AEO: `geo.summary.missing` (answer summary absent), `geo.answer_first.weak` (first paragraph doesn't answer title question - heuristic, info only), `geo.author.missing`, `geo.date.missing_updated`, `geo.faq.empty_answers`, `geo.citations.missing` (claims/statistics without source - heuristic), `geo.entity.inconsistent` (organization/author names vary), `geo.schema.missing`, `geo.schema.invalid`, `geo.schema.mismatch` (JSON-LD differs from visible content).
Technical: `tech.sitemap.invalid`, `tech.sitemap.lastmod_suspicious` (all URLs same time), `tech.robots.blocks_search`, `tech.robots.unreachable`, `tech.robots.live_differs`, `tech.environment.staging_in_production`, `tech.noindex.header`, `tech.markdown.mismatch`, `tech.indexnow.failing`, `tech.redirect.loop`, `tech.https.mixed_content`, `tech.cwv.hints` (large images, missing lazy loading).
Heuristic (`info`) rules never block publishing; `error` rules block "publish" only for new `noindex`-to-indexable mistakes (configurable).

## 12. Production rollout checklist (for each live site)
1. Set environment to `production` only on the live host; staging stays `staging` with HTTP auth.
2. Choose crawler preset (default Balanced); review the bot table; confirm unverified bots.
3. Publish robots.txt; run the canary check; confirm in Search Console and Bing Webmaster.
4. Submit sitemap index; add IndexNow key; check verification files.
5. Run site health; fix all errors.
6. Validate JSON-LD on 5 representative URLs.
7. Record the policy version and date; schedule a quarterly review of the bot catalogue.
8. Monitor: crawl stats in Search Console, bot report (if logs available), AI citation checks (optional).
