import { renderHook, act, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { I18nProvider } from "@/i18n";
import { AuthProvider } from "@/providers/AuthProvider";
import { RealtimeProvider } from "@/providers/RealtimeProvider";
import { setDeviceToken } from "@/services/auth/device-token";
import { useMarkReady } from "@/hooks/use-mark-ready";
import { useBumpAll } from "@/hooks/use-bump-all";
import { makeOrder, makeItem } from "@/test/fixtures/kds";
import type { KdsOrdersQueryData } from "@/services/kds/orders";

/**
 * FE↔BE self-echo dedup contract.
 *
 * Sibling tests already check that each mutation hook records ONE
 * idempotency_key via recordBumpKey, and the backend Pest contract test
 * locks the cloud-side broadcast format `${batchKey}:${itemId}` (for bump-all)
 * or the raw UUID (single-item ops). This file locks the LAST mile: when a
 * broadcast event reaches RealtimeProvider's listener, the key check actually
 * stops invalidateQueries from firing — otherwise the cook's optimistic
 * update gets clobbered by a refetch immediately after their tap.
 */

// Captured listeners registered on the dispatcher; the test fires events
// directly through these instead of spinning up a real WS.
const { listeners, mockOn } = vi.hoisted(() => {
  const list: Array<(event: { type: string; payload: unknown }) => void> = [];
  const on = vi.fn((handler: (event: { type: string; payload: unknown }) => void) => {
    list.push(handler);
    return () => {
      const idx = list.indexOf(handler);
      if (idx >= 0) list.splice(idx, 1);
    };
  });
  return { listeners: list, mockOn: on };
});

vi.mock("@/services/realtime/dispatcher", () => ({
  createRealtimeDispatcher: () => ({
    on: mockOn,
    connect: vi.fn().mockResolvedValue(undefined),
    close: vi.fn(),
  }),
}));

// Avoid touching localStorage / mode toggles in tests.
vi.mock("@/services/base-url-resolver", async () => {
  const actual = await vi.importActual<typeof import("@/services/base-url-resolver")>(
    "@/services/base-url-resolver",
  );
  return { ...actual, isUsingWorkstation: () => false };
});

function fireRealtime(event: { type: string; payload: unknown }) {
  for (const l of listeners) {
    l(event);
  }
}

beforeEach(() => {
  // restoreAllMocks unwinds any vi.spyOn() set inside a previous test
  // (e.g., crypto.randomUUID), so the next test starts with a real fresh impl.
  // clearAllMocks alone resets call history but leaves spy implementations
  // in place — which would leak a pinned UUID across tests.
  vi.restoreAllMocks();
  localStorage.clear();
  listeners.length = 0;
  mockOn.mockClear();
  setDeviceToken("tok", {
    id: "d-1",
    name: "KDS-1",
    type: "kds",
    status: "active",
    branch_id: "b-1",
  });
  // Default fetch handler: /kds/me succeeds so AuthProvider transitions
  // loading → paired, which is what triggers RealtimeProvider's useEffect
  // to register the listener we depend on for fireRealtime() to fire.
  global.fetch = vi.fn().mockImplementation(async (url: string) => {
    if (typeof url === "string" && url.includes("/kds/me")) {
      return {
        ok: true,
        status: 200,
        json: async () => ({
          data: { id: "d-1", name: "KDS-1", type: "kds", status: "active", branch_id: "b-1" },
        }),
      };
    }
    return { ok: true, status: 200, json: async () => ({ data: null }) };
  });
});

function wrap(qc: QueryClient) {
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <I18nProvider>
        <AuthProvider>
          <QueryClientProvider client={qc}>
            <RealtimeProvider>{children}</RealtimeProvider>
          </QueryClientProvider>
        </AuthProvider>
      </I18nProvider>
    );
  };
}

