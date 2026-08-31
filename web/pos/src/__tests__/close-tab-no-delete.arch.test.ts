import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

/**
 * #2528 — the ✕ on an order tab must CLOSE THE TAB, never delete the order.
 *
 * The bug was a hard delete behind a button that looks like "hide this".
 * The fix removed the call — and nothing pinned the removal: putting
 * `deleteOrder.mutateAsync(tab.orderId)` back into `handleCloseTab` left all
 * 1194 tests green, because the only new test covers the pure decision
 * function and never renders the page.
 *
 * A source-level guard is the cheap instrument here, the same one
 * `dialog-boundary.arch.test.ts` uses: the property is "this call site does not
 * exist", which no amount of rendering asserts as directly.
 */
describe("close-tab never deletes", () => {
  const page = readFileSync("src/app/pos/page.tsx", "utf8");

  /** `handleCloseTab` plus everything up to the next top-level declaration. */
  function closeTabHandler(): string {
    const start = page.indexOf("function handleCloseTab");
    expect(start, "handleCloseTab is gone — rename or refactor?").toBeGreaterThan(-1);
    const rest = page.slice(start + 1);
    const end = rest.search(/\n {2}(?:async )?function /);

    return end === -1 ? rest : rest.slice(0, end);
  }

  it("the ✕ handler issues no order-delete call", () => {
    const body = closeTabHandler();

    for (const forbidden of ["deleteOrder", "useDeleteOrder", "orders.delete"]) {
      expect(
        body.includes(forbidden),
        `handleCloseTab must not reference ${forbidden} — closing a tab is a UI action, ` +
          "and #2528 is the incident where it removed a real order instead",
      ).toBe(false);
    }
  });

  it("the confirm dialog is still what stands between ✕ and an unreachable order", () => {
    // Pairs with the assertion above: proving "no delete" is worth little if the
    // warning path were removed in the same edit, since the order would then be
    // orphaned silently rather than deleted loudly.
    expect(page).toContain("decideCloseTab");
    expect(page).toContain("warn_unreachable");
  });
});
