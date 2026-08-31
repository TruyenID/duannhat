import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { useMe } from "../use-me";
import { setDeviceToken } from "@/services/auth/device-token";
import { AuthProvider } from "@/providers/AuthProvider";

beforeEach(() => {
  localStorage.clear();
  vi.restoreAllMocks();
});

function wrap(qc: QueryClient) {
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <AuthProvider>
        <QueryClientProvider client={qc}>{children}</QueryClientProvider>
      </AuthProvider>
    );
  };
}

describe("useMe", () => {
  it("returns device info when paired and /me succeeds", async () => {
    setDeviceToken("tok-1", {
      id: "d-1",
      name: "KDS-1",
      type: "kds",
      branch_id: "b-1",
      branch_name: "Branch A",
    });
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({
        data: {
          id: "d-1",
          name: "KDS-1",
          type: "kds",
          status: "active",
          branch_id: "b-1",
          branch: { id: "b-1", name: "Branch A" },
        },
      }),
    });

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useMe(), { wrapper: wrap(qc) });

    await waitFor(() => {
      expect(result.current.data?.name).toBe("KDS-1");
    });
  });

  it("is disabled when unpaired", async () => {
    // No token set
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useMe(), { wrapper: wrap(qc) });

    // AuthProvider boot sets state="unpaired" → query disabled → no fetch call
    await waitFor(() => {
      expect(result.current.fetchStatus).toBe("idle");
    });
    // No mock — no fetch should have been called
  });
});
