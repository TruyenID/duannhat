import { describe, expect, it } from "vitest";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";

/**
 * #1960 + #1962 — list screens whose pagination controls are not wired through.
 *
 * Both bugs are the same species and it is a species this codebase keeps
 * producing: a control that renders the user's choice while the layer that
 * would act on it never receives it.
 *
 *   #1960  the rows-per-page selector reached the URL and the `<Pagination>`
 *          component — so it displayed "100" — but `apiFilters` omitted
 *          `per_page`, so the request kept asking for the backend default of 25.
 *   #1962  the STT column numbered rows with `row.index + 1`, restarting at 1
 *          on every page. Two different lots on two pages both read "1", which
 *          is worse than no number: staff call the column out loud during a
 *          stock count and it reads like a reference.
 *
 * Neither is visible in review — both screens look complete — and neither is
 * visible at runtime unless you happen to change the setting and count rows.
 *
 * So this file scans EVERY list screen rather than the three the issues named.
 * A hand-written list of the known-bad screens would have been green the day it
 * was written and silent about the fourth screen someone adds next month.
 */

const APP = join(process.cwd(), "src/app");

function walk(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      out.push(...walk(full));

      continue;
    }
    if (entry === "page.tsx") out.push(full);
  }

  return out;
}

/** Source with comments stripped — the fixes name the broken wiring in prose. */
function code(file: string): string {
  return readFileSync(file, "utf8")
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .split("\n")
    .filter((line) => !line.trim().startsWith("//"))
    .join("\n");
}

function rel(file: string): string {
  return file.replace(process.cwd() + "/", "");
}

const PAGES = walk(APP);

describe("#1960 — a rows-per-page selector must reach the request", () => {
  it("every screen that renders <Pagination> with perPage also sends per_page", () => {
    const offenders: string[] = [];

    for (const file of PAGES) {
      const src = code(file);

      // Only screens that actually offer the control. A screen with no selector
      // has nothing to fail to send.
      if (!src.includes("onPerPageChange")) continue;

      // Look at the REQUEST, not the whole file.
      //
      // My first version searched the whole source for `per_page:` — and would
      // have been GREEN on the exact bug it was written for. Both screens
      // already said `per_page` twice: once in `FILTER_DEFAULTS` (URL state) and
      // once as the `perPage={...}` prop (what the control displays). Those are
      // precisely the two places the value DID reach while the request did not.
      // Measured: deleting the fix from either screen left this test passing.
      //
      // Look at the REQUEST BUILDER, not the whole file.
      //
      // Three decoys say `per_page` on a screen that never sends it, and all
      // three were present while the bug was live:
      //
      //   FILTER_DEFAULTS        module scope, URL state
      //   perPage={...}          JSX prop — what the control DISPLAYS
      //   meta ?? { per_page }   JSX fallback for <Pagination>
      //
      // Two earlier versions of this check were fooled. The first searched the
      // whole file and would have been green on the exact bug it was written
      // for. The second stripped two decoys and missed the third — and I only
      // caught that because a mutation removed BOTH copies at once and so
      // "failed" for the wrong reason, which looked like proof.
      //
      // `apiFilters` is the object handed to the data hook on every screen that
      // has one; that is the only place the value has any effect.
      const filters = /apiFilters = useMemo\(([\s\S]*?)\n  \);/.exec(src);
      const request = filters ? filters[1] : src.slice(0, src.search(/\n\s*return \(/));

      if (!/per_page\s*:/.test(request)) {
        offenders.push(rel(file));
      }
    }

    expect(offenders).toEqual([]);
  });
});

describe("#1962 — a row-number column must carry the page offset", () => {
  it("no screen numbers rows with a bare row.index", () => {
    const offenders: string[] = [];

    for (const file of PAGES) {
      const src = code(file);

      // `row.index + 1` and NOTHING ELSE — the `$` anchor matters.
      //
      // My first version stopped at `+ 1` and flagged
      // `row.index + 1 + (page - 1) * perPage`, which is a CORRECT offset. A
      // scan that reports working code is worse than one that misses a bug:
      // the fastest way to make it green is to rewrite the correct screen.
      if (/cell:\s*\(\{\s*row\s*\}\)\s*=>\s*row\.index\s*\+\s*1\s*,?\s*$/m.test(src)) {
        offenders.push(rel(file));
      }
    }

    expect(offenders).toEqual([]);
  });

  it("the material-lots STT header is translated, not a hard-coded string", () => {
    // A small thing, but it is the reason the column was noticed at all: every
    // other screen calls `t(...)` here, and the literal marked this one as
    // written outside the pattern. Vietnamese "STT" also renders as "STT" for a
    // Japanese operator, so the drift was invisible in the default locale.
    const src = code(join(APP, "shop/[shopSlug]/material-lots/page.tsx"));

    expect(src).not.toContain('header: "STT"');
    expect(src).toContain("col.stt");
  });
});

describe("the scan itself is not vacuous", () => {
  it("finds a realistic number of list screens", () => {
    // Both tests above pass trivially if `walk` returns nothing — a broken glob
    // reports "no offenders" and looks exactly like success. Pin the shape of
    // the search instead of trusting it.
    expect(PAGES.length).toBeGreaterThan(30);

    const withSelector = PAGES.filter((f) => code(f).includes("onPerPageChange"));
    expect(withSelector.length).toBeGreaterThan(5);
  });
});