describe("Self-echo dedup: mutation → recordBumpKey → broadcast → no refetch", () => {
  it("dedups a broadcast that carries the same idempotency_key as our own bump", async () => {
    const knownKey = "test-uuid-self-echo-001";
    vi.spyOn(crypto, "randomUUID").mockReturnValue(
      knownKey as ReturnType<typeof crypto.randomUUID>,
    );

    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: makeItem({ id: "i-1", status: "ready" }) }),
    });

    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });
    const seed = makeOrder({
      id: "o-1",
      items: [makeItem({ id: "i-1", status: "preparing" })],
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], { orders: [seed] });

    const { result } = renderHook(() => useMarkReady(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({ orderId: "o-1", itemId: "i-1" });
    });

    // Snapshot the invalidate counter AFTER the mutation (the mutation's own
    // onSettled invalidate is expected). Any further call would be the echo.
    const invalidateSpy = vi.spyOn(qc, "invalidateQueries");
    invalidateSpy.mockClear();

    // Cloud now echoes the bump we just fired. RealtimeProvider's dedup map
    // already holds knownKey from recordBumpKey, so this event must be a no-op.
    await act(async () => {
      fireRealtime({
        type: "order_item.status_changed",
        payload: {
          order_id: "o-1",
          item_id: "i-1",
          status: "ready",
          idempotency_key: knownKey,
        },
      });
    });

    expect(invalidateSpy).not.toHaveBeenCalled();
  });

  it("invalidates when the broadcast carries a key we did not record (foreign device)", async () => {
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], {
      orders: [
        makeOrder({
          id: "o-1",
          items: [makeItem({ id: "i-2", status: "preparing" })],
        }),
      ],
    });

    // No mutation — provider mounts, listener registers after AuthProvider
    // transitions to "paired" (driven by the /kds/me mock in beforeEach).
    renderHook(() => null, { wrapper: wrap(qc) });
    await waitFor(() => expect(listeners.length).toBeGreaterThan(0));

    const invalidateSpy = vi.spyOn(qc, "invalidateQueries");
    invalidateSpy.mockClear();

    await act(async () => {
      fireRealtime({
        type: "order_item.status_changed",
        payload: {
          order_id: "o-1",
          item_id: "i-2",
          status: "ready",
          idempotency_key: "another-device-uuid-999",
        },
      });
    });

    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ["kds", "orders"] });
  });

  it("invalidates when the broadcast has no idempotency_key at all (defensive)", async () => {
    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });
    renderHook(() => null, { wrapper: wrap(qc) });
    await waitFor(() => expect(listeners.length).toBeGreaterThan(0));

    const invalidateSpy = vi.spyOn(qc, "invalidateQueries");
    invalidateSpy.mockClear();

    await act(async () => {
      fireRealtime({
        type: "order_item.status_changed",
        payload: { order_id: "o-1", item_id: "i-2", status: "ready" },
      });
    });

    expect(invalidateSpy).toHaveBeenCalled();
  });

  it("bump-all dedups every per-item event matching ${batchKey}:${itemId}", async () => {
    const batchKey = "batch-test-uuid-001";
    vi.spyOn(crypto, "randomUUID").mockReturnValue(
      batchKey as ReturnType<typeof crypto.randomUUID>,
    );

    global.fetch = vi.fn().mockImplementation(async (url: string) => {
      if (typeof url === "string" && url.includes("/kds/me")) {
        return {
          ok: true,
          status: 200,
          json: async () => ({
            data: {
              id: "d-1",
              name: "KDS-1",
              type: "kds",
              status: "active",
              branch_id: "b-1",
            },
          }),
        };
      }
      return {
        ok: true,
        status: 200,
        json: async () => ({
          data: { id: "o-1", order_code: "042", items: [] },
        }),
      };
    });

    const qc = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } },
    });
    const items = [
      makeItem({ id: "i-1", status: "pending" }),
      makeItem({ id: "i-2", status: "pending" }),
      makeItem({ id: "i-3", status: "pending" }),
    ];
    qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], {
      orders: [makeOrder({ id: "o-1", items })],
    });

    const { result } = renderHook(() => useBumpAll(), { wrapper: wrap(qc) });

    await act(async () => {
      await result.current.mutateAsync({
        orderId: "o-1",
        scope: "pending",
        targetItems: items,
      });
    });

    const invalidateSpy = vi.spyOn(qc, "invalidateQueries");
    invalidateSpy.mockClear();

    // Cloud fans out N events, one per item. All three should be deduped
    // because useBumpAll pre-recorded each derived key in onMutate.
    await act(async () => {
      for (const item of items) {
        fireRealtime({
          type: "order_item.status_changed",
          payload: {
            order_id: "o-1",
            item_id: item.id,
            status: "preparing",
            idempotency_key: `${batchKey}:${item.id}`,
          },
        });
      }
    });

    expect(invalidateSpy).not.toHaveBeenCalled();

    // Sanity check: a foreign-device event for the SAME order still invalidates.
    await act(async () => {
      fireRealtime({
        type: "order_item.status_changed",
        payload: {
          order_id: "o-1",
          item_id: "i-1",
          status: "ready",
          idempotency_key: "foreign-batch:i-1",
        },
      });
    });
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ["kds", "orders"] });
  });

});

/*
 * Re-entrant double-tap on useBumpAll: the wrapper drops the second call when
 * mutation.isPending is true. We intentionally don't test this in vitest —
 * isPending flips after React commits a state update, and synthesizing a
 * sub-frame race in jsdom with TanStack Query v5 produces flaky timeouts
 * (TanStack's internal microtask scheduling doesn't surface deterministic
 * isPending transitions for back-to-back synchronous calls in tests).
 *
 * The primary defense is the component-level `disabled={bumpAll.isPending}`
 * on the trigger (see ticket-card.tsx). The wrapper guard is documented as
 * belt-and-suspenders for the same-frame race (use-bump-all.ts docblock).
 * If a future bug report claims double-bump in production, add a more
 * sophisticated test then — until then, the cost of a flaky CI test
 * outweighs the value of locking sub-frame behavior here.
 */
