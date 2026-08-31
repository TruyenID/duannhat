/**
 * #2108 (ruling #2102) — 総額表示 (`prices_include_tax`) is a MONEY switch,
 * not a display switch.
 *
 * The pre-#2108 hint on this toggle claimed the exact opposite — "only shows
 * customers whether the price includes tax, does not change the price" — and
 * that copy is part of how a real shop ended up over-charging ~10% (#2102):
 * menu prices were entered tax-inclusive, the flag stayed OFF because the UI
 * said it was cosmetic, and the engine added tax on top of prices that
 * already contained it.
 *
 * So this file pins the screen, not the engine:
 *
 * 1. the PERMANENT red warning next to the toggle — it must say the setting
 *    changes what customers PAY on new orders, and it must be there even when
 *    nothing is locked (it is a comprehension warning, not a state warning);
 * 2. the open-shift lock — the toggle disables while a till shift/chain is
 *    open, mirroring the backend 409 TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT so
 *    the admin never bounces off a PATCH the UI happily offered.
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";

vi.mock("next/navigation", () => ({
  useParams: () => ({ shopSlug: "test-shop" }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => "/shop/test-shop/settings",
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

/** Per-test till state: does the branch have an open shift/chain? */
let hasOpenShift = false;

/** Minimal order settings — every field the tab reads has a ?? fallback. */
const orderSettings = {
  default_order_item_status: "pending",
  enable_quick_order: false,
  allow_item_edit_any_status: false,
  prices_include_tax: false,
  currency_code: "JPY",
  available_statuses: [],
  available_currencies: [],
};

vi.mock("@/lib/api", () => {
  class ApiError extends Error {
    status: number;
    body: Record<string, unknown>;
    constructor(status: number, body: Record<string, unknown>) {
      super((body.message as string) || `API Error ${status}`);
      this.status = status;
      this.body = body;
      this.name = "ApiError";
    }
  }

  return {
    ApiError,
    apiFetch: vi.fn().mockImplementation((url: string) => {
      if (url.includes("/settings/order")) {
        return Promise.resolve({ data: orderSettings });
      }
      if (url.includes("/till/current")) {
        return Promise.resolve({
          data: {
            has_open_shift: hasOpenShift,
            has_open_chain: false,
            open_session: hasOpenShift
              ? {
                  id: "s1",
                  session_code: "S-001",
                  opened_at: "2026-08-08T09:00:00+09:00",
                  opener_name: "Tanaka",
                  opened_by_id: "u1",
                  default_currency_code: "JPY",
                }
              : null,
          },
        });
      }
      if (url.endsWith("/api/v1/shops/test-shop")) {
        return Promise.resolve({ data: { brand_slug: "test-brand" } });
      }
      // Everything else (tax-type lookup, payment options, …) — empty list.
      return Promise.resolve({ data: [] });
    }),
  };
});

import { AppProvider } from "@/providers/app-provider";
import SettingsPage from "@/app/shop/[shopSlug]/settings/page";

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <QueryClientProvider client={queryClient}>
      <AppProvider defaultLocale="en">{children}</AppProvider>
    </QueryClientProvider>
  );
}

async function renderOrderTab() {
  render(<SettingsPage />, { wrapper });
  // The order tab is the default tab; wait for the settings query to land.
  await waitFor(() => {
    expect(screen.getByRole("switch", { name: /Prices Include Tax/i })).toBeInTheDocument();
  });
}

describe("shop settings — 総額表示 toggle (#2108)", () => {
  beforeEach(() => {
    hasOpenShift = false;
  });

  it("shows the permanent red money warning next to the toggle", async () => {
    await renderOrderTab();

    // The warning is a comprehension warning, so it must be visible in the
    // normal (nothing locked) state — not only when something is blocked.
    const warning = screen.getByText(/changes the actual amount customers pay on NEW orders/i);
    expect(warning).toBeInTheDocument();
    expect(warning.closest('[role="alert"]')).not.toBeNull();

    // And the hint must no longer claim the flag is display-only.
    expect(screen.queryByText(/does not change the price/i)).toBeNull();
  });

  it("keeps the toggle enabled when no shift is open", async () => {
    await renderOrderTab();

    // The switch mounts before the settings query lands (disabled while
    // loading), so assert the settled state, not the first paint.
    await waitFor(() =>
      expect(screen.getByRole("switch", { name: /Prices Include Tax/i })).toBeEnabled()
    );
  });

  it("locks the toggle while a till shift is open (mirrors the 409 guard)", async () => {
    hasOpenShift = true;
    await renderOrderTab();

    // The lock banner only renders once the till-status query resolved with an
    // open shift — waiting for it rules out "disabled because still loading".
    await waitFor(() =>
      expect(
        screen.getByText(/Tax settings are locked while a cashier shift is open/i)
      ).toBeInTheDocument()
    );
    expect(screen.getByRole("switch", { name: /Prices Include Tax/i })).toBeDisabled();

    // The red money warning does not disappear behind the lock — an admin
    // reading the locked screen still needs to know what the switch does.
    expect(
      screen.getByText(/changes the actual amount customers pay on NEW orders/i)
    ).toBeInTheDocument();
  });
});
