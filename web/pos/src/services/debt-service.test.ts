import { describe, it, expect, vi, beforeEach } from "vitest";
import { apiFetch } from "@/lib/api";
import { debtService } from "./debt-service";
import { posPaymentMethodService } from "./payment-method-service";

vi.mock("@/lib/api", () => ({
  apiFetch: vi.fn(),
  ApiError: class ApiError extends Error {},
}));

const mockFetch = apiFetch as unknown as ReturnType<typeof vi.fn>;

beforeEach(() => {
  mockFetch.mockReset();
  mockFetch.mockResolvedValue({ data: [] });
});

/**
 * These pin the URLs, which is the whole substance of this service — every one
 * of them was wrong in a way that shipped:
 *
 *   - the debt list used to be called on `/shops/{slug}/debts`, a route behind
 *     Platform SSO that a device token can never satisfy (401 missing
 *     principal);
 *   - the on-account payment method was read out of effective-payment-options,
 *     a list that structurally cannot contain it, leaving the "Ghi nợ" button
 *     permanently disabled;
 *   - `part-paid` is a LITERAL segment that sits next to a `{customer}`
 *     wildcard, so a typo here reaches a different endpoint and 400s.
 */
describe("debtService — which endpoint each call actually hits", () => {
  it("lists debtors from the POS namespace, not the SSO-only shops one", async () => {
    await debtService.list();

    expect(mockFetch).toHaveBeenCalledWith("/api/v1/pos/debts?limit=100");
  });

  it("honours an explicit limit", async () => {
    await debtService.list(25);

    expect(mockFetch).toHaveBeenCalledWith("/api/v1/pos/debts?limit=25");
  });

  it("reads one customer's individual debts", async () => {
    await debtService.listForCustomer("cus-1");

    expect(mockFetch).toHaveBeenCalledWith("/api/v1/pos/debts/cus-1");
  });

  it("encodes the customer id rather than pasting it into the path", async () => {
    await debtService.listForCustomer("a/b?c");

    expect(mockFetch).toHaveBeenCalledWith("/api/v1/pos/debts/a%2Fb%3Fc");
  });

  it("reads part-paid orders from the literal segment", async () => {
    await debtService.listPartPaid();

    // Not `/debts/{customer}`: the route is declared before the wildcard on
    // the server precisely so this literal wins.
    expect(mockFetch).toHaveBeenCalledWith("/api/v1/pos/debts/part-paid");
  });
});

describe("posPaymentMethodService", () => {
  it("reads the raw payment_methods rows, where on_account is the ONLY place it exists", async () => {
    await posPaymentMethodService.list();

    expect(mockFetch).toHaveBeenCalledWith("/api/v1/pos/payment-methods");
  });
});
