import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { useQueryClient } from "@tanstack/react-query";
import {
  CLOUD_URL,
  clearWorkstationUrl,
  getMode,
  getWorkstationUrl,
  isUsingWorkstation,
  resetUnreachable,
  setMode as persistMode,
  setWorkstationUrl as persistWorkstationUrl,
  type ApiMode,
} from "@/services/workstation/base-url-resolver";

/**
 * Connection state derived from current API mode + workstation reachability.
 *
 *   - lan-active    : auto/workstation mode AND workstation healthy
 *   - cloud-auto    : auto mode, workstation in backoff (recently unreachable)
 *   - cloud-manual  : user explicitly chose "cloud" in Settings
 *   - disconnected  : last health check failed AND not in any explicit mode
 *
 * Health is probed against workstation's /api/lan/health endpoint (public,
 * no auth required) — see workstation-app routes.go.
 */
export type ConnectionStatus =
  | "lan-active"
  | "cloud-auto"
  | "cloud-manual"
  | "disconnected"
  | "checking";

/**
 * Build identity of the two things a shop actually runs (#2632).
 *
 * `null` means UNKNOWN and nothing else. It is never "the last number we saw":
 * a version string that no longer matches the box in front of the cashier is
 * worse than no number, because the whole point of showing it is diagnosing a
 * mismatch over the phone. Every failing probe resets both fields.
 *
 * They are TWO numbers, deliberately not merged. The bundle ships inside the
 * binary, but the browser can be sitting on a cached older bundle, and that
 * gap is exactly what the pair is for.
 */
export interface WorkstationVersions {
  /** Workstation binary — `version` from GET /api/lan/health. */
  workstation: string | null;
  /** Embedded pos-web bundle — GET /api/lan/pos-bundle/version. */
  posBundle: string | null;
}

const UNKNOWN_VERSIONS: WorkstationVersions = {
  workstation: null,
  posBundle: null,
};

/**
 * Whether HQ expects a newer workstation build than the one answering (#2633).
 *
 * READ-ONLY, and that is a product ruling, not a missing feature: the update is
 * applied from the workstation's own Settings screen, by someone standing at the
 * machine that is about to restart. Nothing here offers to do it.
 *
 * `available` is true ONLY when the workstation said so. An older workstation
 * has no such field, a probe can fail, and the update feed may not be configured
 * at all — every one of those is "we do not know", and the screen stays silent
 * for all of them. There is deliberately no "you are up to date" state: an
 * unconfigured feed also reports no update, so saying so would be a lie in the
 * most common case.
 */
export interface WorkstationUpdateHint {
  available: boolean;
  /** The version HQ expects. null = the workstation did not name a usable one. */
  expectedVersion: string | null;
}

const NO_UPDATE_HINT: WorkstationUpdateHint = {
  available: false,
  expectedVersion: null,
};

interface WorkstationContextValue {
  mode: ApiMode;
  setMode: (mode: ApiMode) => void;
  status: ConnectionStatus;
  workstationReachable: boolean;
  workstationUrl: string;
  cloudUrl: string;
  /** Manually re-probe workstation /api/lan/health — used by Settings "Test" button. */
  testConnection: () => Promise<boolean>;
  lastCheckedAt: Date | null;
  /** Build identity of the workstation binary + the pos-web bundle it serves. */
  versions: WorkstationVersions;
  /** Read-only "a newer workstation build exists" signal — display only (#2633). */
  updateHint: WorkstationUpdateHint;
  /** Pair this device to a shop's workstation at the given IP:port (per-device, localStorage). */
  setWorkstationUrl: (url: string) => void;
  /** Revert to the build-time default (VITE_WORKSTATION_API_URL). */
  clearWorkstationUrl: () => void;
}

const WorkstationContext = createContext<WorkstationContextValue | null>(null);

const HEALTH_INTERVAL_MS = 30_000;
const HEALTH_TIMEOUT_MS = 3_000;

/**
 * A version string, or null when the workstation did not give us a usable one.
 * The bundle endpoint answers `{"version":"unknown"}` when a binary shipped
 * without a real bundle stamp (pos_bundle.go) — that word IS the unknown state,
 * so it collapses here rather than being printed as if it were a build id.
 */
function normalizeVersion(raw: unknown): string | null {
  if (typeof raw !== "string") return null;
  const trimmed = raw.trim();
  if (!trimmed || trimmed === "unknown") return null;
  return trimmed;
}

