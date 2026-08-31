# Debug 002 — Symptoms

> Symptoms for [Add MenuSection](README.md).

## Source

| Field | Value |
|-------|-------|
| Source | local design discussion |
| Reporter | user |
| First seen | 2026-04-13 |
| Severity | medium |
| Affected users | all — no section grouping in menus |
| Related plan | none |

## Expected behavior

Menu should support grouping products into named sections (e.g., Appetizers, Main, Drinks, Desserts). Sections should be reusable across multiple menus with different display orders per menu.

## Actual behavior

`menu_products` links directly to `menus` with only a flat `display_order`. No concept of sections or category grouping within a menu.

## Design Decision

After discussion, the chosen design:
- `menu_sections` table belongs to brand (reusable)
- N:N relationship between `menus` and `menu_sections` via `menu_menu_sections` pivot (with `display_order`)
- `menu_products.menu_section_id` (nullable FK) for optional grouping
- Backward compatible: existing menu products with NULL section still work
