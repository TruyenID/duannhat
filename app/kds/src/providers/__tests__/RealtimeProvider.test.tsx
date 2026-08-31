import { render, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { describe, it, expect, beforeEach, vi } from "vitest";
import { RealtimeProvider } from "../RealtimeProvider";
import { useRealtime } from "../use-realtime";
import { AuthProvider } from "../AuthProvider";
import { setDeviceToken } from "@/services/auth/device-token";
import type { RealtimeEvent } from "@/services/realtime/dispatcher";

// ---------------------------------------------------------------------------
// Fake dispatcher module: captures listeners so tests can emit events manually
// ---------------------------------------------------------------------------
type EmitFn = (e: RealtimeEvent) => void;

const listeners: EmitFn[] = [];
const fakeClient = {
  on: vi.fn((l: EmitFn) => {
    listeners.push(l);
    return () => {
      const idx = listeners.indexOf(l);
      if (idx !== -1) listeners.splice(idx, 1);
    };
  }),
  connect: vi.fn(),
  close: vi.fn(),
};

vi.mock("@/services/realtime/dispatcher", () => ({
  createRealtimeDispatcher: vi.fn(() => fakeClient),
}));

// ---------------------------------------------------------------------------

beforeEach(() => {
  localStorage.clear();
  vi.clearAllMocks();
  // Clear accumulated listeners between tests
  listeners.length = 0;
});

function emit(e: RealtimeEvent) {
  listeners.forEach((l) => l(e));
}

/** Probe component that exposes the realtime context to tests */
function Probe({ onReady }: { onReady: (rt: ReturnType<typeof useRealtime>) => void }) {
  const rt = useRealtime();
  onReady(rt);
  return null;
}

function setup() {
  setDeviceToken("tok", {
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
  const invalidateSpy = vi.spyOn(qc, "invalidateQueries");
  let rt!: ReturnType<typeof useRealtime>;

  render(
    <AuthProvider>
      <QueryClientProvider client={qc}>
        <RealtimeProvider>
          <Probe onReady={(r) => (rt = r)} />
        </RealtimeProvider>
      </QueryClientProvider>
    </AuthProvider>,
  );

  return { qc, invalidateSpy, getRt: () => rt };
}

// ---------------------------------------------------------------------------
// Import dispatcher to spy on createRealtimeDispatcher
// ---------------------------------------------------------------------------
import * as dispatcherModule from "@/services/realtime/dispatcher";

// ── #1798 — auth_ok is what turns the 15s polling fallback OFF, so it must
//    refill what the socket missed. The `?since=` replay buffer only covers
//    60s and only the low-volume event types; order_created/order_updated are
//    live-only. Without this a reconnect leaves the board wrong for good.
describe("RealtimeProvider — resync on (re)connect (#1798)", () => {
  it("invalidates the orders query when auth_ok arrives", async () => {
    const { invalidateSpy } = setup();
    await waitFor(() => expect(listeners.length).toBeGreaterThan(0));
    invalidateSpy.mockClear();

    emit({ type: "auth_ok", payload: null } as RealtimeEvent);

    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ["kds", "orders"] });
  });

  it("resyncs again on a LATER auth_ok — the reconnect case is the point", async () => {
    const { invalidateSpy } = setup();
    await waitFor(() => expect(listeners.length).toBeGreaterThan(0));

    emit({ type: "auth_ok", payload: null } as RealtimeEvent);
    invalidateSpy.mockClear();
    emit({ type: "auth_ok", payload: null } as RealtimeEvent); // reconnect

    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ["kds", "orders"] });
  });
});

describe("RealtimeProvider", () => {
  it("subscribes when paired", async () => {
    setup();
    await waitFor(() => {
      expect(dispatcherModule.createRealtimeDispatcher).toHaveBeenCalled();
    });
    expect(fakeClient.connect).toHaveBeenCalled();
  });

  it("invalidates orders query on order_item.status_changed", async () => {
    const { invalidateSpy } = setup();
    await waitFor(() => {
      expect(dispatcherModule.createRealtimeDispatcher).toHaveBeenCalled();
    });

    emit({ type: "order_item.status_changed", payload: { item_id: "i-1" } });

    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ["kds", "orders"] });
  });

  it("invalidates orders query on order_created", async () => {
    const { invalidateSpy } = setup();
    await waitFor(() => {
      expect(dispatcherModule.createRealtimeDispatcher).toHaveBeenCalled();
    });

    emit({ type: "order_created", payload: {} });

    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ["kds", "orders"] });
  });

  it("dedups events matching a recorded bump key", async () => {
    const { invalidateSpy, getRt } = setup();
    await waitFor(() => {
      expect(dispatcherModule.createRealtimeDispatcher).toHaveBeenCalled();
    });
    await waitFor(() => expect(getRt()).toBeDefined());

    getRt().recordBumpKey("idem-1");

    emit({ type: "order_item.status_changed", payload: { idempotency_key: "idem-1" } });
    expect(invalidateSpy).not.toHaveBeenCalled(); // own echo — skipped

    // Different key should still invalidate
    emit({ type: "order_item.status_changed", payload: { idempotency_key: "idem-other" } });
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ["kds", "orders"] });
  });

  it("does not subscribe when unpaired", async () => {
    // Don't call setDeviceToken — stays unpaired
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <AuthProvider>
        <QueryClientProvider client={qc}>
          <RealtimeProvider>
            <div />
          </RealtimeProvider>
        </QueryClientProvider>
      </AuthProvider>,
    );

    // Give effects time to settle
    await waitFor(() => {
      // auth transitions to unpaired without token
    });

    expect(dispatcherModule.createRealtimeDispatcher).not.toHaveBeenCalled();
  });
});
