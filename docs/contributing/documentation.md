---
title: Documentation Standards
category: contributing
tags: [documentation, writing-standards, diataxis, template, formatting, review-checklist]
summary: Defines mandatory rules for writing and maintaining documentation including the Diataxis framework categories, language standards, templates, and formatting conventions.
related: []
---

# Documentation Standards

> This document defines **mandatory rules** for writing and maintaining documentation in this project. All contributors (human and AI) must follow these standards.

## Language

- **All documentation MUST be written in English.**
- Use clear, concise technical English. Prefer short sentences over long paragraphs.
- Use active voice: "The service validates input" not "Input is validated by the service."
- Use present tense: "The system creates a record" not "The system will create a record."
- Avoid jargon without definition. Define domain terms on first use.

### What "written in English" does and does not mean

The rule governs the **prose**. Four things are quoted verbatim rather than
translated, because translating them would make the document wrong:

| Kept verbatim | Why | Example |
|---|---|---|
| On-screen labels, buttons and error messages | The reader has to find the string on a real screen | `変更を保存 / Save changes`, `Gửi bếp` |
| Domain terms with no English equivalent | They are proper nouns of the business | 軽減税率, インボイス, 精算, 赤伝, 釣銭機 |
| Identifiers from the code | They are what you grep for | `TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT`, `till_sessions` |
| Sample data inside a code block | It illustrates real multilingual input | `{ "vi": "Sốt trắng" }` |

Where a label is quoted, give the English alongside it the first time
(`精算 / Final close`) so a reader who does not read Japanese can still follow.

**File names are not covered by this rule.** Several files keep the names they were
created with (`van-hanh/`, `thue-tieu-thu-van-hanh.md`); renaming them would break
links from `CLAUDE.md`, `backend/docs/` and the per-app doc sets for no reader benefit.
New files should use descriptive English names.

This is the state after #1325, which translated the 34 files that were still written
in Vietnamese or Japanese prose. The rule and the tree now agree.

## Framework: Diataxis

