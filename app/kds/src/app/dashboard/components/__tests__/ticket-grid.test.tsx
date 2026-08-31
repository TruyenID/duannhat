import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { TicketGrid } from "../ticket-grid";
import { I18nProvider } from "@/i18n";
import { AuthProvider } from "@/providers/AuthProvider";
import { RealtimeProvider } from "@/providers/RealtimeProvider";
import { makeOrder, makeItem } from "@/test/fixtures/kds";

vi.mock("@/services/realtime/dispatcher", () => ({
  createRealtimeDispatcher: vi.fn(() => ({
    on: vi.fn(() => () => {}),
    connect: vi.fn(),
    close: vi.fn(),
  })),
}));

beforeEach(() => {
  localStorage.clear();
  vi.clearAllMocks();
});

function wrap(children: React.ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <I18nProvider>
      <AuthProvider>
        <QueryClientProvider client={qc}>
          <RealtimeProvider>{children}</RealtimeProvider>
        </QueryClientProvider>
      </AuthProvider>
    </I18nProvider>
  );
}

describe("TicketGrid", () => {
  it("shows empty state when no orders", () => {
    render(wrap(<TicketGrid orders={[]} />));
    expect(screen.getByText(/(Không có|調理中|No orders)/i)).toBeInTheDocument();
  });

  it("renders multiple tickets", () => {
    const orders = [
      makeOrder({
        id: "o-1",
        order_code: "001",
        items: [makeItem({ id: "i1", menu_item_name: "A", status: "pending" })],
      }),
      makeOrder({
        id: "o-2",
        order_code: "002",
        items: [makeItem({ id: "i2", menu_item_name: "B", status: "preparing" })],
      }),
    ];
    render(wrap(<TicketGrid orders={orders} />));
    expect(screen.getByTestId("ticket-o-1")).toBeInTheDocument();
    expect(screen.getByTestId("ticket-o-2")).toBeInTheDocument();
  });
});
