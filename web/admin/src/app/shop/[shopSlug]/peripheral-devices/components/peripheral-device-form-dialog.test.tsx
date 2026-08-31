/**
 * PeripheralDeviceFormDialog — the LAN-address registration form.
 *
 * Covers the behaviour that makes the P400 / 釣銭機 config work end to end:
 * network types expose host/port, submit builds a `metadata` payload with a
 * numeric port, and a backend 422 on `metadata.host` surfaces under the field.
 */
import { describe, it, expect, vi, beforeEach } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import { ApiError } from "@/lib/api";
import { PeripheralDeviceFormDialog } from "./peripheral-device-form-dialog";

const testQueryClient = new QueryClient();

function Wrapper({ children }: { children: ReactNode }) {
  return (
    <QueryClientProvider client={testQueryClient}>
      <AppProvider>{children}</AppProvider>
    </QueryClientProvider>
  );
}

function renderDialog(onSubmit = vi.fn(() => Promise.resolve())) {
  render(
    <PeripheralDeviceFormDialog shopSlug="test-shop" open onOpenChange={vi.fn()} onSubmit={onSubmit} />,
    { wrapper: Wrapper }
  );
  return { onSubmit };
}

function saveButton(): HTMLButtonElement {
  return screen.getByRole("button", { name: "保存" }) as HTMLButtonElement;
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("PeripheralDeviceFormDialog — network device (P400) registration", () => {
  it("shows host + port fields for the default payment_terminal type", () => {
    renderDialog();
    expect(screen.getByPlaceholderText("192.168.0.77")).toBeInTheDocument();
    expect(screen.getByPlaceholderText("8888")).toBeInTheDocument();
  });

  it("submits a metadata payload with a numeric port", async () => {
    const { onSubmit } = renderDialog();

    fireEvent.change(screen.getByPlaceholderText("192.168.0.77"), {
      target: { value: "192.168.0.77" },
    });
    fireEvent.change(screen.getByPlaceholderText("8888"), { target: { value: "8888" } });
    fireEvent.change(screen.getByPlaceholderText("例：レジ横 P400"), {
      target: { value: "Counter P400" },
    });

    fireEvent.click(saveButton());

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit).toHaveBeenCalledWith({
      name: "Counter P400",
      type: "payment_terminal",
      is_active: true,
      metadata: { host: "192.168.0.77", port: 8888 },
    });
  });

  it("omits port from metadata when the port field is left blank", async () => {
    const { onSubmit } = renderDialog();

    fireEvent.change(screen.getByPlaceholderText("192.168.0.77"), {
      target: { value: "192.168.0.10" },
    });
    fireEvent.click(saveButton());

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    const payload = (onSubmit.mock.calls[0] as unknown[])[0] as {
      metadata: { host: string; port?: number };
    };
    expect(payload.metadata).toEqual({ host: "192.168.0.10" });
    expect(payload.metadata.port).toBeUndefined();
  });

  it("maps a backend 422 on metadata.host onto the host field", async () => {
    const onSubmit = vi.fn(() =>
      Promise.reject(
        new ApiError(422, { errors: { "metadata.host": ["The host field is required."] } })
      )
    );
    renderDialog(onSubmit);

    fireEvent.click(saveButton());

    await waitFor(() =>
      expect(screen.getByText("The host field is required.")).toBeInTheDocument()
    );
  });
});
