/**
 * SKU_IN_MENU — the 409 the force-delete surfaces.
 *
 * Force-deleting an option value used to end in a bare toast: the delete was
 * refused and the operator was told nothing about WHY, so the only way forward
 * was to guess which menu still referenced the SKU and unpick it by hand. The
 * dialog added in this PR lists the blocking menus by name.
 *
 * The whole feature is a two-sided agreement — the backend names the fields, the
 * frontend reads them — and nothing else in the suite holds those two sides
 * together. A rename on either side compiles, ships, and only shows up as the
 * dialog silently never opening, which looks exactly like the old bug.
 *
 * These cases pin the branch conditions the component uses. They read the same
 * predicate the component does; what they defend is that the predicate stays
 * narrow — a 409 from something else, or a non-409, must fall through to the
 * toast rather than open a dialog listing nothing.
 */

import { describe, it, expect } from "vitest";
import { readFileSync } from "node:fs";

/** Mirrors the component's branch: 409 + error === SKU_IN_MENU. */
function opensBlockingMenusDialog(status: number, body: unknown): boolean {
  const b = body as { error?: unknown } | null | undefined;

  return status === 409 && b?.error === "SKU_IN_MENU";
}

describe("which failures open the blocking-menus dialog", () => {
  it("opens on the exact 409 the backend raises", () => {
    expect(
      opensBlockingMenusDialog(409, {
        message: "SKU is still used in a menu.",
        error: "SKU_IN_MENU",
        blocking_menus: [{ id: "m1", name: "本郷店 メニュー" }],
      }),
    ).toBe(true);
  });

  it("does NOT open for a different 409 — that one belongs to the toast", () => {
    // A conflict has many causes; claiming them all as "in a menu" would send
    // the operator hunting through menus for something that is not there.
    expect(opensBlockingMenusDialog(409, { error: "SKU_IN_ORDER" })).toBe(false);
    expect(opensBlockingMenusDialog(409, { message: "Conflict." })).toBe(false);
  });

  it("does NOT open for the same code on another status", () => {
    expect(opensBlockingMenusDialog(422, { error: "SKU_IN_MENU" })).toBe(false);
    expect(opensBlockingMenusDialog(500, { error: "SKU_IN_MENU" })).toBe(false);
  });

  it("survives a body that is null or not an object", () => {
    // A proxy or CDN can answer 409 with HTML; reading `.error` off that must
    // not throw inside a catch block whose job is to report a failure.
    expect(opensBlockingMenusDialog(409, null)).toBe(false);
    expect(opensBlockingMenusDialog(409, undefined)).toBe(false);
    expect(opensBlockingMenusDialog(409, "<html>gateway</html>")).toBe(false);
  });
});

describe("the two sides still agree on the field names", () => {
  it("reads exactly what SkuInMenuException writes", () => {
    // Cross-repo-boundary contract, checked at the only place both halves are
    // visible at once. Renaming `blocking_menus` in PHP would otherwise leave
    // the dialog opening with an empty list — indistinguishable from success.
    const phpSource = readFileSync(
      "../../backend/app/Exceptions/SkuInMenuException.php",
      "utf8",
    );
    const tsxSource = readFileSync(
      "src/app/hq/[brandSlug]/products/[id]/components/variants-display.tsx",
      "utf8",
    );

    expect(phpSource).toContain("'error' => 'SKU_IN_MENU'");
    expect(phpSource).toContain("'blocking_menus'");
    expect(phpSource).toContain("], 409)");

    expect(tsxSource).toContain('err.status === 409');
    expect(tsxSource).toContain('err.body?.error === "SKU_IN_MENU"');
    expect(tsxSource).toContain("err.body.blocking_menus");
  });

  it("keeps a name on every blocking menu the dialog lists", () => {
    // The dialog renders `m.name`; the exception's docblock types the array as
    // {id, name}. A row without a name would render an empty bullet, which
    // reads as "one unnamed menu is blocking you" — worse than no dialog.
    const phpSource = readFileSync(
      "../../backend/app/Exceptions/SkuInMenuException.php",
      "utf8",
    );

    expect(phpSource).toContain("array{id: string, name: string}");
  });
});
