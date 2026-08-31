import { describe, expect, it } from "vitest";
import { slugifyAscii, slugifyHyphen, toOptionSlug, toResourceSlug } from "./option-slug";

const BACKEND_RULE = /^[a-z0-9_]+$/;

/**
 * Verbatim from `HQ/StoreShopRequest.php:25` / `HQ/UpdateShopRequest.php:28` —
 * the strictest rule any resource slug has to clear. Note it forbids `_`, which
 * is why `toResourceSlug` cannot reuse `toOptionSlug`'s underscore fallback.
 * Products (`max:191` + unique) and categories (`max:100`) add no format rule.
 */
const SHOP_SLUG_RULE = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

describe("slugifyAscii", () => {
  it("keeps readable slugs for ASCII labels", () => {
    expect(slugifyAscii("Size")).toBe("size");
    expect(slugifyAscii("  Extra  Large ")).toBe("extra_large");
  });

  it("folds Vietnamese diacritics to ASCII", () => {
    expect(slugifyAscii("Cỡ")).toBe("co");
    expect(slugifyAscii("Màu sắc")).toBe("mau_sac");
  });

  it("returns empty for fully Japanese labels (the original bug)", () => {
    expect(slugifyAscii("量")).toBe("");
    expect(slugifyAscii("サイズ")).toBe("");
  });
});

describe("toOptionSlug", () => {
  it("prefers the readable ASCII slug when there is one", () => {
    expect(toOptionSlug("Size")).toBe("size");
    expect(toOptionSlug("Cỡ")).toBe("co");
  });

  it("never returns empty for Japanese labels", () => {
    for (const label of ["量", "サイズ", "色", "本数", "２本"]) {
      const slug = toOptionSlug(label);
      expect(slug).not.toBe("");
      expect(slug).toMatch(BACKEND_RULE);
    }
  });

  it("keeps distinct Japanese labels distinct", () => {
    const slugs = ["量", "サイズ", "色", "本数"].map((l) => toOptionSlug(l));
    expect(new Set(slugs).size).toBe(slugs.length);
  });

  it("is stable for the same label", () => {
    expect(toOptionSlug("量")).toBe(toOptionSlug("量"));
  });

  it("honours the prefix so keys and values do not collide", () => {
    expect(toOptionSlug("量", "option")).not.toBe(toOptionSlug("量", "value"));
    expect(toOptionSlug("量", "value")).toMatch(/^value_/);
  });

  it("returns empty only for genuinely blank input", () => {
    expect(toOptionSlug("")).toBe("");
    expect(toOptionSlug("   ")).toBe("");
    expect(toOptionSlug(null)).toBe("");
  });

  it("produces backend-valid slugs for mixed labels", () => {
    // No emoji/dingbats in the fixtures — src/__tests__/no-emoji-in-ui.test.ts
    // (#1189) bans them repo-wide, test files included. "※" covers the same
    // case (a symbol that strips to nothing) without tripping that guard.
    for (const label of ["量 A", "Size(大)", "2本", "※"]) {
      expect(toOptionSlug(label)).toMatch(BACKEND_RULE);
    }
  });
});

describe("slugifyHyphen", () => {
  it("keeps readable hyphen slugs for ASCII names", () => {
    expect(slugifyHyphen("Tokyo Store")).toBe("tokyo-store");
    expect(slugifyHyphen("  Grilled   Chicken  ")).toBe("grilled-chicken");
  });

  it("preserves hyphens already in the name and collapses runs", () => {
    expect(slugifyHyphen("Shinjuku - East")).toBe("shinjuku-east");
    expect(slugifyHyphen("--edge--")).toBe("edge");
  });

  it("folds Vietnamese diacritics instead of dropping the letters", () => {
    expect(slugifyHyphen("Cửa hàng Hà Nội")).toBe("cua-hang-ha-noi");
  });

  it("returns empty for fully Japanese names (the #2263 bug)", () => {
    expect(slugifyHyphen("東京店")).toBe("");
    expect(slugifyHyphen("唐揚げ")).toBe("");
    expect(slugifyHyphen("飲み物")).toBe("");
  });
});

describe("toResourceSlug", () => {
  it("prefers the readable slug when there is one", () => {
    expect(toResourceSlug("Tokyo Store", "shop")).toBe("tokyo-store");
    expect(toResourceSlug("Cửa hàng Hà Nội", "shop")).toBe("cua-hang-ha-noi");
  });

  it("never returns empty for Japanese product / shop / category names", () => {
    const names = ["東京店", "唐揚げ", "飲み物", "前菜", "サイズ", "２本"];
    for (const [name, prefix] of names.map(
      (n, i) => [n, ["product", "shop", "category"][i % 3]] as const
    )) {
      const slug = toResourceSlug(name, prefix);
      expect(slug).not.toBe("");
      expect(slug).toMatch(SHOP_SLUG_RULE);
    }
  });

  it("never emits an underscore — the shop regex rejects it", () => {
    for (const name of ["東京店", "唐揚げ", "飲み物", "※", "量 A"]) {
      expect(toResourceSlug(name, "shop")).not.toContain("_");
    }
  });

  it("keeps distinct Japanese names distinct", () => {
    const slugs = ["東京店", "唐揚げ", "飲み物", "前菜"].map((n) => toResourceSlug(n, "product"));
    expect(new Set(slugs).size).toBe(slugs.length);
  });

  it("is stable for the same name", () => {
    expect(toResourceSlug("東京店", "shop")).toBe(toResourceSlug("東京店", "shop"));
  });

  it("honours the prefix so the fallback says what it names", () => {
    expect(toResourceSlug("飲み物", "category")).toMatch(/^category-/);
    expect(toResourceSlug("唐揚げ", "product")).toMatch(/^product-/);
    expect(toResourceSlug("東京店", "shop")).toMatch(/^shop-/);
  });

  it("returns empty only for genuinely blank input", () => {
    expect(toResourceSlug("", "shop")).toBe("");
    expect(toResourceSlug("   ", "shop")).toBe("");
    expect(toResourceSlug(null, "shop")).toBe("");
  });

  it("stays backend-valid for mixed names", () => {
    for (const name of ["東京 Store", "Size(大)", "2本", "※", "Café Nº1"]) {
      expect(toResourceSlug(name, "product")).toMatch(SHOP_SLUG_RULE);
    }
  });

  it("does not collide with the underscore option namespace", () => {
    expect(toResourceSlug("量", "option")).not.toBe(toOptionSlug("量", "option"));
  });
});
