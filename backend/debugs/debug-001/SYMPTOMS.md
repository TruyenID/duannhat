# Debug 001 — Symptoms

> Symptoms for [Category click opens edit drawer instead of detail view](README.md).

## Source

| Field | Value |
|-------|-------|
| Source | user report (in-session) |
| Reporter | user |
| First seen | 2026-04-07, immediately after plan-002 PR opened |
| Severity | medium — not a crash, UX mismatch |
| Affected users | every HQ user who clicks a category to drill in |
| Related plan | plan-002 (HQ Category Screen) |

## Expected behavior

When the user clicks the name of a category in the HQ list, they expect to see a "category detail" view that shows the children of the category (drill-down). Editing should be a separate explicit action.

## Actual behavior

Clicking the name opens a right-side **Sheet drawer** prefilled with the edit form. The drawer has no children list. There is no other way to "drill into" a category — only the chevron expands the inline tree row, which the user does not perceive as the same action as "click name to open detail".

## Steps to reproduce

1. Log in as an HQ user with at least one category that has children.
2. Navigate to `/hq/{brandSlug}/categories`.
3. Click the name of a category that has `children_count > 0`.

**Expected at step 3:** a detail view appears showing the category metadata + a list of its children, with an explicit Edit action.
**Actual at step 3:** the edit Sheet drawer slides in from the right, prefilled. No children are shown anywhere in the drawer.

## Error details

No JS error, no server error. Pure design mismatch.

```
none
```

## Environment

| Field | Value |
|-------|-------|
| Environment | local (post-merge UAT of plan-002) |
| Browser | not specified |
| User role | HQ user |
| Recent changes | plan-002 just shipped (PR #2) |

## When did this start?

Immediately after plan-002 was implemented. The plan's DESIGN.md explicitly chose Sheet drawer for create/edit (Decision 2), but never specified what "click row name" should do. The implementation wired click-name → openEdit because there was no detail page in scope.

## What's NOT happening

- The chevron expand/collapse DOES work — users can see children inline if they find the chevron.
- The edit drawer DOES work — editing is functional.
- The bug is purely the missing "click name → detail" affordance and the resulting drawer-as-detail confusion.

## User's prescription

> "không nên dùng drawer" — get rid of the drawer. Use a Modal (Dialog) for New and Edit, and a separate detail page for drilling into a category.
