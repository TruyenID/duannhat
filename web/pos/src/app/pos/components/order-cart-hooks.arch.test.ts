import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

/**
 * #2746 — the three reopen/void hooks MUST run on every render, including
 * the empty-cart and skeleton early returns. Putting them after
 * `if (!order && !isLoading) return` is React error #310.
 */
const src = readFileSync(
  join(dirname(fileURLToPath(import.meta.url)), "order-cart.tsx"),
  "utf8",
);

describe("OrderCart hooks vs early return (#2746)", () => {
  it("reopen/void state and the tab-switch effect sit BEFORE the first early return", () => {
    const reopenAt = src.indexOf("const [reopenOpen, setReopenOpen] = useState(false);");
    const voidAt = src.indexOf("const [voidOpen, setVoidOpen] = useState(false);");
    const effectAt = src.indexOf("}, [order?.id]);");
    const earlyAt = src.indexOf("if (!order && !isLoading)");

    expect(reopenAt).toBeGreaterThan(0);
    expect(voidAt).toBeGreaterThan(reopenAt);
    expect(effectAt).toBeGreaterThan(voidAt);
    expect(earlyAt).toBeGreaterThan(effectAt);
  });

  it("tab-switch effect depends on order?.id so it can run while order is undefined", () => {
    expect(src).toContain("}, [order?.id]);");
    expect(src).not.toMatch(/useEffect\(\(\) => \{\s*setVoidOpen\(false\);\s*setReopenOpen\(false\);\s*\}, \[order\.id\]\)/);
  });
});
