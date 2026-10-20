# BS CMS Content Style & Editorial Guidelines

This guide establishes the editorial standards, voice, and formatting practices for all content published through BS CMS, whether created by human writers or autonomous AI agents.

---

## 1. Voice and Tone

- **Clarity over Cleverness**: Communicate technical and conceptual ideas simply and directly. Avoid purple prose, corporate buzzwords, and vague generalizations.
- **Active Voice**: Write in active voice whenever possible ("The dispatcher routes the command" instead of "The command is routed by the dispatcher").
- **Eliminate AI Clichés**: Do NOT use generic filler phrases such as:
  - *"In today's fast-paced digital landscape..."*
  - *"Delve into..."* / *"A testament to..."* / *"Rich tapestry..."*
  - *"Game changer"* / *"Revolutionizing the way..."*
- **Authoritative & Practical**: Provide concrete facts, specific configurations, actionable code snippets, and verified source citations.

---

## 2. Information Architecture (Inverted Pyramid)

Readers and AI search crawlers both benefit from high-density, structured content:

1. **Answer Summary (First 100 Words)**: State the primary premise, answer, or conclusion immediately beneath the introduction or in an opening callout.
2. **Core Concepts (H2 Headings)**: Break the subject into logical, self-contained sections.
3. **Elaboration & Implementation (H3/H4)**: Provide detailed steps, code examples, or configurations.
4. **Structured Citations & FAQs**: Conclude with a `:::facts` block for key metrics and a `:::faq` block answering common edge cases.

---

## 3. Formatting & Scannability

- **Headings**: Entry titles are `H1`. Use `##` (H2) for main sections and `###` (H3) for subsections. Never skip heading levels.
- **Paragraphs**: Keep paragraphs between 2 and 4 sentences. Break long walls of text with subheadings, lists, or callouts.
- **Lists & Tables**: Use bulleted lists for options and numbered lists for sequential procedures. Present complex multi-dimensional data in markdown tables.
- **Callouts**: Use `:::callout info`, `tip`, or `warning` sparingly to draw attention to critical requirements or caveats.

---

## 4. Evidence, Citations & Verifiability

- **Rule on Statistics**: Never invent, guess, or extrapolate statistics or benchmark numbers.
- **Citing Sources**: Any numerical claim, performance benchmark, or regulatory citation must include a link to the primary source.
- **Key Facts Block**: For major articles and whitepapers, compile core claims into a `:::facts` directive with verifiable source URLs.

---

## 5. Media & Accessibility Rules

- **Mandatory Alt Text**: Every image must feature an informative, concise `alt` description explaining the visual subject matter.
  - *Bad*: `![diagram](diagram.png)`
  - *Good*: `![Sequence diagram illustrating OAuth 2.1 PKCE authorization flow between client, server, and token endpoint](diagram.png)`
- **Do not say "Image of"**: Screen readers already announce the image element. Describe the content directly.
- **Aspect Ratios**: Prefer standard web image aspect ratios (16:9, 4:3) with optimized WebP or SVG assets.

---

## 6. Hyperlinks and Internal Routing

- **Descriptive Anchor Text**: Always describe the destination within the link text.
  - *Bad*: "For more information [click here](/blog/features)."
  - *Good*: "Review the full [BS CMS features breakdown](/blog/features) for technical specifications."
- **Relative Slugs**: Always reference internal collection entries using root-relative paths (e.g., `/blog/my-slug`, `/docs/installation`).
