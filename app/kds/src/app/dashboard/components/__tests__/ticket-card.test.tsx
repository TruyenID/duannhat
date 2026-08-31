import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { TicketCard } from "../ticket-card";
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

describe("TicketCard", () => {
  it("renders ticket with table number + items", () => {
    const order = makeOrder({
      id: "o-1",
      opened_at: new Date(Date.now() - 3 * 60_000).toISOString(),
      items: [
        makeItem({ id: "i-1", menu_item_name: "Phở Bò", quantity: 2, status: "pending" }),
        makeItem({ id: "i-2", menu_item_name: "Cơm Gà", quantity: 1, status: "preparing" }),
      ],
    });
    render(wrap(<TicketCard order={order} />));
    expect(screen.getByText(/A3/)).toBeInTheDocument();
    expect(screen.getByText(/#042/)).toBeInTheDocument();
    expect(screen.getByText(/Phở Bò/)).toBeInTheDocument();
    expect(screen.getByText(/Cơm Gà/)).toBeInTheDocument();
  });

  // Border color now reads `order.priority` directly (server-derived). The
  // fixture derives priority from opened_at by default, but the test passes
  // an EXPLICIT priority so a future change to that derivation can't make
  // these assertions silently lie about what the component renders.
  it("shows green border when priority=normal", () => {
    const order = makeOrder({ id: "o-x", order_code: "x", priority: "normal" });
    render(wrap(<TicketCard order={order} />));
    expect(screen.getByTestId("ticket-o-x").className).toContain("border-green-500");
  });

  it("shows amber border when priority=warning", () => {
    const order = makeOrder({ id: "o-a", order_code: "a", priority: "warning" });
    render(wrap(<TicketCard order={order} />));
    expect(screen.getByTestId("ticket-o-a").className).toContain("border-amber-500");
  });

  it("shows red border + pulse when priority=critical", () => {
    const order = makeOrder({ id: "o-old", order_code: "old", priority: "critical" });
    render(wrap(<TicketCard order={order} />));
    const card = screen.getByTestId("ticket-o-old");
    expect(card.className).toContain("border-red-500");
    expect(card.className).toContain("animate-pulse");
  });

  it("ignores opened_at for color — only priority drives it", () => {
    // Set opened_at to a "would-be-critical" timestamp but force priority=normal.
    // If the component fell back to client compute, it'd render red; with the
    // server-priority migration it must render green.
    const order = makeOrder({
      id: "o-mix",
      order_code: "mix",
      opened_at: new Date(Date.now() - 30 * 60_000).toISOString(),
      priority: "normal",
    });
    render(wrap(<TicketCard order={order} />));
    expect(screen.getByTestId("ticket-o-mix").className).toContain("border-green-500");
  });

  it("renders bump-all button when there are pending items", () => {
    const order = makeOrder({
      id: "o-b",
      order_code: "b",
      items: [makeItem({ status: "pending" })],
    });
    render(wrap(<TicketCard order={order} />));
    expect(
      screen.getByRole("button", { name: /(Chuyển tất cả|全て|Move all)/i }),
    ).toBeInTheDocument();
  });

  it("hides bump-all button when no pending items", () => {
    const order = makeOrder({
      id: "o-r",
      order_code: "r",
      items: [makeItem({ status: "ready" })],
    });
    render(wrap(<TicketCard order={order} />));
    expect(
      screen.queryByRole("button", { name: /(Chuyển tất cả|全て|Move all)/i }),
    ).toBeNull();
  });
});
