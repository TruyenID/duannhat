import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook } from "@testing-library/react";

const socketMock = vi.hoisted(() => ({ isConnected: false }));
const useWorkstationSocketSpy = vi.hoisted(() => vi.fn());

vi.mock("@/hooks/use-workstation-socket", () => ({
  useWorkstationSocket: (slug: string) => {
    useWorkstationSocketSpy(slug);
    return socketMock;
  },
}));

// Import AFTER the mock is registered.
import { useLiveChannel, LIST_POLL_MS } from "./use-live-channel";

const SHOP = "shop-1";

beforeEach(() => {
  vi.clearAllMocks();
  socketMock.isConnected = false;
});

// #1792 — the gate used to be `workstationReachable` alone, an HTTP health
// probe that says nothing about the socket. "Workstation up over HTTP, socket
// down" then got neither push nor polling and the POS sat frozen.
describe("useLiveChannel (#1792)", () => {
  it("polls when the workstation is reachable but the socket is NOT connected", () => {
    socketMock.isConnected = false;

    const { result } = renderHook(() => useLiveChannel(SHOP, true, "auto"));

    expect(result.current.hasLiveChannel).toBe(false);
    expect(result.current.listRefetchInterval).toBe(LIST_POLL_MS);
  });

  it("stops polling only when the socket is actually connected", () => {
    socketMock.isConnected = true;

    const { result } = renderHook(() => useLiveChannel(SHOP, true, "auto"));

    expect(result.current.hasLiveChannel).toBe(true);
    expect(result.current.listRefetchInterval).toBeUndefined();
  });

  it("polls in Cloud mode even after a successful LAN health test", () => {
    // Manual Test connection can truthfully make workstationReachable=true
    // while API routing remains pinned to Cloud. The LAN socket must still be
    // idle, otherwise auth_ok would disable polling for Cloud-owned queries.
    socketMock.isConnected = true;

    const { result } = renderHook(() => useLiveChannel(SHOP, true, "cloud"));

    expect(result.current.hasLiveChannel).toBe(false);
    expect(result.current.listRefetchInterval).toBe(LIST_POLL_MS);
    // Passing "" is what keeps the hook from dialling a host that isn't there.
    expect(useWorkstationSocketSpy).toHaveBeenCalledWith("");
  });

  it.each(["auto", "workstation"] as const)(
    "passes the shop slug through in healthy %s mode",
    (mode) => {
      renderHook(() => useLiveChannel(SHOP, true, mode));

      expect(useWorkstationSocketSpy).toHaveBeenCalledWith(SHOP);
    },
  );

  it("switches from the LAN socket to Cloud polling immediately", () => {
    socketMock.isConnected = true;
    const { result, rerender } = renderHook(
      ({ mode }: { mode: "auto" | "cloud" }) =>
        useLiveChannel(SHOP, true, mode),
      { initialProps: { mode: "auto" } },
    );

    expect(result.current.hasLiveChannel).toBe(true);
    expect(result.current.listRefetchInterval).toBeUndefined();

    rerender({ mode: "cloud" });

    expect(result.current.hasLiveChannel).toBe(false);
    expect(result.current.listRefetchInterval).toBe(LIST_POLL_MS);
    expect(useWorkstationSocketSpy).toHaveBeenLastCalledWith("");
  });
});
