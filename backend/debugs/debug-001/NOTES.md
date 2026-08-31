# Debug 001 — Notes

> Working log for [Category click opens edit drawer instead of detail view](README.md). Append-only. Newest entries on top.

---

## 2026-04-08 — Pushed to PR #2

- 4 commits added to `feature/plan-002-hq-category-screen` (which still hosts the open PR #2):
  - `d63b6b9` — debug-001 fix (drawer → detail page + Modal)
  - `de59675` — chore: replace `dxs-product.test` with `localhost:5400`
  - `7b7a1db` — chore(cursor): wire omnify MCP into `.cursor/mcp.json` *(unrelated, user-added)*
  - `fc682b5` — chore: bump `@omnifyjp/omnify` to `^3.12.2` *(unrelated, user-added)*
- Status stays `resolved` until PR #2 merges, then it can move to `closed`.

---

## 2026-04-07 — Resolved

- **Root cause:** `page.tsx:147` wired row name click to `openEdit` drawer; no detail route existed.
- **Files changed:** 5 (3 modified, 2 created, 1 deleted, 1 regression test added)
- **Regression test:** `frontend/src/__tests__/category-tree-row.test.tsx` (3 vitest cases — main one asserts `href` is the real detail URL, not `#`)
- **Tests:** vitest 3/3, backend Pest contract suite still 21/21
- **Time spent:** ~30 min (small surface, clear root cause, no live repro needed)

---

## 2026-04-07 — Debug session opened

User report immediately after plan-002 PR #2 was opened: clicking a category name on the HQ list does not show child categories — opens the edit drawer instead. User's verdict: drop the drawer entirely, use a Modal (Dialog) for New and Edit, add a real detail page for drilling in.

DB has 0 categories on this Herd instance, so the bug cannot be visually reproduced via the browser without seeding. The issue is plainly readable from the code (`page.tsx:147` wires row click to `openEdit`), so investigation skips the live repro step and goes straight to the structural fix.

The fix lands as additional commits on the existing `feature/plan-002-hq-category-screen` branch (PR #2 still open, not merged). This avoids a branch-dependency mess and keeps the entire HQ Categories feature reviewable in a single PR.