async function lanGet(path: string): Promise<Response | null> {
  try {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), HEALTH_TIMEOUT_MS);
    // Raw fetch deliberately: /api/lan/* is workstation-specific (no auth,
    // no resolver fallback). Going through apiFetch would route via resolver
    // and could hit Cloud instead, defeating the purpose of a workstation
    // health probe.
    // eslint-disable-next-line no-restricted-globals
    const res = await fetch(`${getWorkstationUrl()}${path}`, {
      signal: controller.signal,
    });
    clearTimeout(timer);
    return res;
  } catch {
    return null;
  }
}

/**
 * Health probe. `ok` keeps its original meaning (the workstation answered 2xx);
 * `version` is a bonus read off the same response — a workstation that answers
 * 200 with a body we cannot parse is still reachable, it just has no version to
 * report.
 *
 * The binary version is ALSO on `GET /api/version` and `GET /api/status`, and
 * both are the wrong door: routes.go wraps them in `local(...)`, which is
 * `localOnly`, i.e. `ip.IsLoopback()`. From a POS tablet those are a 403. Only
 * `/api/lan/*` crosses the LAN.
 */
async function probeWorkstation(): Promise<{
  ok: boolean;
  version: string | null;
  updateHint: WorkstationUpdateHint;
}> {
  const res = await lanGet("/api/lan/health");
  if (!res || !res.ok)
    return { ok: false, version: null, updateHint: NO_UPDATE_HINT };
  const body = (await res.json().catch(() => null)) as {
    version?: unknown;
    expected_version?: unknown;
    update_available?: unknown;
  } | null;
  return {
    ok: true,
    version: normalizeVersion(body?.version),
    updateHint: {
      // Strict `=== true`: a workstation predating #2633 omits the field, and
      // an unparseable body leaves it undefined. Neither is a reason to tell a
      // shop its machine is out of date.
      available: body?.update_available === true,
      expectedVersion: normalizeVersion(body?.expected_version),
    },
  };
}

/** Identity of the pos-web bundle the workstation serves at /pos (#1169). */
async function probePosBundleVersion(): Promise<string | null> {
  const res = await lanGet("/api/lan/pos-bundle/version");
  if (!res || !res.ok) return null;
  const body = (await res.json().catch(() => null)) as {
    version?: unknown;
  } | null;
  return normalizeVersion(body?.version);
}

