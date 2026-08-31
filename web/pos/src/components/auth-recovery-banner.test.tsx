import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, useLocation } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

/**
 * End-to-end wiring for the #2431 soft-auth path: apiFetch sees a 503
 * "auth verification unavailable" → AuthProvider's recovery handler → banner →
 * the cashier chooses. The point of the whole path is that the session is NOT
 * wiped behind their back, so both the "keep" and the "re-pair" exits are
 * pinned here. A banner that renders but is never armed (or a handler that is
 * never unregistered) would look identical in isolation.
 */
vi.mock("@/providers/app-provider", () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}));

const { AuthProvider } = await import("@/providers/auth-provider");
const { AuthRecoveryBanner } = await import("./auth-recovery-banner");
const { apiFetch } = await import("@/lib/api");
const { persistSession, getToken } = await import("@/lib/auth");
const { setMode } = await import("@/services/workstation/base-url-resolver");
const { resetAuthFailureStreak } = await import("@/lib/api");

const originalFetch = global.fetch;
const pairedDevice = { id: "d1", name: "POS 1", type: "pos", branch_id: "b1" };

function LocationProbe() {
  return <span data-testid="path">{useLocation().pathname}</span>;
}

function renderApp() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={["/pos"]}>
      <QueryClientProvider client={client}>
        <AuthProvider>
          <AuthRecoveryBanner />
          <LocationProbe />
        </AuthProvider>
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

function mockStatus(status: number, body: unknown) {
  global.fetch = vi.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  } as Response);
}

async function triggerAuthUnavailable() {
  mockStatus(503, { message: "auth verification unavailable" });
  await act(async () => {
    await apiFetch("/test").catch(() => {});
  });
}

beforeEach(() => {
  localStorage.clear();
  setMode("cloud");
  resetAuthFailureStreak();
  persistSession("live-token", pairedDevice);
});

afterEach(() => {
  global.fetch = originalFetch;
  localStorage.clear();
});

describe("AuthRecoveryBanner", () => {
  it("renders nothing while auth is healthy", () => {
    renderApp();
    expect(screen.queryByTestId("auth-recovery-banner")).not.toBeInTheDocument();
  });

  it("appears on a 503 auth-verification failure and keeps the session", async () => {
    renderApp();
    await triggerAuthUnavailable();

    const banner = await screen.findByTestId("auth-recovery-banner");
    expect(banner).toHaveTextContent("auth.recovery.title");
    // The non-destructive choice must come FIRST: this fires when Cloud is
    // unreachable, so the token is probably fine and re-pairing would relay to
    // the same dead Cloud and strand the till.
    const buttons = within(banner).getAllByRole("button");
    expect(buttons[0]).toHaveTextContent("auth.recovery.keep_working");
    expect(buttons[1]).toHaveTextContent("auth.recovery.repair");
    // The server's reason is shown verbatim — it is what an operator reports.
    expect(banner).toHaveTextContent("auth verification unavailable");
    expect(getToken()).toBe("live-token");
    expect(screen.getByTestId("path")).toHaveTextContent("/pos");
  });

  it("Re-pair (the SECONDARY action) wipes the session and routes to /pairing", async () => {
    renderApp();
    await triggerAuthUnavailable();
    await screen.findByTestId("auth-recovery-banner");

    fireEvent.click(screen.getByRole("button", { name: "auth.recovery.repair" }));

    await waitFor(() => expect(screen.getByTestId("path")).toHaveTextContent("/pairing"));
    expect(getToken()).toBeNull();
    expect(screen.queryByTestId("auth-recovery-banner")).not.toBeInTheDocument();
  });

  it("Keep working (the PRIMARY action) hides the banner and leaves the session intact", async () => {
    renderApp();
    await triggerAuthUnavailable();
    await screen.findByTestId("auth-recovery-banner");

    fireEvent.click(screen.getByRole("button", { name: "auth.recovery.keep_working" }));

    await waitFor(() =>
      expect(screen.queryByTestId("auth-recovery-banner")).not.toBeInTheDocument(),
    );
    expect(getToken()).toBe("live-token");
    expect(screen.getByTestId("path")).toHaveTextContent("/pos");
  });

  it("comes back if the next request fails the same way after a dismiss", async () => {
    renderApp();
    await triggerAuthUnavailable();
    fireEvent.click(await screen.findByRole("button", { name: "auth.recovery.keep_working" }));
    await waitFor(() =>
      expect(screen.queryByTestId("auth-recovery-banner")).not.toBeInTheDocument(),
    );

    await triggerAuthUnavailable();
    expect(await screen.findByTestId("auth-recovery-banner")).toBeInTheDocument();
  });

  it("stays away for 401s — that path uses the #487 streak, not a banner", async () => {
    renderApp();
    mockStatus(401, { message: "invalid token" });

    // First two 401s: nothing happens at all. The workstation says
    // "invalid token" for any Cloud blip, so one is not evidence of anything.
    await act(async () => {
      await apiFetch("/test").catch(() => {});
      await apiFetch("/test").catch(() => {});
    });
    expect(screen.queryByTestId("auth-recovery-banner")).not.toBeInTheDocument();
    expect(getToken()).toBe("live-token");
    expect(screen.getByTestId("path")).toHaveTextContent("/pos");

    // Third crosses the threshold → logout, still no banner.
    await act(async () => {
      await apiFetch("/test").catch(() => {});
    });
    await waitFor(() => expect(screen.getByTestId("path")).toHaveTextContent("/pairing"));
    expect(screen.queryByTestId("auth-recovery-banner")).not.toBeInTheDocument();
    expect(getToken()).toBeNull();
  });

  it("unregisters its handler on unmount so a late 503 cannot setState on a dead tree", async () => {
    const { unmount } = renderApp();
    unmount();

    const errors: unknown[] = [];
    const spy = vi.spyOn(console, "error").mockImplementation((...args) => {
      errors.push(args);
    });
    await triggerAuthUnavailable();
    spy.mockRestore();

    expect(errors).toEqual([]);
  });
});
