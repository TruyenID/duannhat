import { describe, expect, it, vi, beforeEach } from "vitest";
import { floatingSectionService } from "./floating-section-service";
import * as api from "@/lib/api";

/**
 * #1320 — the spotlight read path.
 *
 * The one behaviour worth pinning here is the FAILURE mode. This endpoint lives
 * only on the workstation; a POS talking to Cloud gets a 404, and a POS on a
 * dead LAN gets a network error. Neither is something the cashier can act on —
 * "this shop has no promotions right now" is the honest reading — so both must
 * come back as an empty list, silently. A thrown error here would surface as a
 * red toast on a perfectly healthy till.
 */
describe("floatingSectionService.listOpen", () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it("returns the sections the workstation says are open", async () => {
    vi.spyOn(api, "apiFetch").mockResolvedValue({
      data: [
        {
          id: "fs1",
          name: "Giờ vàng",
          priority: 5,
          products: [
            {
              floating_section_product_id: "fsp1",
              product_id: "p1",
              name: "Bia",
              image_url: null,
              tax_type_id: "tax-reduced",
              display_order: 0,
              skus: [
                { id: "sk1", name: "Chai", sku: "BIA-1", selling_price: 30000, image_url: null },
              ],
            },
          ],
        },
      ],
    } as never);

    const out = await floatingSectionService.listOpen();

    expect(out).toHaveLength(1);
    expect(out[0].products[0].floating_section_product_id).toBe("fsp1");
    // The PROMO price, straight through — the client never recomputes it.
    expect(out[0].products[0].skus[0].selling_price).toBe(30000);
  });

  it("returns [] when the endpoint does not exist (Cloud-only POS)", async () => {
    vi.spyOn(api, "apiFetch").mockRejectedValue(
      new api.ApiError(404, { message: "Not Found" }),
    );

    await expect(floatingSectionService.listOpen()).resolves.toEqual([]);
  });

  it("returns [] when the workstation is unreachable", async () => {
    vi.spyOn(api, "apiFetch").mockRejectedValue(new TypeError("Failed to fetch"));

    await expect(floatingSectionService.listOpen()).resolves.toEqual([]);
  });

  it("tolerates a response with no data key rather than throwing", async () => {
    vi.spyOn(api, "apiFetch").mockResolvedValue({} as never);

    await expect(floatingSectionService.listOpen()).resolves.toEqual([]);
  });
});
