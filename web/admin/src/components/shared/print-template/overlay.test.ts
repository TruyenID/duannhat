/**
 * Shop-overlay construction — plan-053 M4 (#1171), TR-02 / TR-03 / TR-04.
 *
 * These are the rules that decide what a shop actually SAVES. Getting them
 * wrong is not a cosmetic bug: sending the whole document would either be
 * rejected field by field (`SHOP_FIELD_NOT_EDITABLE`) or, if the brand ever
 * widened the allow-list, freeze the shop on the layout of the day it first
 * customised the slip.
 */
import { describe, expect, it } from "vitest";
import { applyOverlay, buildOverlay } from "./overlay";
import type { PrintTemplateCatalog, PrintTemplateDefinition } from "@/types/models/PrintTemplate";

/**
 * A verbatim slice of what `GET /shops/{slug}/print-templates/{kind}` returns
 * (#2043) — the backend assembles it in `BlockCatalog::catalogFor()`, and
 * `tests/Feature/Print/ShopCatalogReadTest.php` pins these exact values against
 * `config/print_blocks.php`. Fixing this fixture by hand to make a test pass
 * would therefore be fixing the wrong end.
 */
function catalog(): PrintTemplateCatalog {
  return {
    blocks: ["logo", "title", "items", "grand_total", "footer_text", "qr_block"],
    required: ["items", "grand_total"],
    sources: ["brand_logo", "branch_logo", "order_url"],
    param_fields: ["store_name", "order_no"],
    mutability: {
      logo: "free",
      title: "free",
      items: "locked",
      grand_total: "locked",
      footer_text: "free",
      qr_block: "free",
    },
    editable_props: {
      logo: ["enabled", "source", "align", "max_width_dots"],
      title: ["enabled", "align", "i18n", "i18n_narrow", "fallback", "bold"],
      items: ["columns"],
      grand_total: [],
      footer_text: ["enabled", "align", "i18n", "fallback", "bold"],
      qr_block: ["enabled", "source", "align"],
    },
    prop_enums: { items: { columns: ["name", "qty", "unit_price", "amount", "tax_mark"] } },
  };
}

function definition(): PrintTemplateDefinition {
  return {
    schema: "tempo.print.v1",
    paper: { columns_58mm: 32, columns_80mm: 48 },
    blocks: [
      { id: "logo", type: "image", enabled: true, source: "brand_logo", align: "center" },
      { id: "title", type: "text", enabled: true, align: "right", i18n: { ja: "支払済" } },
      { id: "items", type: "line_items", columns: ["name", "qty", "amount"] },
      { id: "grand_total", type: "locked" },
      {
        id: "footer_text",
        type: "text",
        enabled: true,
        align: "center",
        fallback: true,
        i18n: { ja: "ありがとうございました", en: "Thanks", vi: "Cam on" },
      },
      { id: "qr_block", type: "qr", enabled: false, source: "order_url", align: "center" },
    ],
  };
}

describe("buildOverlay", () => {
  it("keeps only the blocks the brand delegated", () => {
    const overlay = buildOverlay(definition(), catalog(), ["footer_text", "logo"]);

    expect(overlay.blocks.map((block) => block.id)).toEqual(["logo", "footer_text"]);
    expect(overlay.schema).toBe("tempo.print.v1");
  });

  it("keeps only the named prop when the path is blockId.prop", () => {
    const overlay = buildOverlay(definition(), catalog(), ["qr_block.enabled"]);

    expect(overlay.blocks).toEqual([{ id: "qr_block", enabled: false }]);
  });

  it("never emits a prop outside the catalog's editable list", () => {
    // `items.columns` is the ONLY editable prop of a locked line-items block.
    const overlay = buildOverlay(definition(), catalog(), ["items"]);

    expect(overlay.blocks).toEqual([{ id: "items", columns: ["name", "qty", "amount"] }]);
  });

  it("emits nothing for an engine-owned block, even if the path names it", () => {
    // A brand cannot delegate a locked block; if a stale allow-list still
    // does, the overlay must not carry it into the request.
    expect(buildOverlay(definition(), catalog(), ["grand_total"]).blocks).toEqual([]);
  });

  it("drops paths for blocks this slip does not have", () => {
    expect(buildOverlay(definition(), catalog(), ["debt_signature"]).blocks).toEqual([]);
  });

  it("produces an empty overlay when the brand delegates nothing (locked slip)", () => {
    expect(buildOverlay(definition(), catalog(), []).blocks).toEqual([]);
  });

  it("carries the authored i18n map of a delegated text block", () => {
    const overlay = buildOverlay(definition(), catalog(), ["footer_text"]);
    const footer = overlay.blocks.find((block) => block.id === "footer_text");

    expect(footer?.i18n).toEqual({ ja: "ありがとうございました", en: "Thanks", vi: "Cam on" });
    expect(footer?.fallback).toBe(true);
  });
});

describe("a block the catalog does not describe", () => {
  it("contributes nothing to the overlay — fail closed, never fail open", () => {
    // #2043 replaced `catalogFromDefinition()` (which fell back to "locked" for
    // an unknown block) with the server's catalog. The property it protected
    // has to survive the swap: a definition can outlive a catalog entry — a
    // stored draft written before a block was retired — and when it does, the
    // overlay must carry none of its props rather than all of them.
    const stale: PrintTemplateDefinition = {
      schema: "tempo.print.v1",
      blocks: [{ id: "not_a_real_block", enabled: true, align: "center" }],
    };

    expect(buildOverlay(stale, catalog(), ["not_a_real_block"]).blocks).toEqual([]);
  });
});

describe("applyOverlay", () => {
  it("patches the resolved slip block by block, keeping base order", () => {
    const merged = applyOverlay(definition(), {
      schema: "tempo.print.v1",
      blocks: [{ id: "footer_text", i18n: { ja: "ハノイ店より" } }],
    });

    expect(merged.blocks.map((block) => block.id)).toEqual([
      "logo",
      "title",
      "items",
      "grand_total",
      "footer_text",
      "qr_block",
    ]);
    expect(merged.blocks.find((block) => block.id === "footer_text")?.i18n).toEqual({
      ja: "ハノイ店より",
    });
    // Untouched props survive the patch.
    expect(merged.blocks.find((block) => block.id === "footer_text")?.align).toBe("center");
  });

  it("returns the base untouched when there is no draft", () => {
    const base = definition();
    expect(applyOverlay(base, null)).toBe(base);
  });
});
