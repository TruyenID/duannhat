import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { effectivePaymentOptionsService } from "./payment-method-service";

const originalFetch = global.fetch;
const fetchMock = vi.fn();

function mockOk(body: unknown = { data: { revision: 1, options: [] } }): void {
  fetchMock.mockResolvedValueOnce({
    ok: true,
    status: 200,
    json: async () => body,
  } as Response);
}

function callArgs(): { url: string; init: RequestInit } {
  const [url, init] = fetchMock.mock.calls[0];
  return { url: String(url), init: init as RequestInit };
}

beforeEach(() => {
  global.fetch = fetchMock;
  fetchMock.mockReset();
  localStorage.setItem("pos_device_token", "test-token");
});

afterEach(() => {
  global.fetch = originalFetch;
});

describe("effectivePaymentOptionsService.list", () => {
  it("GETs /pos/effective-payment-options", async () => {
    mockOk();
    await effectivePaymentOptionsService.list("shop-a");
    const { url, init } = callArgs();
    expect(url).toContain("/api/v1/pos/effective-payment-options");
    expect(init?.method ?? "GET").toBe("GET");
  });

  it("returns the effective-options envelope", async () => {
    mockOk({
      data: {
        revision: 3,
        snapshot_hash: "abc",
        ownership_revision: "1",
        options: [
          {
            id: "opt-cash",
            display_name: "Cash",
            provider: "internal",
            rail: "cash",
            method_type: "cash",
            effective: true,
            source: "shop",
            reason: "",
            error_code: null,
            connection_id: "conn-1",
            connection_option_id: null,
            shop_option_id: "shop-opt-1",
            shop_preference: "inherit",
            device_preference: "inherit",
            legacy_payment_method_id: "pm-cash",
            legacy_payment_method_code: "cash",
            client: {
              requires_tendered: true,
              immediate_settlement: true,
              supports_pos_checkout: true,
            },
          },
        ],
      },
    });
    const res = await effectivePaymentOptionsService.list("shop-a");
    expect(res.data.revision).toBe(3);
    expect(res.data.options).toHaveLength(1);
    expect(res.data.options[0].client?.supports_pos_checkout).toBe(true);
  });
});
