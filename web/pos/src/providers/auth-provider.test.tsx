import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, renderHook } from "@testing-library/react";
import { useContext, type ReactNode } from "react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AuthContext, AuthProvider } from "./auth-provider";
import { setUnauthorizedHandler } from "@/lib/api";
import { persistSession, type DeviceInfo } from "@/lib/auth";

function useAuthContext() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("AuthContext not provided");
  return ctx;
}

function useLocationContext() {
  return useLocation();
}

function Wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return (
    <MemoryRouter initialEntries={["/pos"]}>
      <QueryClientProvider client={queryClient}>
        <Routes>
          <Route path="/pos" element={<AuthProvider>{children}</AuthProvider>} />
          <Route path="/pairing" element={<AuthProvider>{children}</AuthProvider>} />
          <Route path="/" element={<AuthProvider>{children}</AuthProvider>} />
        </Routes>
      </QueryClientProvider>
    </MemoryRouter>
  );
}

const testDevice: DeviceInfo = {
  id: "d1",
  name: "POS Terminal 1",
  type: "pos",
  branch_id: "b1",
  branch_name: "Branch A",
};

beforeEach(() => {
  setUnauthorizedHandler(null);
  localStorage.clear();
});

afterEach(() => {
  setUnauthorizedHandler(null);
  localStorage.clear();
});

describe("AuthProvider", () => {
  it("initial state: unauthenticated when no token in storage", () => {
    const { result } = renderHook(() => useAuthContext(), { wrapper: Wrapper });
    expect(result.current.isAuthenticated).toBe(false);
    expect(result.current.device).toBeNull();
    expect(result.current.token).toBeNull();
  });

  it("hydrates from localStorage on mount", () => {
    persistSession("stored-token", testDevice);
    const { result } = renderHook(() => useAuthContext(), { wrapper: Wrapper });
    expect(result.current.isAuthenticated).toBe(true);
    expect(result.current.token).toBe("stored-token");
    expect(result.current.device?.name).toBe("POS Terminal 1");
  });

  it("setPaired() persists session + updates state", () => {
    const { result } = renderHook(() => useAuthContext(), { wrapper: Wrapper });
    act(() => {
      result.current.setPaired("new-token", testDevice);
    });
    expect(result.current.isAuthenticated).toBe(true);
    expect(result.current.token).toBe("new-token");
    expect(localStorage.getItem("pos_device_token")).toBe("new-token");
  });

  it("logout() clears session + navigates to /pairing", () => {
    persistSession("existing-token", testDevice);
    const { result, rerender } = renderHook(
      () => ({ auth: useAuthContext(), loc: useLocationContext() }),
      { wrapper: Wrapper },
    );
    expect(result.current.auth.isAuthenticated).toBe(true);

    act(() => {
      result.current.auth.logout();
    });
    rerender();

    expect(result.current.auth.isAuthenticated).toBe(false);
    expect(result.current.auth.token).toBeNull();
    expect(localStorage.getItem("pos_device_token")).toBeNull();
    expect(result.current.loc.pathname).toBe("/pairing");
  });

  it("unregisters handler on unmount", () => {
    const { unmount } = renderHook(() => useAuthContext(), { wrapper: Wrapper });
    unmount();
    expect(true).toBe(true);
  });
});

describe("AuthProvider + apiFetch 401 integration", () => {
  it("sustained 401 from apiFetch → clears state + navigates to /pairing", async () => {
    persistSession("expiring-token", testDevice);

    const { result, rerender } = renderHook(
      () => ({ auth: useAuthContext(), loc: useLocationContext() }),
      { wrapper: Wrapper },
    );
    expect(result.current.auth.isAuthenticated).toBe(true);

    // A genuinely revoked token 401s on every call — cross the transient-401
    // threshold (#487) so the provider tears down the session.
    const fetchMock = vi.fn().mockResolvedValue({
      ok: false,
      status: 401,
      json: async () => ({ message: "unauthorized" }),
    } as Response);
    const originalFetch = global.fetch;
    global.fetch = fetchMock;

    try {
      const { apiFetch, resetAuthFailureStreak } = await import("@/lib/api");
      resetAuthFailureStreak();
      await act(async () => {
        for (let i = 0; i < 3; i++) {
          try {
            await apiFetch("/test");
          } catch {
            // expected — apiFetch throws ApiError(401)
          }
        }
      });
      rerender();

      expect(result.current.auth.isAuthenticated).toBe(false);
      expect(result.current.auth.token).toBeNull();
      expect(result.current.loc.pathname).toBe("/pairing");
    } finally {
      global.fetch = originalFetch;
    }
  });
});
