import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { join } from "node:path";

/**
 * #1961 + #1959 — two screens that showed a confident-looking answer they had
 * no basis for.
 *
 * They are pinned together because the defect is the same shape in both, and
 * the same shape is what a future edit will reintroduce: a number rendered
 * precisely while the data behind it is missing or mixed. A test per screen
 * that only checked "the card renders" would pass in both broken states.
 *
 * Asserted against SOURCE rather than a mounted render because what must not
 * come back is a specific WIRING — `orders[0]?.currency` as the aggregate's
 * currency, and a `<Pagination>` on a tree. Both look entirely reasonable in a
 * diff, and both produce a screen that renders beautifully and lies.
 */

function read(relative: string): string {
  return readFileSync(join(process.cwd(), relative), "utf8");
}

/**
 * Source with comments stripped.
 *
 * Needed because the fixes DOCUMENT the wiring they removed — the orders page
 * explains why `orders[0]?.currency` was wrong, the categories page explains
 * why `<Pagination>` cannot work on a tree. A raw `not.toContain` matches that
 * prose and fails on the very comment that makes the fix understandable, which
 * would push the next author to delete the explanation to get green.
 */
function code(relative: string): string {
  return read(relative)
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .split("\n")
    .filter((line) => !line.trim().startsWith("//"))
    .join("\n");
}

const ORDERS_PAGE = "src/app/hq/[brandSlug]/orders/page.tsx";
const CATEGORIES_PAGE = "src/app/hq/[brandSlug]/categories/page.tsx";

describe("#1961 — the HQ orders aggregate never guesses its currency", () => {
  it("does not take the aggregate currency from the first row", () => {
    const src = code(ORDERS_PAGE);

    // The original line. Its comment claimed "the list is normally scoped to
    // one branch", but the branch filter defaults to ALL branches, so the
    // default view labelled a VND+JPY sum with whichever symbol row 0 carried.
    expect(src).not.toContain("orders[0]?.currency");
  });

  it("only prints a single amount when the server reports exactly one currency", () => {
    const src = read(ORDERS_PAGE);

    // One currency → an amount. Anything else → no amount at all, because the
    // backend sums `total_amount` across currencies and there is no symbol that
    // makes such a sum true.
    expect(src).toContain("aggCurrencies.length === 1");
    expect(src).toContain("aggMixedCurrency");
    expect(src).toContain("MixedCurrencyStatCard");
  });

  it("the mixed-currency card shows no total", () => {
    const card = read("src/app/hq/[brandSlug]/orders/components/revenue-stat-card.tsx");
    const start = card.indexOf("export function MixedCurrencyStatCard");
    expect(start).toBeGreaterThan(-1);

    const body = card.slice(start);

    // The whole point: it must not accept, and therefore cannot render, a value.
    expect(body).not.toContain("formatCurrency");
    expect(body).not.toContain("value");
  });

  it("the service type marks `currencies` optional so an old backend is not read as one currency", () => {
    const service = read("src/services/order-service.ts");

    // `currencies?: string[]` — absent means UNKNOWN. Typing it as required
    // would make a deploy-order skew (frontend ahead of backend) resolve to
    // `[]`, which `length === 1` rejects — correct — but it would also mean the
    // field silently disappearing from the API produces a type lie rather than
    // a visible gap.
    expect(service).toContain("currencies?: string[]");
  });
});

describe("#1959 — the HQ category tree does not fake pagination", () => {
  it("renders no pagination control", () => {
    const src = code(CATEGORIES_PAGE);

    // A hierarchy cannot be paginated by row: page 2 holds children whose
    // parents are on page 1, so the second page is a forest of orphans. The
    // control was wired to state the query never read, so the buttons moved
    // nothing while the tree stopped at 100.
    expect(src).not.toContain("<Pagination");
    expect(src).not.toContain("@/components/shared/pagination");
  });

  it("states the load cap and checks it against the real total", () => {
    const src = read(CATEGORIES_PAGE);

    expect(src).toContain("CATEGORY_TREE_CAP");
    // The comparison is the fix. Removing the pagination control alone would
    // leave the truncation exactly as silent as before — tidier, still lying.
    expect(src).toMatch(/meta\?\.total\s*\?\?\s*0\)\s*>\s*CATEGORY_TREE_CAP/);
    expect(src).toContain("hq.categories.truncated");
  });
});

describe("both screens carry their copy in all three locales", () => {
  it.each(["ja", "en", "vi"])("%s has the new keys", (locale) => {
    const catalogue = JSON.parse(read(`src/i18n/${locale}.json`)) as Record<string, string>;

    // A missing key renders as the key itself — so the "we cannot show this"
    // message would come out as `hq.categories.truncated`, which reads as a
    // bug rather than as the explanation it is meant to be.
    expect(catalogue["hq.categories.truncated"]).toBeTruthy();
    expect(catalogue["hq.orders.stat.mixed_currency_hint"]).toBeTruthy();
  });
});
