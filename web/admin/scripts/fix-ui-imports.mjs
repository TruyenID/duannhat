#!/usr/bin/env node
/**
 * One-shot script to replace `@/components/ui/*` imports with `@godxjp/ui`.
 *
 * Background: this project dropped the legacy `src/components/ui/` folder and
 * migrated to the external `@godxjp/ui` design system package. A bunch of
 * customer/order files still import from the old path and were silently
 * broken until `npm run build` was run again. This script consolidates each
 * file's legacy imports into a single `@godxjp/ui` import.
 *
 * Usage: node scripts/fix-ui-imports.mjs
 */

import { readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

const files = [
  "src/app/shop/[shopSlug]/orders/[id]/page.tsx",
  "src/app/shop/[shopSlug]/orders/components/payment-method-dialog.tsx",
  "src/app/shop/[shopSlug]/orders/new/components/cart-summary.tsx",
  "src/app/shop/[shopSlug]/orders/new/components/customer-search-input.tsx",
  "src/app/shop/[shopSlug]/orders/new/components/order-item-row.tsx",
  "src/app/shop/[shopSlug]/orders/new/page.tsx",
  "src/app/shop/[shopSlug]/orders/page.tsx",
  "src/components/shared/customer-order-history.tsx",
  "src/app/shop/[shopSlug]/customers/page.tsx",
  "src/app/shop/[shopSlug]/customers/[id]/edit/page.tsx",
  "src/app/shop/[shopSlug]/customers/[id]/page.tsx",
  "src/app/shop/[shopSlug]/customers/components/customer-form.tsx",
  "src/app/shop/[shopSlug]/customers/components/customer-list-table.tsx",
  "src/app/hq/[brandSlug]/orders/components/branch-filter-select.tsx",
  "src/app/hq/[brandSlug]/orders/components/revenue-stat-card.tsx",
  "src/app/hq/[brandSlug]/orders/page.tsx",
  "src/app/hq/[brandSlug]/orders/[id]/page.tsx",
  "src/app/hq/[brandSlug]/customers/[id]/page.tsx",
  "src/app/hq/[brandSlug]/customers/page.tsx",
];

// Matches `import { A, B, type C } from "@/components/ui/<something>";`
// across both quote styles. Captures the named specifier list.
const IMPORT_RE =
  /import\s*(?:type\s+)?\{\s*([^}]+)\s*\}\s*from\s*["']@\/components\/ui\/[^"']+["'];?/g;

function splitSpecifiers(listText) {
  return listText
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean);
}

let totalFilesTouched = 0;

for (const rel of files) {
  const abs = path.resolve(rel);
  const src = readFileSync(abs, "utf8");

  const specs = new Set();
  let match;
  IMPORT_RE.lastIndex = 0;
  while ((match = IMPORT_RE.exec(src)) !== null) {
    for (const s of splitSpecifiers(match[1])) specs.add(s);
  }

  if (specs.size === 0) {
    continue;
  }

  // Strip every legacy import in-place, collapse blank lines left behind.
  let next = src.replace(IMPORT_RE, "").replace(/^\s*\n(?=\s*\n)/gm, "");

  // Insert the consolidated import after the last remaining import line,
  // so it lives with the rest of the imports instead of the top of file.
  const lines = next.split(/\r?\n/);
  let insertAt = 0;
  for (let i = 0; i < lines.length; i++) {
    if (/^\s*import\s/.test(lines[i])) insertAt = i + 1;
  }

  const merged = `import { ${[...specs].join(", ")} } from "@godxjp/ui";`;
  lines.splice(insertAt, 0, merged);
  next = lines.join("\n");

  if (next !== src) {
    writeFileSync(abs, next);
    totalFilesTouched += 1;
    console.log(`✔ ${rel}  (${specs.size} specifiers merged)`);
  }
}

console.log(`\nDone. ${totalFilesTouched} file(s) updated.`);
