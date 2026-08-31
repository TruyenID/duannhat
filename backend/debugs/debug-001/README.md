---
debug: 001
title: Category click opens edit drawer instead of detail view
slug: category-detail-missing-children
status: closed
severity: medium
source: user-report
branch: feature/plan-002-hq-category-screen
pr: https://github.com/godx-jp/godx-tempo/pull/2
created: 2026-04-07
updated: 2026-04-08
---

# Debug 001 — Category click opens edit drawer instead of detail view

> Clicking a category name on the HQ Categories list opens the edit drawer (Sheet). The drawer has no list of child categories — users expect a "category detail" view that shows the children of the category they just clicked. The drawer pattern is the wrong fit; user wants a real detail page and a Modal (Dialog) for create/edit.

## Status

- **Current:** `investigating`
- **Severity:** medium (UX, not a crash)
- **Source:** user report (post-merge UAT of plan-002)
- **Created:** 2026-04-07
- **Owner:** _(assign)_

## Quick links

- [SYMPTOMS.md](SYMPTOMS.md) — what's broken, repro steps
- [INVESTIGATION.md](INVESTIGATION.md) — root cause + fix scope
- [FIX.md](FIX.md) — what changed, regression test, verification
- [NOTES.md](NOTES.md) — working log

## Related

- Plan: plan-002 (HQ Category Screen) — PR #2 still open, fix lands as additional commits on the same branch
- Reporter: user (in-session)
