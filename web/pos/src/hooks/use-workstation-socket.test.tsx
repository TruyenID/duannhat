import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, renderHook } from "@testing-library/react";
import { type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import { orderKeys, tableKeys } from "@/hooks/api/query-keys";
import { TAKEAWAY_ORDER_FILTERS } from "@/hooks/api/use-orders";
import type { CustomerOrder } from "@/app/pos/types";

// ── Mocks (hoisted so the vi.mock factories can reference them) ──────────────
const { printPaymentReceipt, toastCalls, toastError } = vi.hoisted(() => ({
  printPaymentReceipt: vi.fn(() => Promise.resolve({})),
  toastCalls: [] as Array<{ message: string; opts?: { action?: { onClick: () => void } } }>,
  toastError: vi.fn(),
}));

vi.mock("@/services/workstation-print-service", () => ({
  workstationPrintService: { enabled: true, printPaymentReceipt },
}));

vi.mock("sonner", () => ({
  toast: Object.assign(
    (message: string, opts?: { action?: { onClick: () => void } }) => {
      toastCalls.push({ message, opts });
    },
    { error: toastError },
  ),
}));

// Controllable fake WebSocket — capture the instance so the test can drive it.
let lastWs: FakeWS | null = null;
class FakeWS {
  onopen: (() => void) | null = null;
  onmessage: ((e: { data: string }) => void) | null = null;
  onclose: (() => void) | null = null;
  onerror: (() => void) | null = null;
  sent: string[] = [];
  closeCalls = 0;
  constructor(public url: string) {
    // Capture the instance so the test can drive onopen/onmessage.
    // eslint-disable-next-line @typescript-eslint/no-this-alias
    lastWs = this;
  }
  send(d: string) {
    this.sent.push(d);
  }
  close() {
    this.closeCalls += 1;
  }
}

// Import AFTER the mocks are registered.
import { useWorkstationSocket } from "./use-workstation-socket";

const SHOP = "shop-1";

function makeWrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={client}>
        <AppProvider>{children}</AppProvider>
      </QueryClientProvider>
    );
  };
}

function seedDetail(client: QueryClient, order: Partial<CustomerOrder>) {
  client.setQueryData(orderKeys.detail(SHOP, order.id as string), {
    data: order as CustomerOrder,
  });
}

function fireMessage(type: string, payload?: unknown) {
  act(() => {
    lastWs?.onmessage?.({ data: JSON.stringify({ type, payload }) });
  });
}

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("pos_device_token", "test-token");
  toastCalls.length = 0;
  printPaymentReceipt.mockClear();
  toastError.mockClear();
  lastWs = null;
  vi.stubGlobal("WebSocket", FakeWS as unknown as typeof WebSocket);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

// ── #1798 — a socket that was down received none of the live patches, and
//    nothing else fills the hole: refetchOnReconnect keys off NETWORK events,
//    and the #1792 polling fallback schedules its first fetch 15s after the
//    drop, which a 3s reconnect beats. So auth_ok must resync. ──────────────
describe("useWorkstationSocket — resync on (re)connect (#1798)", () => {
  it("invalidates the WS-driven lists when auth_ok arrives", () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    const spy = vi.spyOn(client, "invalidateQueries");

    renderHook(() => useWorkstationSocket(SHOP), { wrapper: makeWrapper(client) });
    expect(lastWs).not.toBeNull();
    spy.mockClear(); // ignore anything the mount itself did

    fireMessage("auth_ok", {});

    const busted = spy.mock.calls.map(([arg]) =>
      JSON.stringify((arg as { queryKey: unknown }).queryKey),
    );
    expect(busted).toContain(JSON.stringify(orderKeys.lists(SHOP)));
    expect(busted).toContain(JSON.stringify(orderKeys.list(SHOP, TAKEAWAY_ORDER_FILTERS)));
    expect(busted).toContain(JSON.stringify(tableKeys.all(SHOP)));
  });

  it("resyncs again on a LATER auth_ok — the reconnect case is the whole point", () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    renderHook(() => useWorkstationSocket(SHOP), { wrapper: makeWrapper(client) });

    fireMessage("auth_ok", {});
    const spy = vi.spyOn(client, "invalidateQueries");
    fireMessage("auth_ok", {}); // second connect after a drop

    expect(spy).toHaveBeenCalled();
  });
});