This project follows the [Diataxis documentation framework](https://diataxis.fr/). Every document belongs to exactly one of four categories:

| Category | Folder | Purpose | Oriented to |
|----------|--------|---------|-------------|
| **Guide** | `docs/guide/` | Step-by-step instructions to achieve a goal | Tasks (how-to) |
| **Explanation** | `docs/explanation/` | Background knowledge, concepts, reasoning | Understanding (why) |
| **Reference** | `docs/reference/` | Precise, complete technical descriptions | Information (what) |
| **Contributing** | `docs/contributing/` | Rules and standards for contributors | Process (how we work) |

### When to use which category

| You want to... | Category | Example |
|----------------|----------|---------|
| Help someone set up the project | Guide | `getting-started.md` |
| Explain what a domain concept is and why it exists | Explanation | `inventory-domain.md` |
| List every API endpoint with request/response format | Reference | `api-product.md` |
| Define how controllers must be structured | Contributing | `controller.md` |

### Key rules

- **Never mix categories.** A reference doc must not contain tutorials. An explanation must not contain API specs.
- **Cross-reference instead of duplicating.** Use relative links: `See [Stock Management](../explanation/stock-management.md)`.
- **Explanation docs answer "why."** Reference docs answer "what." Guides answer "how." Contributing docs answer "how we work."

---

## File Structure

```
docs/
├── README.md              # Navigation index (required)
├── guide/                 # Step-by-step guides
├── explanation/           # Domain concepts & business logic
├── reference/             # API specs, architecture, config
└── contributing/          # Development rules & standards
```

## File Naming

| Rule | Example |
|------|---------|
| Lowercase kebab-case | `stock-management.md`, `api-product.md` |
| Prefix with category context if needed | `api-overview.md`, `api-product.md` |
| No numbering in filenames | `getting-started.md` not `01-getting-started.md` |
| Use `.md` extension only | Not `.mdx`, `.txt`, `.rst` |

---

## Document Structure

Every document MUST follow this structure:

### 1. Frontmatter

YAML frontmatter at the very top:

```yaml
---
title: Document Title
category: explanation        # one of: guide, explanation, reference, contributing
tags: [tag1, tag2, tag3]
summary: One-sentence summary used by AI agents and search to decide relevance.
related: [other-doc-slug]
verified_at: 2026-07-30                                 # optional, see below
source_of_truth: backend/routes/api/shops/orders.php    # optional, see below
---
```

#### `verified_at` + `source_of_truth` — the drift markers

Docs rot silently. A reader has no way to tell a doc that still matches the code
from one that was last true three refactors ago, so the two keys below record it:

| Key | Meaning |
|-----|---------|
| `verified_at` | The date someone last read this doc **against the code** and confirmed it. Not the date it was last edited. |
| `source_of_truth` | The single file that decides the doc's central claim — the route file, the enum, the migration. Where a reader goes to check. |

Rules:

- **Only stamp `verified_at` when you actually did the check.** A blanket
  refresh across files you did not read manufactures exactly the false
  confidence these keys exist to remove.
- Refresh `verified_at` whenever you re-derive the doc from its
  `source_of_truth`, even if nothing changed — a confirmed-unchanged doc is a
  result worth recording.
- **A doc with no `verified_at` is unverified, not stale.** Treat both the same
  way: check it against `source_of_truth` before relying on it.
- Both keys are optional. A doc with no single code owner (a decision record, a
  runbook, a QA plan) may legitimately omit `source_of_truth`.

### 2. Title (H1)

- One `#` heading per document, immediately after the frontmatter.
- Title must be descriptive and unique across all docs.
- No emoji in titles.

```markdown
# Product Domain
```

### 3. Lead Paragraph

Immediately after the title, one paragraph (1-3 sentences) explaining what this document covers and who should read it.

### 4. Table of Contents (optional)

For documents longer than 3 sections, include a TOC using markdown links. Not required for short documents.

### 5. Sections (H2, H3)

- Use `##` for major sections, `###` for subsections.
- Never skip heading levels (no `#` → `###`).
- Keep section headings concise and scannable.
- Maximum nesting depth: H4 (`####`). If you need deeper nesting, split the document.

### 6. Content Rules

| Rule | Correct | Wrong |
|------|---------|-------|
| One concept per section | Dedicated section for each entity | Mixing two concepts in one section |
| Tables for structured data | Endpoint tables, field tables, enum tables | Inline lists of fields |
| Code blocks for examples | ` ```json ` with language tag | Inline code for multi-line examples |
| Relative links for cross-references | `[API Overview](../reference/api-overview.md)` | Absolute paths or bare filenames |

---

## Formatting Standards

### Tables

Use tables for:
- Endpoint listings (Method, Path, Description)
- Field definitions (Field, Type, Description)
- Enum values (Value, Description)
- Permission matrices (Action, Role1, Role2, ...)

### Code Blocks

- Always specify language: ` ```php `, ` ```json `, ` ```bash `, ` ```yaml `
- Use `json` for request/response examples
- Use `php` for code patterns and templates
- Use `bash` for CLI commands
- Keep code examples minimal — show the pattern, not the full implementation

### Diagrams

Use ASCII art for flow diagrams and state machines.

### Admonitions

Use blockquotes with bold prefix for important notes:

```markdown
> **Warning:** Never modify auto-generated files in `OmnifyBase/`.

> **Note:** Stock level quantity cannot be edited directly.
```

### Section Separators

Use `---` (horizontal rule) between major sections within a document.

---

## Category templates

Start from the template for the category instead of an empty file:

| Category | Template |
|----------|----------|
| Guide | [`templates/guide.md.tpl`](templates/guide.md.tpl) |
| Explanation | [`templates/explanation.md.tpl`](templates/explanation.md.tpl) |
| Reference | [`templates/reference.md.tpl`](templates/reference.md.tpl) |
| Contributing | [`templates/contributing.md.tpl`](templates/contributing.md.tpl) |

---

## Scope — which doc set does this govern

This file is the standard for **every** doc set in the monorepo: `docs/`,
`backend/docs/`, and each app's own `docs/` (`workstation/docs/`,
`app/{tms,kiosk,kds,handy}/docs/`, `web/{pos,customer}/docs/`).
`backend/docs/contributing/documentation.md` used to carry a second, diverging
copy — it now points here (#1322).

---

## README.md Requirements

`docs/README.md` serves as the navigation index. It MUST:

1. List every document grouped by category
2. Include a 1-line description for each document
3. Use relative links
4. Stay updated when documents are added/removed/renamed

---

## Review Checklist

Before merging any documentation change:

- [ ] Written in English
- [ ] Belongs to exactly one Diataxis category
- [ ] Has YAML frontmatter with `title`, `category`, `tags`, `summary`
- [ ] If the doc's facts were re-checked against code: `verified_at` refreshed and `source_of_truth` set
- [ ] Follows the correct category template
- [ ] Has H1 title + lead paragraph
- [ ] No mixed content (explanation in reference, etc.)
- [ ] Tables used for structured data (not inline lists)
- [ ] Code blocks have language tags
- [ ] Cross-references use relative links
- [ ] Listed in `docs/README.md`
- [ ] No duplicate content — cross-reference instead
- [ ] File name is lowercase kebab-case
- [ ] No emoji in headings or content
