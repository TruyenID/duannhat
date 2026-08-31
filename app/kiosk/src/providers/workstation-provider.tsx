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
import NetInfo from "@react-native-community/netinfo";
import { useQueryClient } from "@tanstack/react-query";

import { useAuth } from "./auth-provider";
import { getDeviceToken } from "../lib/api";
import { workstationDiscovery } from "../services/workstation/discovery";
import { workstationSocket } from "../services/workstation/socket";
import {
  isLanFallbackEnabled,
  isUsingWorkstation,
  onLanFallbackChange,
  onManualUrlChange,
  resetUnreachable,
  resolveWorkstationUrl,
  setLanFallbackEnabled,
  shouldScanWorkstation,
} from "../services/workstation/base-url-resolver";
import type { WorkstationInfo } from "../services/workstation/types";

interface WorkstationContextValue {
  workstation: WorkstationInfo | null;
  /** True when WebSocket to workstation is open. */
  socketConnected: boolean;
  /** True when the current API call would route through workstation. */
  usingWorkstation: boolean;
  /** True when the operator opted into the LAN standby (mDNS + LAN routing). */
  lanFallbackEnabled: boolean;
  /** Turn the LAN standby on/off from Settings. */
  setLanFallback: (enabled: boolean) => Promise<void>;
}

const WorkstationContext = createContext<WorkstationContextValue | undefined>(undefined);

export function WorkstationProvider({ children }: { children: ReactNode }) {
  const { device, isAuthenticated } = useAuth();
  const queryClient = useQueryClient();

  const [workstation, setWorkstation] = useState<WorkstationInfo | null>(null);
  const [socketConnected, setSocketConnected] = useState(false);
  const [usingWorkstation, setUsingWorkstation] = useState(false);
  const [lanFallbackEnabled, setLanFallbackState] = useState(isLanFallbackEnabled());
  // Re-render trigger for manual-URL changes so `resolveWorkstationUrl()` below
  // recomputes the socket target. The value itself is read live off the resolver.
  const [, setManualUrlTick] = useState<string | null>(null);
  const usingPollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const branchId = device?.branch_id ?? "";

  // Mirror the persisted opt-in. The resolver hydrates it from AsyncStorage
  // asynchronously at import, so subscribing (rather than reading once) is what
  // picks up the stored value on a cold start.
  useEffect(() => onLanFallbackChange(setLanFallbackState), []);
  useEffect(() => onManualUrlChange(setManualUrlTick), []);

  // The workstation URL the socket should talk to: mDNS-discovered (standby on)
  // OR a manual/build-time URL (works even with standby off). Printing and its
  // print_status failure events flow over this socket, so it must connect to a
  // manually-configured workstation too — not only an mDNS-discovered one
  // (issue #44 review finding #3). Gated on auth (no socket before pairing);
  // `workstation`/manual-tick drive the re-render that recomputes it.
  const socketUrl = isAuthenticated
    ? workstation?.proxyUrl ?? resolveWorkstationUrl()
    : null;

  // Start/stop mDNS discovery. Gated on the LAN-standby opt-in: the kiosk is
  // cloud-first, and it used to be the only client on the network continuously
  // probing `_ws-app._tcp`. Nothing touches the LAN until an operator asks for
  // the fallback in Settings (issue #44).
  useEffect(() => {
    if (!shouldScanWorkstation(isAuthenticated, branchId)) {
      workstationDiscovery.stop();
      setWorkstation(null);
      return;
    }
    workstationDiscovery.start(branchId);
    const unsub = workstationDiscovery.onChange(setWorkstation);
    return () => {
      unsub();
      workstationDiscovery.stop();
    };
  }, [isAuthenticated, branchId, lanFallbackEnabled]);

  // Open/close WebSocket to the resolved workstation (mDNS or manual/env URL).
  useEffect(() => {
    if (!socketUrl) {
      workstationSocket.disconnect();
      return;
    }
    let cancelled = false;
    (async () => {
      const token = await getDeviceToken();
      if (cancelled || !token) return;
      workstationSocket.connect(socketUrl, token);
    })();
    const unsub = workstationSocket.onStatusChange(setSocketConnected);
    return () => {
      cancelled = true;
      unsub();
    };
  }, [socketUrl]);

  // Wire workstation broadcast events to React Query cache invalidation.
  useEffect(() => {
    const offs = [
      workstationSocket.on("menu_updated", () => {
        queryClient.invalidateQueries({ queryKey: ["menu"] });
      }),
      workstationSocket.on("order_created", () => {
        queryClient.invalidateQueries({ queryKey: ["order"] });
      }),
      workstationSocket.on("order_updated", () => {
        queryClient.invalidateQueries({ queryKey: ["order"] });
      }),
      workstationSocket.on("order_paid", () => {
        queryClient.invalidateQueries({ queryKey: ["order"] });
        queryClient.invalidateQueries({ queryKey: ["payment"] });
      }),
    ];
    return () => offs.forEach((off) => off());
  }, [queryClient]);

  // Reset unreachable flag when network changes (4G ↔ WiFi → workstation may be reachable again).
  useEffect(() => {
    const unsub = NetInfo.addEventListener(() => {
      resetUnreachable();
    });
    return () => unsub();
  }, []);

  // Reflect the resolver's current routing target in state so the UI banner
  // can react. Polling is cheap (sync function read) and keeps UI in sync with
  // the unreachable-backoff timestamp without coupling to fetch internals.
  useEffect(() => {
    const tick = () => setUsingWorkstation(isUsingWorkstation());
    tick();
    usingPollRef.current = setInterval(tick, 2_000);
    return () => {
      if (usingPollRef.current) clearInterval(usingPollRef.current);
    };
  }, []);

  const setLanFallback = useCallback(async (enabled: boolean) => {
    await setLanFallbackEnabled(enabled);
  }, []);

  const value = useMemo<WorkstationContextValue>(
    () => ({
      workstation,
      socketConnected,
      usingWorkstation,
      lanFallbackEnabled,
      setLanFallback,
    }),
    [workstation, socketConnected, usingWorkstation, lanFallbackEnabled, setLanFallback],
  );

  return <WorkstationContext.Provider value={value}>{children}</WorkstationContext.Provider>;
}

export function useWorkstation(): WorkstationContextValue {
  const ctx = useContext(WorkstationContext);
  if (!ctx) throw new Error("useWorkstation must be used within <WorkstationProvider>");
  return ctx;
}

export function useWorkstationSubscribe(
  type: string,
  handler: (payload: unknown) => void,
): void {
  const handlerRef = useRef(handler);
  handlerRef.current = handler;
  useEffect(() => {
    return workstationSocket.on(type, (payload) => handlerRef.current(payload));
  }, [type]);
}
