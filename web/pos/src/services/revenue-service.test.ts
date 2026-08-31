import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { revenueService } from "./revenue-service";

const originalFetch = global.fetch;
const fetchMock = vi.fn();

function mockOk(body: unknown = { data: {} }): void {
  fetchMock.mockResolvedValueOnce({
    ok: true,
    status: 200,
    json: async () => body,
  } as Response);
}

beforeEach(() => {
  global.fetch = fetchMock;
  fetchMock.mockReset();
  // Pair the device so /pos/* calls clear the #472 no-token fail-fast guard.
  localStorage.setItem("pos_device_token", "test-token");
});

afterEach(() => {
  global.fetch = originalFetch;
});

describe("revenueService.summary", () => {
  it("calls GET /api/v1/pos/revenue/summary with no params", async () => {
    mockOk({ data: { granularity: "day", series: [] } });
    await revenueService.summary("shop-a");
    const url = String(fetchMock.mock.calls[0][0]);
    expect(url).toContain("/api/v1/pos/revenue/summary");
    expect(url).not.toContain("?");
  });

  it("serialises granularity / from / to into the query string", async () => {
    mockOk({ data: { granularity: "month", series: [] } });
    await revenueService.summary("shop-a", {
      granularity: "month",
      from: "2026-01-01",
      to: "2026-12-31",
    });
    const url = String(fetchMock.mock.calls[0][0]);
    expect(url).toContain("granularity=month");
    expect(url).toContain("from=2026-01-01");
    expect(url).toContain("to=2026-12-31");
  });

  it("omits empty filter values", async () => {
    mockOk({ data: { granularity: "day", series: [] } });
    await revenueService.summary("shop-a", { granularity: "day" });
    const url = String(fetchMock.mock.calls[0][0]);
    expect(url).toContain("granularity=day");
    expect(url).not.toContain("from=");
    expect(url).not.toContain("to=");
  });
});

describe("revenueService.voids", () => {
  it("calls GET /api/v1/pos/revenue/voids with no params", async () => {
    mockOk({ data: { granularity: "day", series: [] } });
    await revenueService.voids("shop-a");
    const url = String(fetchMock.mock.calls[0][0]);
    expect(url).toContain("/api/v1/pos/revenue/voids");
    expect(url).not.toContain("?");
  });

  it("serialises granularity / from / to into the query string", async () => {
    mockOk({ data: { granularity: "day", series: [] } });
    await revenueService.voids("shop-a", {
      granularity: "day",
      from: "2026-05-01",
      to: "2026-05-31",
    });
    const url = String(fetchMock.mock.calls[0][0]);
    expect(url).toContain("granularity=day");
    expect(url).toContain("from=2026-05-01");
    expect(url).toContain("to=2026-05-31");
  });

  it("returns the parsed data envelope", async () => {
    mockOk({
      data: {
        granularity: "day",
        from: "2026-05-01",
        to: "2026-05-01",
        kpis: {
          order_voids: 1,
          order_void_value: 3000,
          item_voids: 1,
          item_void_value: 1200,
          order_void_rate_pct: 50,
        },
        series: [
          { period: "2026-05-01", order_voids: 1, item_voids: 1, void_value: 4200 },
        ],
        order_reasons: [{ reason: "manager_void", count: 1, value: 3000 }],
        item_reasons: [{ reason: "wrong_item", count: 1, value: 1200 }],
        top_items: [
          { name: "Cà phê sữa", variant: "L", count: 1, value: 1200 },
        ],
        generated_at: "2026-05-02T00:00:00Z",
      },
    });
    const res = await revenueService.voids("shop-a", { granularity: "day" });
    expect(res.data.kpis.order_voids).toBe(1);
    expect(res.data.kpis.item_void_value).toBe(1200);
    expect(res.data.series).toHaveLength(1);
    expect(res.data.top_items[0]?.name).toBe("Cà phê sữa");
  });
});

describe("revenueService.voidEvents", () => {
  it("calls GET /api/v1/pos/revenue/void-events with no params", async () => {
    mockOk({ data: { total: 0, rows: [] } });
    await revenueService.voidEvents("shop-a");
    const url = String(fetchMock.mock.calls[0][0]);
    expect(url).toContain("/api/v1/pos/revenue/void-events");
    expect(url).not.toContain("?");
  });

  it("serialises window + type + pagination into the query string", async () => {
    mockOk({ data: { total: 0, rows: [] } });
    await revenueService.voidEvents("shop-a", {
      granularity: "day",
      from: "2026-05-01",
      to: "2026-05-31",
      type: "item",
      page: 2,
      per_page: 25,
    });
    const url = String(fetchMock.mock.calls[0][0]);
    expect(url).toContain("granularity=day");
    expect(url).toContain("from=2026-05-01");
    expect(url).toContain("to=2026-05-31");
    expect(url).toContain("type=item");
    expect(url).toContain("page=2");
    expect(url).toContain("per_page=25");
  });

  it("returns the parsed data envelope with rows", async () => {
    mockOk({
      data: {
        from: "2026-05-01",
        to: "2026-05-01",
        type: "all",
        total: 2,
        page: 1,
        per_page: 20,
        rows: [
          {
            kind: "item",
            order_id: "o1",
            order_code: "ORD-2026-0002",
            voided_at: "2026-05-01T14:00:00Z",
            reason: "wrong_item",
            item_name: "Spring Rolls",
            variant: "2pc",
            quantity: 2,
            item_count: 1,
            value: 1200,
          },
        ],
        generated_at: "2026-05-02T00:00:00Z",
      },
    });
    const res = await revenueService.voidEvents("shop-a", { type: "all" });
    expect(res.data.total).toBe(2);
    expect(res.data.rows[0]?.kind).toBe("item");
    expect(res.data.rows[0]?.order_code).toBe("ORD-2026-0002");
    expect(res.data.rows[0]?.value).toBe(1200);
  });
});