describe("useWorkstationSocket — source ownership (#2978)", () => {
  it("closes the LAN socket when its shop slug is withdrawn for Cloud mode", () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    const { result, rerender } = renderHook(
      ({ shopSlug }: { shopSlug: string }) => useWorkstationSocket(shopSlug),
      {
        initialProps: { shopSlug: SHOP },
        wrapper: makeWrapper(client),
      },
    );
    const lanSocket = lastWs;
    expect(lanSocket).not.toBeNull();

    fireMessage("auth_ok", {});
    expect(result.current.isConnected).toBe(true);

    // useLiveChannel passes an empty slug as soon as API ownership switches to
    // Cloud. The effect cleanup must sever LAN push in that same render.
    rerender({ shopSlug: "" });

    expect(lanSocket?.closeCalls).toBe(1);
    expect(result.current.isConnected).toBe(false);
  });
});

describe("useWorkstationSocket — order.code_assigned (plan-041)", () => {

  it("swaps the provisional code for the Cloud ORD code in the cache", () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    seedDetail(client, {
      id: "ord-1",
      order_code: "WS-A1B2-20260625-014",
      paid_amount: 0,
    });

    renderHook(() => useWorkstationSocket(SHOP), { wrapper: makeWrapper(client) });
    expect(lastWs).not.toBeNull();

    fireMessage("order.code_assigned", { id: "ord-1", order_code: "ORD-2026-0004" });

    const detail = client.getQueryData<{ data: CustomerOrder }>(
      orderKeys.detail(SHOP, "ord-1"),
    );
    expect(detail?.data.order_code).toBe("ORD-2026-0004");
  });

  it("offers a reprint when the order was already paid under the provisional code", () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    seedDetail(client, {
      id: "ord-2",
      order_code: "WS-A1B2-20260625-015",
      paid_amount: 50000, // paid while provisional
    });

    renderHook(() => useWorkstationSocket(SHOP), { wrapper: makeWrapper(client) });
    fireMessage("order.code_assigned", { id: "ord-2", order_code: "ORD-2026-0005" });

    // A reprint toast fired; invoking its action reprints with the new code.
    expect(toastCalls).toHaveLength(1);
    act(() => toastCalls[0]!.opts?.action?.onClick());
    expect(printPaymentReceipt).toHaveBeenCalledWith({
      orderId: "ord-2",
      reprintReason: "code_finalized",
    });
  });

  it("does NOT prompt a reprint for an unpaid order", () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    seedDetail(client, {
      id: "ord-3",
      order_code: "WS-A1B2-20260625-016",
      paid_amount: 0,
    });

    renderHook(() => useWorkstationSocket(SHOP), { wrapper: makeWrapper(client) });
    fireMessage("order.code_assigned", { id: "ord-3", order_code: "ORD-2026-0006" });

    expect(toastCalls).toHaveLength(0);
    expect(printPaymentReceipt).not.toHaveBeenCalled();
  });
});

describe("useWorkstationSocket — order_synced (cloud-origin orders)", () => {
  // A customer-web / kiosk order never passes through this POS, so it only
  // reaches the screen via the workstation's Cloud pull-DOWN loop. This hook
  // being connected is exactly what disables list polling (pos/page.tsx), so
  // without an invalidation here the order stays invisible until a reload.
  it("invalidates the order lists so the new cloud order is refetched", () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    const invalidate = vi.spyOn(client, "invalidateQueries");

    renderHook(() => useWorkstationSocket(SHOP), { wrapper: makeWrapper(client) });
    expect(lastWs).not.toBeNull();

    fireMessage("order_synced", { order_id: "cw-1", is_new: true, source: "pull_down" });

    const keys = invalidate.mock.calls.map(([arg]) =>
      JSON.stringify((arg as { queryKey: unknown }).queryKey),
    );
    expect(keys).toContain(JSON.stringify(orderKeys.lists(SHOP)));
    expect(keys).toContain(JSON.stringify(orderKeys.detail(SHOP, "cw-1")));
    expect(keys).toContain(JSON.stringify(tableKeys.all(SHOP)));
    // The takeaway drawer keeps its own feed, outside the open-list key.
    expect(keys).toContain(
      JSON.stringify(orderKeys.list(SHOP, TAKEAWAY_ORDER_FILTERS)),
    );
  });

  // The workstation pull cursor is second-precision and inclusive, so a
  // malformed/partial payload is a real possibility; it must still refresh the
  // lists rather than throw and kill the socket's message loop.
  it("still refreshes the lists when the payload carries no order_id", () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    const invalidate = vi.spyOn(client, "invalidateQueries");

    renderHook(() => useWorkstationSocket(SHOP), { wrapper: makeWrapper(client) });
    fireMessage("order_synced", {});

    const keys = invalidate.mock.calls.map(([arg]) =>
      JSON.stringify((arg as { queryKey: unknown }).queryKey),
    );
    expect(keys).toContain(JSON.stringify(orderKeys.lists(SHOP)));
  });
});
