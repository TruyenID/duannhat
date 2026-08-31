/**
 * Plan-047 T5.10 — shop payment settings component tests.
 * Covers G7, G8, G10/G11, G12 and the G14 "secrets never in DOM" rule.
 *
 * Fixtures mirror the presenter shapes in
 * `services/shop-payment-settings-service.ts` exactly (`ownership`,
 * `connections[]`, `options[]`, `setup_required`, `connection_mutable`). They
 * were rewritten for #1184 after the plan-047 rewire (#1157) changed the
 * payload from `connection` (singular) to `connections[]` and swapped the
 * device override from a radio group to a per-option switch — the old fixtures
 * had been silently red ever since, because nothing ran this suite.
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { toast } from "sonner";
import {
  PAYMENTS_STATE_TEST_IDS,
  resolvePaymentsViewState,
} from "@/app/shop/[shopSlug]/settings/payments/lib/payments-view-state";
import {
  redactPaymentSecrets,
  type ShopPaymentConfiguration,
} from "@/services/shop-payment-settings-service";

vi.mock("next/navigation", () => ({
  useParams: () => ({ shopSlug: "test-shop", deviceId: "dev-1" }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => "/shop/test-shop/settings/payments/connection",
  notFound: () => {
    throw new Error("notFound");
  },
}));

vi.mock("sonner", () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}));

/**
 * A payload as the backend would send it if it leaked credentials — the point
 * of G14 is that `redactPaymentSecrets` strips them in the service layer, so
 * none of these strings can reach the DOM even by accident.
 */
const SECRET_PAYLOAD = {
  data: {
    ownership: {
      management_model: "hq_managed",
      brand_owner_org_unit_id: "ou-brand-1",
      operator_org_unit_id: null,
      ownership_revision: "rev-7",
      reason: null,
    },
    connections: [
      {
        id: "conn-1",
        provider: "stripe",
        environment: "test",
        owner_scope: "hq",
        merchant_display_name: "Demo Brand HQ",
        merchant_account_id: "acct_***1234",
        health: "ready",
        health_reason_code: null,
        is_active: true,
        last_validated_at: "2026-07-20T03:44:41Z",
        secret: "sk_live_SHOULD_NEVER_RENDER",
        api_key: "pk_test_SHOULD_NEVER_RENDER",
        client_secret: "cs_test_SHOULD_NEVER_RENDER",
      },
    ],
    options: [
      {
        id: "opt-cash",
        display_name: "Cash",
        provider: "internal",
        rail: "cash",
        method_type: null,
        effective: true,
        source: "effective",
        reason: "ENABLED",
        error_code: null,
        connection_id: null,
        connection_option_id: null,
        shop_option_id: "spo-1",
        owner_scope: "hq",
        operator_org_unit_id: null,
        shop_preference: "enabled",
        device_preference: "inherit",
        trace: [],
      },
    ],
    revision: 3,
    snapshot_hash: "hash-3",
    setup_required: false,
    connection_mutable: false,
  },
} as unknown as { data: ShopPaymentConfiguration };

/** G8 — franchise shop that has not connected its own gateway yet. */
const FRANCHISE_NO_CONNECTION = {
  data: {
    ownership: {
      management_model: "franchise_owned",
      brand_owner_org_unit_id: "ou-brand-1",
      operator_org_unit_id: "ou-franchise-a",
      ownership_revision: "rev-7",
      reason: null,
    },
    connections: [],
    options: [],
    revision: 0,
    snapshot_hash: null,
    setup_required: true,
    connection_mutable: true,
  },
} as unknown as { data: ShopPaymentConfiguration };

/** G7-adjacent — ownership could not be resolved; disclosure is fail-closed. */
const UNRESOLVED_OWNERSHIP = {
  data: {
    ...FRANCHISE_NO_CONNECTION.data,
    ownership: {
      management_model: "unresolved",
      brand_owner_org_unit_id: null,
      operator_org_unit_id: null,
      ownership_revision: null,
      reason: "ownership_source_unavailable",
    },
    setup_required: false,
    connection_mutable: false,
  },
} as unknown as { data: ShopPaymentConfiguration };

