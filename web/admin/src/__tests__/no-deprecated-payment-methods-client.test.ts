import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

/**
 * #1895 — nothing in admin-web may call the DEPRECATED HQ payment-methods CRUD.
 *
 * Those backend routes carry an RFC 8594 `Sunset: Fri, 01 Jan 2027` header that
 * has already gone out to clients, so the removal is a scheduled event, not open
 * debt. What made the schedule safe to keep was measured on 2026-08-06: the
 * admin-web consumer chain was **already dead**.
 *
 *     settings/payment-methods/{,new,[id]}/page.tsx   → 10-line redirects
 *     components/payment-method-form.tsx              → PaymentMethodForm used in 0 places
 *     hooks/api/use-payment-methods.ts                → only that orphan form imported it
 *     services/payment-method-service.ts              → 0 external consumers, both halves
 *     query-keys.ts::paymentMethodKeys                → 0 external consumers
 *
 * All five were deleted. This test is what keeps them deleted: a new caller
 * added between now and 2027 would be invisible — it would work perfectly,
 * because the routes still answer until the sunset — and the January removal
 * would then break a screen written in the meantime by someone who had no
 * reason to know.
 *
 * ## Scope, and what is deliberately NOT forbidden
 *
 * Only the **HQ brand-scoped CRUD** is deprecated. `GET /shops/{shop}/payment-methods`
 * and `GET /pos/payment-methods` are current API and are used by pos-web and the
 * workstation; a blanket ban on the string "payment-methods" would forbid them
 * too and would be wrong.
 *
 * The three redirect pages are also deliberately kept. They are not dead code —
 * they are a compatibility shim for bookmarks pointing at the old settings URL,
 * and deleting them converts an old bookmark into a 404. They contain no API
 * call, which is what this test actually cares about.
 */

const HQ_CRUD = /\/api\/v1\/hq\/\$\{[^}]*\}\/payment-methods|hq\/[^"'`]*\/payment-methods/;

function walk(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      if (entry === "node_modules" || entry === ".next") continue;
      out.push(...walk(full));
      continue;
    }
    if (/\.tsx?$/.test(entry)) out.push(full);
  }
  return out;
}

describe("#1895 — the deprecated HQ payment-methods CRUD has no client", () => {
  it("no source file builds a URL against /hq/{brand}/payment-methods", () => {
    const offenders: string[] = [];

    for (const file of walk(join(process.cwd(), "src"))) {
      // This test itself names the path in prose; skip it rather than weaken
      // the pattern, which would also stop matching a real offender.
      if (file.endsWith("no-deprecated-payment-methods-client.test.ts")) continue;

      const body = readFileSync(file, "utf8");
      if (HQ_CRUD.test(body)) {
        offenders.push(file.replace(process.cwd() + "/", ""));
      }
    }

    // `toEqual([])` rather than `.toHaveLength(0)`: on failure the diff names
    // the file, which is the whole point of running this at build time.
    expect(offenders).toEqual([]);
  });

  it("the deleted modules stay deleted", () => {
    const gone = [
      "src/services/payment-method-service.ts",
      "src/hooks/api/use-payment-methods.ts",
      "src/app/hq/[brandSlug]/settings/payment-methods/components/payment-method-form.tsx",
    ];

    for (const path of gone) {
      let exists = true;
      try {
        statSync(join(process.cwd(), path));
      } catch {
        exists = false;
      }

      expect(exists, `${path} came back — it targets routes that stop answering 2027-01-01`).toBe(
        false,
      );
    }
  });
});
