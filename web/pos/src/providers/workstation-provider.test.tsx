import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { WorkstationProvider, useWorkstation } from "./workstation-provider";

function wrapper({ children }: { children: ReactNode }) {
  return (
    <QueryClientProvider client={new QueryClient()}>
      <WorkstationProvider>{children}</WorkstationProvider>
    </QueryClientProvider>
  );
}

beforeEach(() => {
  localStorage.clear();
  vi.restoreAllMocks();
});

describe("WorkstationProvider LAN opt-in", () => {
  it("does not probe localhost in the default Cloud mode", async () => {
    const fetchSpy = vi.spyOn(globalThis, "fetch");
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    await waitFor(() => expect(result.current.status).toBe("cloud-manual"));
    expect(result.current.mode).toBe("cloud");
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it("starts the workstation health probe after Auto mode is selected", async () => {
    const fetchSpy = vi
      .spyOn(globalThis, "fetch")
      .mockRejectedValue(new TypeError("workstation unavailable"));
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    act(() => result.current.setMode("auto"));

    await waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(1));
    expect(String(fetchSpy.mock.calls[0]?.[0])).toContain(
      "localhost:8080/api/lan/health",
    );
  });

  it("keeps Cloud routing explicit when its manual LAN test succeeds", async () => {
    mockLan(HEALTHY);
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    await act(async () => {
      expect(await result.current.testConnection()).toBe(true);
    });

    // Reachability is a physical diagnostic result, not permission to route
    // data or mount the LAN WebSocket. useLiveChannel owns that mode gate.
    expect(result.current.workstationReachable).toBe(true);
    expect(result.current.mode).toBe("cloud");
    expect(result.current.status).toBe("cloud-manual");
  });
});

/**
 * #2632 — the two version numbers a shop reads out on a support call.
 *
 * The failure this pins is not "no number appears": it is a number appearing
 * that describes a workstation the terminal has stopped talking to.
 */
function jsonResponse(body: unknown, ok = true): Response {
  return {
    ok,
    status: ok ? 200 : 503,
    json: async () => body,
  } as unknown as Response;
}

/** Mock the LAN by URL. Returning null means the fetch rejects (unreachable). */
function mockLan(route: (url: string) => Response | null) {
  return vi.spyOn(globalThis, "fetch").mockImplementation(async (input) => {
    const res = route(String(input));
    if (!res) throw new TypeError("workstation unavailable");
    return res;
  });
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason?: unknown) => void;
  const promise = new Promise<T>((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
}

const HEALTHY = (url: string) =>
  url.includes("/api/lan/health")
    ? jsonResponse({ status: "ok", version: "1.4.2" })
    : url.includes("/api/lan/pos-bundle/version")
      ? jsonResponse({ bundle: "pos-web", version: "2026.08.12-a1b2c3" })
      : null;

describe("workstation + pos-bundle versions (#2632)", () => {
  it("reports the binary version and the bundle version as two separate numbers", async () => {
    mockLan(HEALTHY);
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    act(() => result.current.setMode("auto"));

    await waitFor(() =>
      expect(result.current.versions).toEqual({
        workstation: "1.4.2",
        posBundle: "2026.08.12-a1b2c3",
      }),
    );
  });

  it("asks ONLY /api/lan/* — /api/version and /api/status are loopback-only (403 from a tablet)", async () => {
    const fetchSpy = mockLan(HEALTHY);
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    act(() => result.current.setMode("auto"));
    await waitFor(() => expect(result.current.versions.posBundle).toBeTruthy());

    const urls = fetchSpy.mock.calls.map((c) => String(c[0]));
    expect(urls.length).toBeGreaterThan(0);
    for (const url of urls) {
      expect(url).toContain("/api/lan/");
    }
    expect(urls.some((u) => /\/api\/(version|status)\b/.test(u))).toBe(false);
  });

  it("forgets the versions when the workstation stops answering — never a remembered number", async () => {
    mockLan(HEALTHY);
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    act(() => result.current.setMode("auto"));
    await waitFor(() =>
      expect(result.current.versions.workstation).toBe("1.4.2"),
    );

    // Workstation goes away; the next probe must clear BOTH, not keep the
    // last-seen build id on screen.
    mockLan(() => null);
    await act(async () => {
      await result.current.testConnection();
    });

    expect(result.current.versions).toEqual({
      workstation: null,
      posBundle: null,
    });
  });

  it("treats the bundle endpoint's own \"unknown\" as unknown, not as a build id", async () => {
    mockLan((url) =>
      url.includes("/api/lan/health")
        ? jsonResponse({ status: "ok", version: "1.4.2" })
        : jsonResponse({ bundle: "pos-web", version: "unknown" }),
    );
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    act(() => result.current.setMode("auto"));

    await waitFor(() =>
      expect(result.current.versions.workstation).toBe("1.4.2"),
    );
    expect(result.current.versions.posBundle).toBeNull();
  });

  it("keeps both unknown in Cloud mode (no LAN call is made at all)", async () => {
    const fetchSpy = mockLan(HEALTHY);
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    await waitFor(() => expect(result.current.status).toBe("cloud-manual"));
    expect(result.current.versions).toEqual({
      workstation: null,
      posBundle: null,
    });
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});

/**
 * #2633 — the read-only "HQ expects a newer build" hint, carried on the same
 * health probe as the version above.
 *
 * The interesting cases are all the ones that mean "we do not know": an older
 * workstation with no such field, an unreachable machine, Cloud mode. Every one
 * of them has to land on `available: false`, because the screen turns this
 * boolean into a sentence telling a shop to go update its PC.
 */
describe("workstation update hint (#2633)", () => {
  const UPDATABLE = (url: string) =>
    url.includes("/api/lan/health")
      ? jsonResponse({
          status: "ok",
          version: "1.4.2",
          expected_version: "1.5.0",
          update_available: true,
        })
      : jsonResponse({ bundle: "pos-web", version: "1.4.2" });

  it("reports the expected version when the workstation says an update exists", async () => {
    mockLan(UPDATABLE);
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    act(() => result.current.setMode("auto"));

    await waitFor(() =>
      expect(result.current.updateHint).toEqual({
        available: true,
        expectedVersion: "1.5.0",
      }),
    );
  });

  it("reports no update when the workstation is current", async () => {
    mockLan((url) =>
      url.includes("/api/lan/health")
        ? jsonResponse({
            status: "ok",
            version: "1.4.2",
            expected_version: "1.4.2",
            update_available: false,
          })
        : jsonResponse({ bundle: "pos-web", version: "1.4.2" }),
    );
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    act(() => result.current.setMode("auto"));

    await waitFor(() =>
      expect(result.current.versions.workstation).toBe("1.4.2"),
    );
    expect(result.current.updateHint.available).toBe(false);
  });

  // A workstation predating this change answers health without the two keys.
  // Absent must read as "no idea", never as an update.
  it("reports no update when the workstation build predates the field", async () => {
    mockLan(HEALTHY);
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    act(() => result.current.setMode("auto"));

    await waitFor(() =>
      expect(result.current.versions.workstation).toBe("1.4.2"),
    );
    expect(result.current.updateHint).toEqual({
      available: false,
      expectedVersion: null,
    });
  });

  it("forgets the hint when the workstation stops answering", async () => {
    mockLan(UPDATABLE);
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    act(() => result.current.setMode("auto"));
    await waitFor(() => expect(result.current.updateHint.available).toBe(true));

    mockLan(() => null);
    await act(async () => {
      await result.current.testConnection();
    });

    expect(result.current.updateHint).toEqual({
      available: false,
      expectedVersion: null,
    });
  });

  it("carries no hint in Cloud mode — no workstation is being asked", async () => {
    const fetchSpy = mockLan(UPDATABLE);
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    await waitFor(() => expect(result.current.status).toBe("cloud-manual"));
    expect(result.current.updateHint).toEqual({
      available: false,
      expectedVersion: null,
    });
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});

describe("stale health probe fencing (#2976)", () => {
  it("does not let a pending LAN probe re-enable reachability after switching to Cloud", async () => {
    const oldHealth = deferred<Response>();
    const fetchSpy = vi.spyOn(globalThis, "fetch").mockImplementation((input) => {
      if (String(input).includes("/api/lan/health")) return oldHealth.promise;
      return Promise.resolve(jsonResponse({ version: "old-bundle" }));
    });
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    act(() => result.current.setMode("auto"));
    await waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(1));

    act(() => result.current.setMode("cloud"));
    await waitFor(() => expect(result.current.status).toBe("cloud-manual"));

    await act(async () => {
      oldHealth.resolve(jsonResponse({ status: "ok", version: "old-workstation" }));
      await oldHealth.promise;
    });

    expect(result.current.mode).toBe("cloud");
    expect(result.current.workstationReachable).toBe(false);
    expect(result.current.lastCheckedAt).toBeNull();
    expect(result.current.versions).toEqual(UNKNOWN_VERSIONS_FOR_TEST);
    expect(fetchSpy).toHaveBeenCalledTimes(1);
  });

  it("keeps the new host healthy when the previous host fails later", async () => {
    const oldHealth = deferred<Response>();
    const fetchSpy = vi.spyOn(globalThis, "fetch").mockImplementation((input) => {
      const url = String(input);
      if (url.startsWith("http://localhost:8080")) return oldHealth.promise;
      if (url.includes("/api/lan/health")) {
        return Promise.resolve(jsonResponse({ status: "ok", version: "new-workstation" }));
      }
      return Promise.resolve(jsonResponse({ version: "new-bundle" }));
    });
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    act(() => result.current.setMode("auto"));
    await waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(1));

    act(() => result.current.setWorkstationUrl("http://192.168.1.77:8080"));
    await waitFor(() =>
      expect(result.current.versions).toEqual({
        workstation: "new-workstation",
        posBundle: "new-bundle",
      }),
    );

    await act(async () => {
      oldHealth.reject(new TypeError("old workstation unavailable"));
      await oldHealth.promise.catch(() => undefined);
    });

    expect(result.current.workstationUrl).toBe("http://192.168.1.77:8080");
    expect(result.current.workstationReachable).toBe(true);
    expect(result.current.status).toBe("lan-active");
    expect(result.current.versions).toEqual({
      workstation: "new-workstation",
      posBundle: "new-bundle",
    });
  });

  it("publishes health before bundle metadata and rejects stale bundle results", async () => {
    const oldBundle = deferred<Response>();
    const fetchSpy = vi.spyOn(globalThis, "fetch").mockImplementation((input) => {
      const url = String(input);
      if (url.startsWith("http://localhost:8080") && url.includes("/health")) {
        return Promise.resolve(jsonResponse({ status: "ok", version: "old-workstation" }));
      }
      if (url.startsWith("http://localhost:8080")) return oldBundle.promise;
      if (url.includes("/api/lan/health")) {
        return Promise.resolve(jsonResponse({ status: "ok", version: "new-workstation" }));
      }
      return Promise.resolve(jsonResponse({ version: "new-bundle" }));
    });
    const { result } = renderHook(() => useWorkstation(), { wrapper });

    act(() => result.current.setMode("auto"));

    // The optional version endpoint is still pending, but HTTP health is
    // enough to open the live channel and stop fallback polling.
    await waitFor(() => expect(result.current.workstationReachable).toBe(true));
    expect(result.current.status).toBe("lan-active");
    expect(fetchSpy.mock.calls.some(([url]) => String(url).includes("pos-bundle"))).toBe(true);

    act(() => result.current.setWorkstationUrl("http://192.168.1.88:8080"));
    await waitFor(() =>
      expect(result.current.versions).toEqual({
        workstation: "new-workstation",
        posBundle: "new-bundle",
      }),
    );

    await act(async () => {
      oldBundle.resolve(jsonResponse({ version: "late-old-bundle" }));
      await oldBundle.promise;
    });

    expect(result.current.versions).toEqual({
      workstation: "new-workstation",
      posBundle: "new-bundle",
    });
    expect(result.current.workstationReachable).toBe(true);
    expect(result.current.status).toBe("lan-active");
  });
});

const UNKNOWN_VERSIONS_FOR_TEST = {
  workstation: null,
  posBundle: null,
};
