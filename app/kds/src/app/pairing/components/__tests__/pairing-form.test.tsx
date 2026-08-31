import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { MemoryRouter } from "react-router-dom";
import { AuthProvider } from "@/providers/AuthProvider";
import { I18nProvider } from "@/i18n";
import PairingForm from "../pairing-form";

beforeEach(() => {
  localStorage.clear();
  vi.restoreAllMocks();
});

function wrap(node: React.ReactNode) {
  return (
    <I18nProvider>
      <AuthProvider>
        <MemoryRouter>{node}</MemoryRouter>
      </AuthProvider>
    </I18nProvider>
  );
}

describe("PairingForm", () => {
  it("disables submit until 6 chars entered", async () => {
    render(wrap(<PairingForm />));
    const btn = await screen.findByRole("button", { name: /ペアリング|Ghép|Pair/i });
    expect(btn).toBeDisabled();

    await userEvent.type(screen.getByPlaceholderText(/A3BK9X/), "ABC");
    expect(btn).toBeDisabled();

    await userEvent.type(screen.getByPlaceholderText(/A3BK9X/), "DEF");
    expect(btn).not.toBeDisabled();
  });

  it("submits + saves token on success", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: async () => ({
        device_token: "new-token-xyz",
        device: {
          id: "d-new",
          name: "KDS-New",
          type: "kds",
          branch_id: "b-1",
          branch: { id: "b-1", name: "Branch A" },
        },
      }),
    });

    render(wrap(<PairingForm />));
    await userEvent.type(screen.getByPlaceholderText(/A3BK9X/), "ABCDEF");
    await userEvent.click(screen.getByRole("button", { name: /ペアリング|Ghép|Pair/i }));

    await waitFor(() => {
      expect(localStorage.getItem("kds_device_token")).toBe("new-token-xyz");
    });
  });

  it("#935: sends expected_type=kds so the backend gates the code by device type", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: async () => ({
        device_token: "tok",
        device: { id: "d1", name: "KDS", type: "kds", branch_id: "b-1" },
      }),
    });

    render(wrap(<PairingForm />));
    await userEvent.type(screen.getByPlaceholderText(/A3BK9X/), "ABCDEF");
    await userEvent.click(screen.getByRole("button", { name: /ペアリング|Ghép|Pair/i }));

    await waitFor(() => {
      const [, init] = vi.mocked(global.fetch).mock.calls[0] as [string, RequestInit];
      expect(JSON.parse(String(init.body)).expected_type).toBe("kds");
    });
  });

  it("#935: refuses to store a token when an old backend hands back a non-kds device", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: async () => ({
        device_token: "tok-wrong",
        device: { id: "d1", name: "handy", type: "pos", branch_id: "b-1" },
      }),
    });

    render(wrap(<PairingForm />));
    await userEvent.type(screen.getByPlaceholderText(/A3BK9X/), "ABCDEF");
    await userEvent.click(screen.getByRole("button", { name: /ペアリング|Ghép|Pair/i }));

    await waitFor(() => {
      expect(screen.getByRole("alert")).toBeInTheDocument();
    });
    expect(localStorage.getItem("kds_device_token")).toBeNull();
  });

  it("#935: surfaces the backend's per-field type-mismatch message on 422", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 422,
      json: async () => ({
        message: "The given data was invalid.",
        errors: {
          pairing_code: ['This pairing code belongs to a "kiosk" device and cannot be used by this app.'],
        },
      }),
    });

    render(wrap(<PairingForm />));
    await userEvent.type(screen.getByPlaceholderText(/A3BK9X/), "ABCDEF");
    await userEvent.click(screen.getByRole("button", { name: /ペアリング|Ghép|Pair/i }));

    await waitFor(() => {
      expect(screen.getByRole("alert")).toHaveTextContent(/kiosk/);
    });
  });

  it("shows error when pair fails", async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 422,
      json: async () => ({ message: "Invalid code" }),
    });

    render(wrap(<PairingForm />));
    await userEvent.type(screen.getByPlaceholderText(/A3BK9X/), "BADCD1");
    await userEvent.click(screen.getByRole("button", { name: /ペアリング|Ghép|Pair/i }));

    await waitFor(() => {
      expect(screen.getByRole("alert")).toBeInTheDocument();
    });
  });
});
