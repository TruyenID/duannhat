import { render, screen, fireEvent } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { ItemRow } from "../item-row";
import { I18nProvider } from "@/i18n";
import { setDeviceToken } from "@/services/auth/device-token";
import { makeItem } from "@/test/fixtures/kds";
import type { ItemTransition } from "@/services/kds/orders";

const { markPreparingMutate, markReadyMutate, markServedMutate, revertMutate } = vi.hoisted(() => ({
  markPreparingMutate: vi.fn(),
  markReadyMutate: vi.fn(),
  markServedMutate: vi.fn(),
  revertMutate: vi.fn(),
}));

vi.mock("@/services/realtime/dispatcher", () => ({
  createRealtimeDispatcher: vi.fn(() => ({
    on: vi.fn(() => () => {}),
    connect: vi.fn(),
    close: vi.fn(),
  })),
}));

vi.mock("@/providers/use-realtime", () => ({
  useRealtime: () => ({ recordBumpKey: vi.fn(), isConnected: false }),
}));

vi.mock("@/hooks/use-mark-preparing", () => ({
  useMarkPreparing: () => ({ mutate: markPreparingMutate, isPending: false }),
}));
vi.mock("@/hooks/use-mark-ready", () => ({
  useMarkReady: () => ({ mutate: markReadyMutate, isPending: false }),
}));
vi.mock("@/hooks/use-mark-served", () => ({
  useMarkServed: () => ({ mutate: markServedMutate, isPending: false }),
}));
vi.mock("@/hooks/use-revert-item", () => ({
  useRevertItem: () => ({ mutate: revertMutate, isPending: false }),
}));

beforeEach(() => {
  localStorage.clear();
  vi.clearAllMocks();
  setDeviceToken("tok", {
    id: "d-1",
    name: "KDS-1",
    type: "kds",
    status: "active",
    branch_id: "b-1",
  });
});

function wrap(children: React.ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <I18nProvider>
      <QueryClientProvider client={qc}>{children}</QueryClientProvider>
    </I18nProvider>
  );
}

describe("ItemRow forward action by allowed_transitions", () => {
  it("pending: clicking forward fires markPreparing only", () => {
    const item = makeItem({
      id: "i-1",
      status: "pending",
      allowed_transitions: ["mark-preparing"],
    });
    render(wrap(<ItemRow orderId="o-1" item={item} />));
    expect(screen.queryByTestId("item-i-1-revert")).toBeNull();
    fireEvent.click(screen.getByTestId("item-i-1-forward"));
    expect(markPreparingMutate).toHaveBeenCalledWith({
      orderId: "o-1",
      itemId: "i-1",
    });
    expect(markReadyMutate).not.toHaveBeenCalled();
    expect(markServedMutate).not.toHaveBeenCalled();
  });

  it("preparing: forward fires markReady, revert button is visible", () => {
    const item = makeItem({
      id: "i-2",
      status: "preparing",
      allowed_transitions: ["mark-ready", "revert"],
    });
    render(wrap(<ItemRow orderId="o-1" item={item} />));
    expect(screen.getByTestId("item-i-2-revert")).toBeInTheDocument();
    fireEvent.click(screen.getByTestId("item-i-2-forward"));
    expect(markReadyMutate).toHaveBeenCalledWith({ orderId: "o-1", itemId: "i-2" });
  });

  it("ready: forward button disabled (mark-served is waiter domain), revert visible", () => {
    // KDS only handles preparing/ready — mark-served is waiter's action.
    // A ready item shows no kitchen forward button (disabled), only revert.
    const item = makeItem({
      id: "i-3",
      status: "ready",
      allowed_transitions: ["mark-served", "revert"],
    });
    render(wrap(<ItemRow orderId="o-1" item={item} />));
    expect(screen.getByTestId("item-i-3-revert")).toBeInTheDocument();
    expect((screen.getByTestId("item-i-3-forward") as HTMLButtonElement).disabled).toBe(true);
  });

  it("served: ItemRow renders nothing (waiter domain, not kitchen)", () => {
    const item = makeItem({
      id: "i-4",
      status: "served",
      allowed_transitions: ["revert"],
    });
    const { container } = render(wrap(<ItemRow orderId="o-1" item={item} />));
    expect(container.firstChild).toBeNull();
  });
});

describe("ItemRow revert dispatches with correct `to` target", () => {
  it.each<[string, "pending" | "preparing", ItemTransition[]]>([
    ["preparing", "pending", ["mark-ready", "revert"]],
    ["ready", "preparing", ["mark-served", "revert"]],
    // "served" is not tested here: ItemRow returns null for served items (waiter domain)
  ])("status %s reverts to %s", (status, target, allowed) => {
    const item = makeItem({
      id: "i-r",
      status: status as "preparing" | "ready" | "served",
      allowed_transitions: allowed,
    });
    render(wrap(<ItemRow orderId="o-1" item={item} />));
    fireEvent.click(screen.getByTestId("item-i-r-revert"));
    expect(revertMutate).toHaveBeenCalledWith({
      orderId: "o-1",
      itemId: "i-r",
      to: target,
    });
  });
});

describe("ItemRow voided defensive render", () => {
  it("renders item info struck-through with no action buttons", () => {
    const item = makeItem({
      id: "i-v",
      status: "voided",
      allowed_transitions: [],
    });
    render(wrap(<ItemRow orderId="o-1" item={item} />));
    const row = screen.getByTestId("item-row-i-v");
    expect(row.className).toContain("opacity-50");
    expect(screen.queryByTestId("item-i-v-forward")).toBeNull();
    expect(screen.queryByTestId("item-i-v-revert")).toBeNull();
  });
});
