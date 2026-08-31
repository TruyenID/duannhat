/**
 * SettingsPage — 精算 close-report section toggles.
 *
 * Guards the two things that make this screen correct:
 *   1. Completeness — a Switch renders for EVERY canonical toggle key, so a
 *      section can never be silently omitted (the reason this screen exists).
 *   2. Wiring — flipping a switch PATCHes exactly that one key with the new
 *      value through the scoped mutation.
 * Plus an i18n-completeness check so a future toggle can't ship without its
 * label + description in every locale.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { AppProvider } from "@/providers/app-provider";
import { CLOSE_REPORT_TOGGLE_KEYS } from "@/services/shop-order-settings-service";
import viMessages from "@/i18n/vi.json";
import jaMessages from "@/i18n/ja.json";
import enMessages from "@/i18n/en.json";

// Shared, hoisted mock state so vi.mock factories (hoisted above imports) can
// reference it safely.
const h = vi.hoisted(() => ({
  mutate: vi.fn(),
  query: {
    data: {
      data: {
        close_report_payment_methods: true,
        close_report_service_charge: true,
        close_report_denominations: true,
        close_report_drawer_check: true,
        close_report_tax_breakdown: true,
      },
    },
    isLoading: false,
    isError: false,
    refetch: vi.fn(),
  },
}));

// PosHeader pulls in till polling + clock timers irrelevant to this screen.
vi.mock("@/app/pos/components/pos-header", () => ({
  PosHeader: () => null,
}));

vi.mock("@/providers/use-auth", () => ({
  useAuth: () => ({ device: { branch_name: "SJK" } }),
}));

// The version card (#2632) reads LAN state. This screen is rendered here
// without the real provider tree, and the card's own behaviour is covered by
// version-card.test.tsx.
vi.mock("@/providers/workstation-provider", () => ({
  useWorkstation: () => ({
    versions: { workstation: null, posBundle: null },
    updateHint: { available: false, expectedVersion: null },
    mode: "workstation",
    status: "lan-active",
    testConnection: vi.fn(),
  }),
}));

vi.mock("@/hooks/api/use-shop-order-settings", () => ({
  useShopOrderSettings: () => h.query,
  useUpdateCloseReportToggles: () => ({ mutate: h.mutate }),
}));

import { SettingsPage } from "./page";

function renderPage() {
  return render(
    <AppProvider>
      <MemoryRouter initialEntries={["/shop/sjk/settings"]}>
        <Routes>
          <Route path="/shop/:shopSlug/settings" element={<SettingsPage />} />
          <Route path="/shop/:shopSlug" element={<div>POS_HOME</div>} />
        </Routes>
      </MemoryRouter>
    </AppProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("pos_locale", "en"); // deterministic string assertions
});

afterEach(() => {
  vi.clearAllMocks();
  // restoreAllMocks un-installs vi.spyOn spies (e.g. the sonner toast.error
  // spy in the 403 test below) — clearAllMocks alone keeps the stub
  // implementation active and would leak into later tests using the real
  // toast (round-4 audit B-II.7).
  vi.restoreAllMocks();
});

describe("SettingsPage — close-report toggles", () => {
  it("renders a switch for EVERY canonical toggle key (no section missed)", () => {
    renderPage();
    expect(screen.getAllByRole("switch")).toHaveLength(
      CLOSE_REPORT_TOGGLE_KEYS.length,
    );
  });

  it("labels each of the five sections", () => {
    renderPage();
    for (const label of [
      "Payment methods",
      "Service charge",
      "Denominations",
      "Drawer check",
      "Per-rate tax breakdown",
    ]) {
      expect(screen.getByText(label)).toBeInTheDocument();
    }
  });

  it("navigates back to the POS home when 'Back to POS' is clicked", () => {
    renderPage();
    expect(screen.queryByText("POS_HOME")).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Back to POS" }));
    expect(screen.getByText("POS_HOME")).toBeInTheDocument();
  });

  it("moves the switch immediately on click (does not wait on the round-trip)", () => {
    renderPage();
    // Regression for "toast shows but toggle doesn't move": the switch is
    // driven by local state, so a click flips aria-checked synchronously even
    // though the mocked mutation never resolves.
    const sw = screen.getByRole("switch", { name: "Denominations" });
    expect(sw).toHaveAttribute("aria-checked", "true");
    fireEvent.click(sw);
    expect(sw).toHaveAttribute("aria-checked", "false");
    fireEvent.click(sw);
    expect(sw).toHaveAttribute("aria-checked", "true");
  });

  it("PATCHes exactly the flipped key with its new value", () => {
    renderPage();
    // Every switch starts ON (mock data all true); flipping sends `false`.
    fireEvent.click(
      screen.getByRole("switch", { name: "Per-rate tax breakdown" }),
    );
    expect(h.mutate).toHaveBeenCalledTimes(1);
    expect(h.mutate.mock.calls[0][0]).toEqual({
      close_report_tax_breakdown: false,
    });

    fireEvent.click(screen.getByRole("switch", { name: "Payment methods" }));
    expect(h.mutate).toHaveBeenCalledTimes(2);
    expect(h.mutate.mock.calls[1][0]).toEqual({
      close_report_payment_methods: false,
    });
  });
});

describe("close-report settings i18n completeness", () => {
  const byLocale: Record<string, Record<string, string>> = {
    vi: viMessages as Record<string, string>,
    ja: jaMessages as Record<string, string>,
    en: enMessages as Record<string, string>,
  };

  it.each(["vi", "ja", "en"])(
    "%s has a label + description for every toggle key",
    (loc) => {
      const msgs = byLocale[loc];
      for (const key of CLOSE_REPORT_TOGGLE_KEYS) {
        expect(msgs[`settings.close_report.${key}`]).toBeTruthy();
        expect(msgs[`settings.close_report.${key}_desc`]).toBeTruthy();
      }
    },
  );
});

describe("tax-breakdown toggle 403 (plan-043 audit fix 1.5/A1)", () => {
  it("maps TAX_BREAKDOWN_TOGGLE_FORBIDDEN to the manager-only message and rolls the switch back", async () => {
    const { toast } = await import("sonner");
    const { ApiError } = await import("@/lib/api");
    const errorSpy = vi.spyOn(toast, "error").mockImplementation(() => "");

    // Backend rejects the anonymous device: fire onError + onSettled like the
    // real mutation would.
    h.mutate.mockImplementation((_body, opts) => {
      opts?.onError?.(
        new ApiError(403, {
          code: "TAX_BREAKDOWN_TOGGLE_FORBIDDEN",
          message: "Only a signed-in user can change the tax-breakdown print toggle.",
        }),
      );
      opts?.onSettled?.();
    });

    renderPage();
    const taxSwitch = screen.getByRole("switch", {
      name: (enMessages as Record<string, string>)[
        "settings.close_report.close_report_tax_breakdown"
      ],
    });
    fireEvent.click(taxSwitch);

    // The dedicated manager-only message — not the raw backend string.
    expect(errorSpy).toHaveBeenCalledWith(
      (enMessages as Record<string, string>)[
        "settings.close_report.tax_breakdown_forbidden"
      ],
    );
    // onSettled cleared the optimistic override → server value (ON) wins again.
    expect(taxSwitch).toHaveAttribute("aria-checked", "true");
  });

  it.each(["vi", "ja", "en"])("%s has the manager-only message", (loc) => {
    const msgs = { vi: viMessages, ja: jaMessages, en: enMessages }[loc] as Record<string, string>;
    expect(msgs["settings.close_report.tax_breakdown_forbidden"]).toBeTruthy();
  });
});