const DEVICE_EVALUATION = {
  data: {
    revision: 3,
    snapshot_hash: "hash-3",
    ownership_revision: "rev-7",
    published_at: "2026-07-20T03:44:41Z",
    options: [
      {
        id: "opt-cash",
        display_name: "Cash",
        provider: "internal",
        rail: "cash",
        method_type: null,
        effective: true,
        source: "effective",
        reason: "ENABLED",
        error_code: null,
        connection_id: null,
        connection_option_id: null,
        shop_option_id: "spo-1",
        owner_scope: "hq",
        operator_org_unit_id: null,
        shop_preference: "enabled",
        device_preference: "inherit",
        trace: [],
      },
    ],
  },
};

let configResponse: { data: ShopPaymentConfiguration } = SECRET_PAYLOAD;
let devicePatchStatus = 200;

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
      if (url.includes("/devices/dev-1/payment-options") && init?.method === "PATCH") {
        if (devicePatchStatus === 409) {
          return Promise.reject(new ApiError(409, { code: "DEVICE_POLICY_WIDEN_FORBIDDEN" }));
        }
        return Promise.resolve({ data: { revision: 4, snapshot_hash: "hash-4", options: [] } });
      }
      if (url.includes("/devices/dev-1/payment-options")) {
        return Promise.resolve(DEVICE_EVALUATION);
      }
      if (url.includes("/payment-configuration")) {
        return Promise.resolve(redactPaymentSecrets(configResponse));
      }
      // Device registry (header name) and anything else the shell pulls.
      return Promise.resolve({
        data: [{ id: "dev-1", name: "POS-1", type: "pos", status: "active" }],
      });
    }),
  };
});

import { AppProvider } from "@/providers/app-provider";
import PaymentsConnectionPage from "@/app/shop/[shopSlug]/settings/payments/connection/page";
import DevicePaymentPolicyPage from "@/app/shop/[shopSlug]/settings/payments/devices/[deviceId]/page";
import { PaymentsViewPanel } from "@/app/shop/[shopSlug]/settings/payments/components/payments-view-panel";

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <QueryClientProvider client={queryClient}>
      {/* `en` so the assertions below can be plain English regexes. */}
      <AppProvider defaultLocale="en">{children}</AppProvider>
    </QueryClientProvider>
  );
}

const SECRET_PATTERNS = [
  /sk_live/i,
  /sk_test/i,
  /pk_test/i,
  /cs_test/i,
  /api_key/i,
  /client_secret/i,
];

function assertNoSecrets(container: HTMLElement) {
  const text = container.textContent ?? "";
  for (const pattern of SECRET_PATTERNS) {
    expect(text).not.toMatch(pattern);
  }
}

beforeEach(() => {
  vi.clearAllMocks();
  configResponse = SECRET_PAYLOAD;
  devicePatchStatus = 200;
});

describe("Shop payment settings — secrets never in DOM (G12/F12)", () => {
  it("redacts secret fields from API payloads before UI render", async () => {
    render(<PaymentsConnectionPage />, { wrapper });
    await waitFor(() => {
      expect(screen.getByText(/acct_\*\*\*1234/)).toBeTruthy();
    });
    assertNoSecrets(document.body);
  });

  it("strips the secret keys from the parsed payload itself, not just the render", () => {
    const redacted = redactPaymentSecrets(SECRET_PAYLOAD) as unknown as {
      data: { connections: Array<Record<string, unknown>> };
    };
    const connection = redacted.data.connections[0];
    expect(connection).not.toHaveProperty("secret");
    expect(connection).not.toHaveProperty("api_key");
    expect(connection).not.toHaveProperty("client_secret");
    // Non-secret fields survive untouched.
    expect(connection.merchant_account_id).toBe("acct_***1234");
  });
});

