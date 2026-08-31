/**
 * #2043 — every print-template block and kind must carry a NAME.
 *
 * `i18n-parity.test.ts` proves the three dictionaries hold the same keys. It
 * cannot prove they hold the RIGHT keys: when #2040 added seven 精算 blocks to
 * `PRINT_BLOCK_MUTABILITY`, all three files were equally missing all seven, so
 * parity stayed green and the block editor rendered
 * `print_templates.block.sales_summary` as the block's own name. Nothing in
 * tsc, eslint or the parity test could see it — `t()` falls back to the raw
 * key, which is a string, which type-checks.
 *
 * This file closes the loop the other way: the id lists are the source, the
 * dictionaries must cover them, in every locale. It is a bijection on purpose —
 * a label with no id is a block that was renamed and left a dead string behind.
 *
 * #2043 phase B moved the BEHAVIOURAL catalog to the server (`data.catalog` on
 * both the HQ and the shop read), so `PRINT_BLOCK_MUTABILITY` /
 * `PRINT_BLOCK_EDITABLE_PROPS` no longer exist to anchor this. `PRINT_BLOCK_IDS`
 * replaces them, and the swap is a downgrade in blast radius on purpose: that
 * list decides only which names this app can SPEAK. Being wrong in it shows a
 * raw `print_templates.block.x` on screen; being wrong in the old mirrors took a
 * control away and said nothing.
 *
 * @vitest-environment node
 */

import { describe, expect, it } from "vitest";
import ja from "@/i18n/ja.json";
import en from "@/i18n/en.json";
import vi from "@/i18n/vi.json";
import { PRINT_BLOCK_IDS, PRINT_TEMPLATE_KINDS } from "@/types/models/PrintTemplate";

const DICTS = { ja, en, vi } as Record<string, Record<string, string>>;
const LOCALES = ["ja", "en", "vi"] as const;

const BLOCK_PREFIX = "print_templates.block.";
const KIND_PREFIX = "print_templates.kind.";

/** Block ids the editor can draw a header for. */
const BLOCK_IDS = PRINT_BLOCK_IDS;

function idsLabelledIn(locale: string, prefix: string): string[] {
  return Object.keys(DICTS[locale])
    .filter((key) => key.startsWith(prefix))
    .map((key) => key.slice(prefix.length));
}

describe("print-template block labels", () => {
  it.each(LOCALES)("%s.json names every block in PRINT_BLOCK_IDS", (locale) => {
    const missing = BLOCK_IDS.filter((id) => !(BLOCK_PREFIX + id in DICTS[locale]));
    expect(missing).toEqual([]);
  });

  it.each(LOCALES)("%s.json has no block label without a block id", (locale) => {
    const orphans = idsLabelledIn(locale, BLOCK_PREFIX).filter((id) => !BLOCK_IDS.includes(id));
    expect(orphans).toEqual([]);
  });

  it("has no duplicate block id — a duplicate hides a missing one from the count", () => {
    expect(new Set(BLOCK_IDS).size).toBe(BLOCK_IDS.length);
  });
});

describe("print-template kind labels", () => {
  it.each(LOCALES)("%s.json names every kind in PRINT_TEMPLATE_KINDS", (locale) => {
    const missing = PRINT_TEMPLATE_KINDS.filter((kind) => !(KIND_PREFIX + kind in DICTS[locale]));
    expect(missing).toEqual([]);
  });

  it.each(LOCALES)("%s.json has no kind label without a catalog entry", (locale) => {
    const known: readonly string[] = PRINT_TEMPLATE_KINDS;
    const orphans = idsLabelledIn(locale, KIND_PREFIX).filter((kind) => !known.includes(kind));
    expect(orphans).toEqual([]);
  });
});
