---
title: Service Layer Patterns
category: contributing
tags: [service, transactions, locking, n-plus-one, eloquent, database, concurrency, moved]
summary: Pointer — service-layer rules are maintained at backend/docs/contributing/service.md, the doc set backend/CLAUDE.md wires in.
related:
  - ../../backend/docs/contributing/service.md
---

# Service Layer Patterns

> **Moved.** The canonical copy is **[`backend/docs/contributing/service.md`](../../backend/docs/contributing/service.md)**.

Service-layer rules are Laravel rules, and `backend/docs/` is the doc set that is
wired in directly: `backend/CLAUDE.md` lists it as required reading, and
`backend/.claude/docs-manifest.md` loads it automatically. Keeping a second copy
here in the umbrella only produces two rule sets that drift apart — which is
exactly what happened (#1322).

Three rules that used to exist only in the umbrella copy are now merged into the
canonical one as rules 9-11 plus review-checklist entries:

| Rule | What it prevents |
|---|---|
| Read-validate-write must lock **every row it checked**, not only the rows it will write | Two requests both clear validation, then both write |
| If a Resource uses `whenLoaded()`, the service **must** eager-load that relation | A missing `with()` causes not an N+1 but **silent data loss** — the field serializes as empty |
| Never assume a `*_by_id` column is non-NULL | Legacy rows and device-token-created rows are both NULL |
