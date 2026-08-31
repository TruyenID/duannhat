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
  isUsingWorkstation,
  resetUnreachable,
} from "../services/workstation/base-url-resolver";
import type { WorkstationInfo } from "../services/workstation/types";

interface WorkstationContextValue {
  workstation: WorkstationInfo | null;
  /** True when WebSocket to workstation is open. */
  socketConnected: boolean;
  /** True when the current API call would route through workstation. */
  usingWorkstation: boolean;
}

const WorkstationContext = createContext<WorkstationContextValue | undefined>(undefined);

export function WorkstationProvider({ children }: { children: ReactNode }) {
  const { device, isAuthenticated } = useAuth();
  const queryClient = useQueryClient();

  const [workstation, setWorkstation] = useState<WorkstationInfo | null>(null);
  const [socketConnected, setSocketConnected] = useState(false);
  const [usingWorkstation, setUsingWorkstation] = useState(false);
  const usingPollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const branchId = device?.branch_id ?? "";

  // Start/stop mDNS discovery when auth state changes.
  useEffect(() => {
    if (!isAuthenticated || !branchId) {
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
  }, [isAuthenticated, branchId]);

  // Open/close WebSocket when workstation discovered.
  useEffect(() => {
    if (!workstation?.proxyUrl) {
      workstationSocket.disconnect();
      return;
    }
    let cancelled = false;
    (async () => {
      const token = await getDeviceToken();
      if (cancelled || !token) return;
      workstationSocket.connect(workstation.proxyUrl, token);
    })();
    const unsub = workstationSocket.onStatusChange(setSocketConnected);
    return () => {
      cancelled = true;
      unsub();
    };
  }, [workstation?.proxyUrl]);

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

  const value = useMemo<WorkstationContextValue>(
    () => ({ workstation, socketConnected, usingWorkstation }),
    [workstation, socketConnected, usingWorkstation],
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
