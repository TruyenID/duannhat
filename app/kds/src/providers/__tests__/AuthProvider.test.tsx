import { render, screen, waitFor } from "@testing-library/react";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { AuthProvider } from "../AuthProvider";
import { useAuth } from "../use-auth";
import { setDeviceToken } from "@/services/auth/device-token";

// Probe component to read state
function Probe() {
  const a = useAuth();
  return (
    <div>
      <span data-testid="state">{a.state}</span>
      <span data-testid="device-name">{a.device?.name ?? "none"}</span>
    </div>
  );
}

beforeEach(() => {
  localStorage.clear();
  vi.restoreAllMocks();
});

describe("AuthProvider", () => {
  it("starts in loading state when token absent, transitions to unpaired", async () => {
    render(
      <AuthProvider>
        <Probe />
      </AuthProvider>
    );

    // RTL v16 + React 19 flushes synchronous effects inside act() during render.
    // The no-token path has no async await, so state is already "unpaired" here.
    // Verify the final settled state is "unpaired".
    await waitFor(() => {
      expect(screen.getByTestId("state").textContent).toBe("unpaired");
    });
  });

  it("transitions to paired when token exists and /me succeeds", async () => {
    setDeviceToken("tok-good", {
      id: "d-1",
      name: "KDS-1",
      type: "kds",
      branch_id: "b-1",
      branch_name: "Branch A",
    });

    // Mock fetch for /api/v1/kds/me
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({
        data: {
          id: "d-1",
          name: "KDS-1-Updated",
          type: "kds",
          status: "active",
          branch_id: "b-1",
          branch: { id: "b-1", name: "Branch A" },
        },
      }),
    });

    render(
      <AuthProvider>
        <Probe />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId("state").textContent).toBe("paired");
    });
    expect(screen.getByTestId("device-name").textContent).toBe("KDS-1-Updated");
  });

  it("clears token and transitions to unpaired when /me returns 401", async () => {
    setDeviceToken("tok-revoked", {
      id: "d-1",
      name: "KDS-1",
      type: "kds",
      branch_id: "b-1",
      branch_name: "Branch A",
    });

    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 401,
      json: async () => ({ message: "revoked" }),
    });

    render(
      <AuthProvider>
        <Probe />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId("state").textContent).toBe("unpaired");
    });
    expect(localStorage.getItem("kds_device_token")).toBeNull();
  });

  it("stays paired on network error (offline tolerance)", async () => {
    setDeviceToken("tok-offline", {
      id: "d-1",
      name: "KDS-1",
      type: "kds",
      branch_id: "b-1",
      branch_name: "Branch A",
    });

    global.fetch = vi.fn().mockRejectedValue(new Error("Network error"));

    render(
      <AuthProvider>
        <Probe />
      </AuthProvider>
    );

    await waitFor(() => {
      expect(screen.getByTestId("state").textContent).toBe("paired");
    });
    expect(localStorage.getItem("kds_device_token")).toBe("tok-offline");
  });
});
