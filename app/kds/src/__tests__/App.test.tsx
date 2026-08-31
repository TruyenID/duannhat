import { render, screen, waitFor } from "@testing-library/react";
import { describe, it, expect, beforeEach, vi } from "vitest";
import App from "../App";
import { setDeviceToken } from "@/services/auth/device-token";

beforeEach(() => {
  localStorage.clear();
  vi.restoreAllMocks();
  // Start at / so guards trigger redirect
  window.history.replaceState({}, "", "/");
});

describe("App router", () => {
  it("redirects to /pairing when unpaired", async () => {
    render(<App />);
    await waitFor(() => {
      expect(screen.getByLabelText(/ペアリング|Ghép|Pair/i)).toBeInTheDocument();
    });
  });

  it("renders dashboard when paired (token verified by /me)", async () => {
    setDeviceToken("tok-good", {
      id: "d-1",
      name: "KDS-1",
      type: "kds",
      branch_id: "b-1",
      branch_name: "Branch A",
    });
    global.fetch = vi.fn().mockImplementation((url: string) => {
      if (String(url).includes("/kds/orders")) {
        return Promise.resolve({
          ok: true,
          status: 200,
          json: async () => ({ data: [] }),
        });
      }
      return Promise.resolve({
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
    });
    render(<App />);
    await waitFor(() => {
      expect(screen.getByText("Branch A")).toBeInTheDocument();
    });
  });
});
