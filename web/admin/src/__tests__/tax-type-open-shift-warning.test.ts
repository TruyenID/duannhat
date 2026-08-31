import { beforeEach, describe, expect, it, vi } from "vitest";
import { taxTypeService } from "@/services/tax-type-service";

/**
 * #1129 — an HQ tax-rate edit is deliberately NOT blocked while a cashier shift
 * is open (plan-043 Q6: per-line snapshots protect orders already created), but
 * it lands on EVERY branch of the brand at once. The backend answers with the
 * branches that are mid-shift so the operator is told instead of finding out
 * from a Z-report that will not reconcile.
 *
 * These pin the wire contract the warning toast depends on: `meta` must survive
 * the service layer. Before this, `update()` was typed `{ data: TaxType }` and
 * the field was dropped on the floor.
 */
describe("taxTypeService.update — open-shift meta (#1129)", () => {
  beforeEach(() => {
    vi.mocked(globalThis.fetch).mockClear();
  });

  function mockResponse(body: unknown) {
    vi.mocked(globalThis.fetch).mockResolvedValueOnce(
      new Response(JSON.stringify(body), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      })
    );
  }

  it("passes through the branches that are mid-shift", async () => {
    mockResponse({
      data: { id: "tt-1", rate: "8.00" },
      meta: {
        rate_changed: true,
        open_shift_branches: [
          { id: "br-1", name: "Ningyocho" },
          { id: "br-2", name: null },
        ],
      },
    });

    const result = await taxTypeService.update("betoya", "tt-1", { rate: 8 });

    expect(result.meta?.rate_changed).toBe(true);
    expect(result.meta?.open_shift_branches).toHaveLength(2);
    expect(result.meta?.open_shift_branches[0]).toEqual({ id: "br-1", name: "Ningyocho" });
    // A branch with no name still has to reach the toast — it falls back to the id.
    expect(result.meta?.open_shift_branches[1]?.name).toBeNull();
  });

  it("reports an empty list when nothing is mid-shift", async () => {
    mockResponse({
      data: { id: "tt-1", rate: "8.00" },
      meta: { rate_changed: true, open_shift_branches: [] },
    });

    const result = await taxTypeService.update("betoya", "tt-1", { rate: 8 });

    expect(result.meta?.rate_changed).toBe(true);
    expect(result.meta?.open_shift_branches).toEqual([]);
  });

  it("survives a response with no meta at all — an older backend", async () => {
    mockResponse({ data: { id: "tt-1", rate: "10.00" } });

    const result = await taxTypeService.update("betoya", "tt-1", { name: "標準" });

    expect(result.meta).toBeUndefined();
    expect(result.data.id).toBe("tt-1");
  });
});