describe("Shop payment settings — G7 HQ-managed connection is disclosed read-only", () => {
  it("shows the HQ contract scope and no rotate control", async () => {
    render(<PaymentsConnectionPage />, { wrapper });
    await waitFor(() => {
      expect(screen.getByText(/HQ contract/i)).toBeTruthy();
    });
    // Rotating HQ credentials is not a shop-level capability.
    expect(screen.queryByText(/Rotate credentials/i)).toBeNull();
    assertNoSecrets(document.body);
  });
});

describe("Shop payment settings — G8 franchise missing connection", () => {
  it("shows the setup prerequisite immediately after load, not a perpetual spinner", async () => {
    configResponse = FRANCHISE_NO_CONNECTION;
    render(<PaymentsConnectionPage />, { wrapper });

    await waitFor(() => {
      expect(screen.queryByTestId(PAYMENTS_STATE_TEST_IDS.loading)).toBeNull();
    });

    expect(screen.getByText(/No payment gateway is connected yet/i)).toBeTruthy();
  });

  it("distinguishes 'unresolved ownership' from 'no connections' (fail-closed copy)", async () => {
    configResponse = UNRESOLVED_OWNERSHIP;
    render(<PaymentsConnectionPage />, { wrapper });

    await waitFor(() => {
      expect(screen.getByText(/not disclosed while ownership is unresolved/i)).toBeTruthy();
    });
    // "No active connection." would be a claim the backend never made.
    expect(screen.queryByText(/^No active connection\.$/)).toBeNull();
  });
});

describe("Shop payment settings — G12 mutually exclusive states", () => {
  it("PaymentsViewPanel renders only one primary state test id", () => {
    const { rerender, container } = render(
      <PaymentsViewPanel
        state={resolvePaymentsViewState({ isLoading: true, isError: false, hasData: false })}
      />,
      { wrapper }
    );
    expect(container.querySelectorAll("[data-testid^='payments-state-']").length).toBe(1);

    rerender(
      <PaymentsViewPanel
        state={resolvePaymentsViewState({
          isLoading: false,
          isError: true,
          error: new (class extends Error {})(),
          hasData: false,
        })}
        onRetry={() => undefined}
      />
    );
    expect(container.querySelectorAll("[data-testid^='payments-state-']").length).toBe(1);
  });

  it("error state does not render empty state simultaneously", () => {
    const { container } = render(
      <PaymentsViewPanel state={{ kind: "error", statusCode: 500 }} onRetry={() => undefined} />,
      { wrapper }
    );
    expect(
      container.querySelector(`[data-testid="${PAYMENTS_STATE_TEST_IDS.error}"]`)
    ).toBeTruthy();
    expect(container.querySelector(`[data-testid="${PAYMENTS_STATE_TEST_IDS.empty}"]`)).toBeNull();
  });
});

describe("Shop payment settings — device policy widen 409 (G10/G11)", () => {
  it("surfaces the widen-conflict toast when the device PATCH returns 409", async () => {
    devicePatchStatus = 409;
    render(<DevicePaymentPolicyPage />, { wrapper });

    const toggle = await screen.findByRole("switch", { name: /Disable on this device/i });
    fireEvent.click(toggle);

    await waitFor(() => {
      expect(toast.error).toHaveBeenCalled();
    });

    const message = String(vi.mocked(toast.error).mock.calls.at(-1)?.[0]);
    expect(message).toMatch(/cannot enable an option disabled at shop level/i);
  });

  it("shows the success toast when the device PATCH is accepted", async () => {
    render(<DevicePaymentPolicyPage />, { wrapper });

    const toggle = await screen.findByRole("switch", { name: /Disable on this device/i });
    fireEvent.click(toggle);

    await waitFor(() => {
      expect(toast.success).toHaveBeenCalled();
    });
    expect(toast.error).not.toHaveBeenCalled();
  });
});