export function WorkstationProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [mode, setModeState] = useState<ApiMode>(() => getMode());
  const [workstationReachable, setReachable] = useState(false);
  const [status, setStatus] = useState<ConnectionStatus>("checking");
  const [lastCheckedAt, setLastCheckedAt] = useState<Date | null>(null);
  const [versions, setVersions] =
    useState<WorkstationVersions>(UNKNOWN_VERSIONS);
  const [updateHint, setUpdateHint] =
    useState<WorkstationUpdateHint>(NO_UPDATE_HINT);
  const [workstationUrl, setWorkstationUrlState] = useState<string>(() =>
    getWorkstationUrl(),
  );
  // Only the newest probe may write versions. A probe is two awaited requests,
  // so a slow one can land after the operator has already switched to Cloud (or
  // re-paired) — and writing then would put a number back on screen for a
  // workstation this terminal is no longer talking to.
  const probeSeq = useRef(0);

  const computeStatus = useCallback(
    (reach: boolean, currentMode: ApiMode): ConnectionStatus => {
      if (currentMode === "cloud") return "cloud-manual";
      if (currentMode === "workstation") {
        return reach ? "lan-active" : "disconnected";
      }
      // auto
      if (!reach) return "cloud-auto";
      return isUsingWorkstation() ? "lan-active" : "cloud-auto";
    },
    [],
  );

  const refreshHealth = useCallback(async () => {
    const seq = ++probeSeq.current;
    const { ok, version, updateHint: hint } = await probeWorkstation();

    // A mode/URL change may have started a newer probe (or invalidated LAN
    // probing entirely) while this request was in flight. The fence covers
    // every state and breaker write, not only the optional version fields:
    // stale reachability is what decides whether POS mounts the LAN WebSocket
    // and disables polling.
    if (seq !== probeSeq.current) return false;

    setReachable(ok);
    setLastCheckedAt(new Date());

    if (!ok) {
      // Unreachable ⇒ both numbers go back to unknown in the same beat.
      setVersions(UNKNOWN_VERSIONS);
      setUpdateHint(NO_UPDATE_HINT);
      setStatus(computeStatus(false, getMode()));
      return false;
    }

    // Health is the live-channel gate. Publish it immediately instead of
    // making the socket wait for the optional bundle-version request.
    resetUnreachable();
    setStatus(computeStatus(true, getMode()));

    const posBundle = await probePosBundleVersion();
    if (seq !== probeSeq.current) return false;

    setVersions({ workstation: version, posBundle });
    setUpdateHint(hint);
    return true;
  }, [computeStatus]);

  // LAN access is opt-in. Cloud mode must not touch localhost/LAN at all;
  // switching to Auto or Workstation starts discovery immediately.
  useEffect(() => {
    if (mode === "cloud") {
      probeSeq.current++; // an in-flight probe must not write after this
      setReachable(false);
      setStatus("cloud-manual");
      setLastCheckedAt(null);
      setVersions(UNKNOWN_VERSIONS);
      setUpdateHint(NO_UPDATE_HINT);
      return;
    }

    void refreshHealth();
    const id = setInterval(() => {
      void refreshHealth();
    }, HEALTH_INTERVAL_MS);
    return () => clearInterval(id);
  }, [mode, refreshHealth]);

  // Re-probe on regain of network connectivity (commonly missed transition).
  useEffect(() => {
    if (mode === "cloud") return;

    const handler = () => {
      resetUnreachable();
      refreshHealth();
    };
    window.addEventListener("online", handler);
    return () => window.removeEventListener("online", handler);
  }, [mode, refreshHealth]);

  const setMode = useCallback(
    (next: ApiMode) => {
      const prev = getMode();
      // Invalidate an in-flight LAN probe synchronously. Waiting for the Cloud
      // mode effect leaves a race window where the old response can set
      // workstationReachable=true and briefly remount the LAN WebSocket.
      if (next === "cloud") probeSeq.current++;
      persistMode(next);
      setModeState(next);
      setStatus(computeStatus(workstationReachable, next));

      // Mode flip → the same query key now resolves against a different
      // base URL. Without busting the cache, TanStack Query keeps
      // serving the previous side's response (LAN→cloud reads the
      // cached LAN body and vice versa) until `staleTime` expires.
      // The most visible symptom: a cashier with an open till session
      // sees "no shift open" after toggling because the cached
      // /pos/till/current from the old side is stale w.r.t. the new
      // side's view. We also bust the resolver's reachability backoff
      // so the very next request hits the new URL immediately rather
      // than waiting out the 30s LAN-unreachable timer.
      if (prev !== next) {
        resetUnreachable();
        // Invalidate everything — narrow scoping per-domain (till,
        // menu, orders, settings, payments…) would be fragile and
        // miss whatever query the cashier is staring at. Cache
        // entries refetch on next mount/focus.
        void queryClient.invalidateQueries();
      }
    },
    [computeStatus, queryClient, workstationReachable],
  );

  // Pairing a new address is the same cache-invalidation situation as a mode
  // flip (same reasoning as setMode above): every LAN-bound query now points
  // at a different host, so stale cached responses from the old address must
  // not linger.
  const applyUrlChange = useCallback(() => {
    setWorkstationUrlState(getWorkstationUrl());
    setReachable(false);
    setStatus("checking");
    // Different host ⇒ the versions on screen describe a machine this terminal
    // no longer points at. Unknown until the new address answers.
    setVersions(UNKNOWN_VERSIONS);
    setUpdateHint(NO_UPDATE_HINT);
    void queryClient.invalidateQueries();
    void refreshHealth();
  }, [queryClient, refreshHealth]);

  const setWorkstationUrl = useCallback(
    (url: string) => {
      persistWorkstationUrl(url);
      applyUrlChange();
    },
    [applyUrlChange],
  );

  const clearWorkstationUrlHandler = useCallback(() => {
    clearWorkstationUrl();
    applyUrlChange();
  }, [applyUrlChange]);

  const value = useMemo<WorkstationContextValue>(
    () => ({
      mode,
      setMode,
      status,
      workstationReachable,
      workstationUrl,
      cloudUrl: CLOUD_URL,
      testConnection: refreshHealth,
      lastCheckedAt,
      versions,
      updateHint,
      setWorkstationUrl,
      clearWorkstationUrl: clearWorkstationUrlHandler,
    }),
    [
      mode,
      setMode,
      status,
      workstationReachable,
      workstationUrl,
      refreshHealth,
      lastCheckedAt,
      versions,
      updateHint,
      setWorkstationUrl,
      clearWorkstationUrlHandler,
    ],
  );

  return (
    <WorkstationContext.Provider value={value}>
      {children}
    </WorkstationContext.Provider>
  );
}

export function useWorkstation(): WorkstationContextValue {
  const ctx = useContext(WorkstationContext);
  if (!ctx) {
    throw new Error("useWorkstation must be used within <WorkstationProvider>");
  }
  return ctx;
}
