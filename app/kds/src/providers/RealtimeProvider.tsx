import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useAuth } from "./use-auth";
import { getDeviceToken } from "@/services/auth/device-token";
import {
  createRealtimeDispatcher,
  type RealtimeMode,
} from "@/services/realtime/dispatcher";
import { isUsingWorkstation } from "@/services/base-url-resolver";
import { useAudioChime } from "@/hooks/use-audio-chime";
import { RealtimeContext, type RealtimeCtx } from "./use-realtime";
import type { RealtimeBackend } from "@/services/realtime/dispatcher";
import { workstationAlerts } from "@/services/realtime/workstation-alerts";

const DEDUP_TTL_MS = 30_000;

/**
 * Subscribes to realtime events (LAN WS or cloud Reverb), invalidates
 * TanStack Query cache so useOrders refetches, and deduplicates echoes
 * of bumps the local client originated (each operation hook records its
 * idempotency_key before the request fires; incoming OrderItemStatusChanged
 * events carrying the same key are skipped).
 */
export function RealtimeProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient();
  const { state, device } = useAuth();
  const { play: playChime } = useAudioChime();
  const recentBumpsRef = useRef<Map<string, number>>(new Map());
  const clientRef = useRef<RealtimeBackend | null>(null);
  const [isConnected, setIsConnected] = useState(false);

  // Poll LAN↔cloud failover every 5s — lifts into state so useEffect re-runs
  // and tears down/re-establishes the WS connection on mode change.
  const [connectionMode, setConnectionMode] = useState<RealtimeMode>(
    () => (isUsingWorkstation() ? "workstation" : "cloud"),
  );
  useEffect(() => {
    const id = setInterval(() => {
      const next: RealtimeMode = isUsingWorkstation() ? "workstation" : "cloud";
      setConnectionMode((prev) => (prev !== next ? next : prev));
    }, 5_000);
    return () => clearInterval(id);
  }, []);

  const recordBumpKey = useCallback((key: string) => {
    if (!key) return;
    recentBumpsRef.current.set(key, Date.now());
    const cutoff = Date.now() - DEDUP_TTL_MS;
    for (const [k, ts] of recentBumpsRef.current.entries()) {
      if (ts < cutoff) recentBumpsRef.current.delete(k);
    }
  }, []);

  useEffect(() => {
    if (state !== "paired" || !device) return;
    const token = getDeviceToken();
    if (!token) return;

    const client = createRealtimeDispatcher({
      mode: connectionMode,
      token,
      branchId: device.branch_id,
    });
    clientRef.current = client;
    setIsConnected(false);

    // #1806 S2 — mất kết nối = KHÔNG BIẾT GÌ, không phải "không có sự cố".
    workstationAlerts.clear();
    const off = client.on((event) => {
      // #1806 S2 — sự cố máy trạm. Bếp cần thấy `no_printer` sớm nhất có thể:
      // phiếu không in ra thì món không được nấu, và không ai biết cho tới khi
      // khách hỏi.
      if (event.type === "alert.snapshot") {
        const list = (event.payload as { alerts?: unknown[] })?.alerts ?? [];
        workstationAlerts.replace(list as never[]);
        return;
      }
      if (event.type === "alert.raised") {
        workstationAlerts.raise(event.payload as never);
        return;
      }
      if (event.type === "alert.resolved") {
        const p = event.payload as { kind: string; subject: string };
        workstationAlerts.resolve(p.kind, p.subject);
        return;
      }

      // auth_ok — WS handshake complete, mark connected so polling stops
      if (event.type === "auth_ok") {
        setIsConnected(true);
        // #1798 — RESYNC on every successful (re)connect. The `?since=` replay
        // buffer covers the socket's gap only partly: it holds 60 SECONDS and
        // only the low-volume KDS event types (kitchen_printed,
        // item.status_changed, print_status). `order_created` /
        // `order_updated` are live-only, so a drop longer than the window — or
        // of an unreplayed type — leaves the board wrong with nothing to
        // correct it. Marking connected here is exactly what turns the 15s
        // polling fallback OFF (use-orders.ts), so this line must refill what
        // the socket missed or nothing will.
        //
        // Also what makes the workstation's #1793 behaviour work: it closes a
        // client whose buffer overflowed so that the client reconnects AND
        // refetches. Without this it only reconnects.
        void qc.invalidateQueries({ queryKey: ["kds", "orders"] });
        return;
      }
      // auth_fail — token rejected, mark disconnected
      if (event.type === "auth_fail") {
        setIsConnected(false);
        return;
      }

      // Dedup: skip own echoes already optimistically applied
      const payload = event.payload as { idempotency_key?: string } | null;
      const key = payload?.idempotency_key;
      if (key && recentBumpsRef.current.has(key)) return;

      switch (event.type) {
        case "order_created":
          playChime();
          void qc.invalidateQueries({ queryKey: ["kds", "orders"] });
          break;
        case "order.kitchen_printed":
          // Plan-038 T9.7 — pos-web (or handy) fired an order to the
          // kitchen. The card may already be in cache via the periodic
          // poll; invalidate so the fresh items + ticket_no surface
          // immediately. Play the chime so the cook hears it.
          playChime();
          void qc.invalidateQueries({ queryKey: ["kds", "orders"] });
          // Remember the time of the last received event so the next
          // WS reconnect can request "?since=<ts>" backfill.
          try {
            const ts =
              event.timestamp ??
              (event.payload as { fired_at?: string } | null)?.fired_at ??
              new Date().toISOString();
            localStorage.setItem("kds_last_event_at", ts);
          } catch {
            // localStorage write can fail in private-mode safari; non-fatal.
          }
          break;
        case "order_updated":
        case "order_paid":
        case "order_item.status_changed":
          void qc.invalidateQueries({ queryKey: ["kds", "orders"] });
          break;
        default:
          break;
      }
    });

    void client.connect();

    return () => {
      off();
      client.close();
      clientRef.current = null;
      setIsConnected(false);
    };
  }, [state, device, qc, playChime, connectionMode]);

  const ctx = useMemo<RealtimeCtx>(
    () => ({ recordBumpKey, isConnected }),
    [recordBumpKey, isConnected],
  );

  return <RealtimeContext.Provider value={ctx}>{children}</RealtimeContext.Provider>;
}
