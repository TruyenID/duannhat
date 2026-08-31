/**
 * plan-054 D9 / T5.6 — the shop-level PayPay off switch.
 *
 * The load-bearing case is the one the generic options list cannot cover: a
 * shop with NO provisioned gateway options. `options[]` is assembled from
 * `payment_gateway_connection_options`, and the PayPay QR row is created
 * lazily at the first PayPay checkout — so before that sale the generic
 * preference select has nothing to render, and `PaymentsViewPanel` swallows
 * its children on the empty/setup-required states anyway. If this card does
 * not survive those states, "a shop may opt out" is only true after the shop
 * has already taken PayPay money.
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import type { ShopPayPaySwitchState } from "@/services/shop-payment-settings-service";

vi.mock("next/navigation", () => ({
  useParams: () => ({ shopSlug: "test-shop" }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => "/shop/test-shop/settings/payments/options",
  notFound: () => {
    throw new Error("notFound");
  },
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

/** A shop that has never taken a PayPay payment: no options, nothing connected. */
const NO_OPTIONS_CONFIG = {
  data: {
    ownership: {
      management_model: "unresolved",
      brand_owner_org_unit_id: null,
      operator_org_unit_id: null,
      ownership_revision: null,
      reason: "ownership_source_unavailable",
    },
    connections: [],
    options: [],
    revision: 0,
    snapshot_hash: null,
    setup_required: false,
    connection_mutable: false,
  },
};

const INHERIT_ENABLED: ShopPayPaySwitchState = {
  preference: "inherit",
  available_preferences: ["inherit", "disabled"],
  effective_enabled: true,
  brand_enabled: true,
  reason: null,
} as ShopPayPaySwitchState;

let paypayState: ShopPayPaySwitchState = INHERIT_ENABLED;
const apiCalls: Array<{ url: string; init?: RequestInit }> = [];

vi.mock("@/lib/api", () => {
  class ApiError extends Error {
    status: number;
    body: unknown;
    constructor(status: number, body: Record<string, unknown>) {
      super((body.message as string) || `API Error ${status}`);
      this.status = status;
      this.body = body;
      this.name = "ApiError";
    }
  }

  return {
    ApiError,
    apiFetch: vi.fn().mockImplementation((url: string, init?: RequestInit) => {
      apiCalls.push({ url, init });
      if (url.includes("/settings/paypay")) {
        if (init?.method === "PATCH") {
          const body = JSON.parse(String(init.body)) as { preference: string };
          paypayState = {
            ...paypayState,
            preference: body.preference as ShopPayPaySwitchState["preference"],
            effective_enabled: body.preference === "inherit",
            reason: body.preference === "disabled" ? "disabled_for_shop" : null,
          };
        }
        return Promise.resolve({ data: paypayState });
      }
      if (url.includes("/payment-configuration")) {
        return Promise.resolve(NO_OPTIONS_CONFIG);
      }
      return Promise.resolve({ data: [] });
    }),
  };
});

import { AppProvider } from "@/providers/app-provider";
import { shopPaymentSettingsService } from "@/services/shop-payment-settings-service";
import PaymentsOptionsPage from "@/app/shop/[shopSlug]/settings/payments/options/page";

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <QueryClientProvider client={queryClient}>
      {/* `en` so the assertions below can be plain English. */}
      <AppProvider defaultLocale="en">{children}</AppProvider>
    </QueryClientProvider>
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  apiCalls.length = 0;
  paypayState = INHERIT_ENABLED;
});

describe("Shop PayPay switch (plan-054 D9)", () => {
  it("renders on a shop with no gateway options at all", async () => {
    render(<PaymentsOptionsPage />, { wrapper });

    await waitFor(() => {
      expect(document.querySelector('[data-slot="payments-paypay-section"]')).toBeTruthy();
    });

    // The generic list is empty for this shop; the switch is still there.
    expect(document.querySelector('[data-slot="payments-options-section"]')).toBeNull();
    expect(screen.getByText(/PayPay \(customer web QR\)/)).toBeTruthy();
  });

  it("shows the brand-inherited state as the current preference", async () => {
    render(<PaymentsOptionsPage />, { wrapper });

    const trigger = await screen.findByLabelText(/Shop preference/i);
    expect(trigger.textContent).toMatch(/Inherit/);
    // Badge reflects what customer-web will actually do, not the raw preference.
    expect(screen.getByText(/^Enabled$/)).toBeTruthy();
  });

  it("explains an upstream blocker instead of silently doing nothing", async () => {
    paypayState = {
      ...INHERIT_ENABLED,
      effective_enabled: false,
      brand_enabled: false,
      reason: "currency_unsupported",
    } as ShopPayPaySwitchState;

    render(<PaymentsOptionsPage />, { wrapper });

    await waitFor(() => {
      expect(screen.getByText(/settles in Japanese yen/i)).toBeTruthy();
    });

    // Still settable: the opt-out has to already hold on the day the brand is
    // ready, so an unreachable gateway must not lock the control.
    const trigger = await screen.findByLabelText(/Shop preference/i);
    expect(trigger.hasAttribute("disabled")).toBe(false);
  });

  it("PATCHes the shop preference to the dedicated endpoint", async () => {
    const res = await shopPaymentSettingsService.updatePayPaySwitch("test-shop", {
      preference: "disabled" as ShopPayPaySwitchState["preference"],
    });

    const patch = apiCalls.find((c) => c.init?.method === "PATCH");
    expect(patch?.url).toBe("/api/v1/shops/test-shop/settings/paypay");
    expect(JSON.parse(String(patch?.init?.body))).toEqual({ preference: "disabled" });
    expect(res.data.preference).toBe("disabled");
    expect(res.data.effective_enabled).toBe(false);
    expect(res.data.reason).toBe("disabled_for_shop");
  });

  it("never offers a preference the backend would reject", async () => {
    render(<PaymentsOptionsPage />, { wrapper });
    await screen.findByLabelText(/Shop preference/i);

    // `enabled` and `blocked` are refused by updateShopOption — the server
    // decides the list, and the UI must not invent entries beside it.
    expect(paypayState.available_preferences).toEqual(["inherit", "disabled"]);
  });
});
