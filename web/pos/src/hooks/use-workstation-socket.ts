import { useEffect, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { getToken } from "@/lib/auth";
import { getWorkstationUrl, hasWorkstation } from "@/services/workstation/base-url-resolver";
import { workstationPrintService } from "@/services/workstation-print-service";
import { workstationAlerts } from "./workstation-alerts";
import { orderKeys, tableKeys } from "@/hooks/api/query-keys";
import { OPEN_ORDER_FILTERS, TAKEAWAY_ORDER_FILTERS } from "@/hooks/api/use-orders";
import { useTranslation } from "@/providers/app-provider";
import type { PaginatedResponse } from "@/lib/api";
import type { CustomerOrder } from "@/app/pos/types";

const RECONNECT_DELAY_MS = 3_000;
const MAX_RECONNECT_DELAY_MS = 30_000;

/**
 * Connects to the workstation WebSocket at /ws and keeps the React Query
 * cache up-to-date from real-time events. Mount once at POS page level.
 *
 * Events handled:
 *   order_synced  → a cloud-origin order (customer-web / kiosk) arrived or
 *                   changed via the workstation's pull-DOWN loop; invalidate
 *                   so the lists + detail refetch (payload is a signal only)
 *   order_created / order_updated / order_voided → patch list + detail
 *   order.code_assigned → swap provisional code for Cloud ORD-#### (plan-041)
 *   order_paid    → remove from open list (payload: {order_id, payment_id})
 *   order_item.status_changed → patch item status in detail + list entry
 *
 * Reconnects with exponential back-off on disconnect, and re-connects
 * immediately when the browser regains network connectivity.
 */
export function useWorkstationSocket(shopSlug: string): { isConnected: boolean } {
  const queryClient = useQueryClient();
  const { t } = useTranslation();
  const wsRef = useRef<WebSocket | null>(null);
  const reconnectTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const reconnectDelayRef = useRef(RECONNECT_DELAY_MS);
  const unmountedRef = useRef(false);
  const [isConnected, setIsConnected] = useState(false);

  // Must match the filter object in useOpenOrders exactly — same serialised
  // query key or every patch below silently writes to a cache entry nothing
  // reads. Imported rather than re-declared: the two copies of this literal
  // had already drifted once.
  const openFilters = OPEN_ORDER_FILTERS;

  // The takeaway drawer keeps its OWN feed (order_type=takeaway) so it isn't
  // crowded out of the 100-order open page. That query is a separate cache
  // entry, so the optimistic open-list patches below don't reach it — bust it
  // whenever a takeaway order changes so the overview badge + drawer stay live.
  const takeawayFeedKey = orderKeys.list(shopSlug, TAKEAWAY_ORDER_FILTERS);
  const bustTakeawayFeed = () =>
    queryClient.invalidateQueries({ queryKey: takeawayFeedKey });
  const takeawayFeedHas = (orderId: string) => {
    const feed =
      queryClient.getQueryData<PaginatedResponse<CustomerOrder>>(takeawayFeedKey);
    return !!feed?.data.some((o) => o.id === orderId);
  };

  useEffect(() => {
    if (!shopSlug) return;
    // No workstation configured (VITE_WORKSTATION_API_URL=none / empty) →
    // silent no-op. Spawning a socket against the "none" sentinel would
    // resolve relative to the POS tunnel and spin a failing reconnect
    // loop (#422).
    if (!hasWorkstation()) return;
    unmountedRef.current = false;

    function scheduleReconnect() {
      if (unmountedRef.current) return;
      const delay = reconnectDelayRef.current;
      reconnectDelayRef.current = Math.min(delay * 2, MAX_RECONNECT_DELAY_MS);
      reconnectTimerRef.current = setTimeout(() => {
        reconnectTimerRef.current = null;
        connect();
      }, delay);
    }

    function connect() {
      if (unmountedRef.current) return;

      if (wsRef.current) {
        wsRef.current.onclose = null;
        wsRef.current.close();
        wsRef.current = null;
      }

      const token = getToken();
      if (!token || unmountedRef.current) return;

      const url = getWorkstationUrl().replace(/^http/, "ws") + "/ws";
      let ws: WebSocket;
      try {
        ws = new WebSocket(url);
      } catch {
        scheduleReconnect();
        return;
      }
      wsRef.current = ws;

      ws.onopen = () => {
        ws.send(JSON.stringify({ type: "auth", payload: { token } }));
      };

      ws.onmessage = (e) => {
        let msg: { type: string; payload?: unknown };
        try {
          msg = JSON.parse(e.data as string);
        } catch {
          return;
        }

        switch (msg.type) {
          // #1806 S2 — trạng thái máy trạm.
          //
          // `alert.snapshot` THAY toàn bộ danh sách, không gộp: một sự cố đã
          // hết trong lúc socket chết sẽ không có `alert.resolved` nào tới, nên
          // gộp sẽ để lại banner đỏ vĩnh viễn cho vấn đề đã được sửa.
          case "alert.snapshot": {
            const list = (msg.payload as { alerts?: unknown[] })?.alerts ?? [];
            workstationAlerts.replace(list as never[]);
            break;
          }
          case "alert.raised":
            workstationAlerts.raise(msg.payload as never);
            break;
          case "alert.resolved": {
            const p = msg.payload as { kind: string; subject: string };
            workstationAlerts.resolve(p.kind, p.subject);
            break;
          }
          case "auth_ok":
            reconnectDelayRef.current = RECONNECT_DELAY_MS;
            setIsConnected(true);
            // #1798 — RESYNC. Everything below this line is a live patch; a
            // socket that was down received none of them, and nothing else
            // fills the hole: TanStack's refetchOnReconnect fires on NETWORK
            // events (navigator.onLine) and a dead WS is not a dead network,
            // and the #1792 polling fallback schedules its FIRST fetch one
            // interval (15s) after the drop — so a reconnect at 3s (the
            // backoff, which this very line resets) beats it and polling goes
            // back off having fetched nothing.
            //
            // This is also what makes the workstation's #1793 behaviour work:
            // it closes a client whose buffer overflowed precisely so the
            // client reconnects and refetches. Without this line it reconnects
            // and does not refetch, and the missed events are gone for good.
            //
            // Fires on the FIRST connect too, not just reconnects. Harmless —
            // the queries have just mounted and TanStack coalesces — and the
            // alternative (a "have connected before" flag) would be wrong in
            // exactly the rare case this exists for. The 3s backoff bounds how
            // often a flapping socket can trigger it.
            queryClient.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
            bustTakeawayFeed();
            queryClient.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
            break;

          // ── Cloud-origin order arrived / changed ────────────────────────
          // Emitted by the workstation's Cloud pull-DOWN loop for orders this
          // POS did not create: customer-web QR dine-in, customer-web takeaway,
          // kiosk. Without it those orders landed in the workstation's SQLite
          // and never surfaced here, because this hook being connected is
          // exactly what turns the list polling off (see pos/page.tsx).
          //
          // Payload is a signal ({order_id, is_new}), not the order: the
          // workstation shapes an order against the *requesting* client's
          // Accept-Language and rewrites image URLs onto the LAN image-cache
          // host, neither of which it can know from a background sync
          // goroutine. Invalidating lets the normal query refetch it correctly.
          case "order_synced": {
            const orderId = (msg.payload as { order_id?: string } | undefined)?.order_id;
            queryClient.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
            bustTakeawayFeed();
            queryClient.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
            if (orderId) {
              queryClient.invalidateQueries({
                queryKey: orderKeys.detail(shopSlug, orderId),
              });
            }
            break;
          }

          // ── Order-level events ──────────────────────────────────────────
          // Payload IS the full CustomerOrder — write directly into cache
          // without a network round-trip.
          case "order_created":
          case "order_updated":
          case "order_voided": {
            const order = msg.payload as CustomerOrder | undefined;
            if (!order?.id) {
              // Payload missing — fall back to invalidating everything
              queryClient.invalidateQueries({ queryKey: orderKeys.all(shopSlug) });
              break;
            }
            // Write full order into the detail cache slot ({ data: order })
            queryClient.setQueryData<{ data: CustomerOrder }>(
              orderKeys.detail(shopSlug, order.id),
              { data: order },
            );
            // Patch the open list (PaginatedResponse shape: { data[], links, meta })
            queryClient.setQueryData<PaginatedResponse<CustomerOrder>>(
              orderKeys.list(shopSlug, openFilters),
              (prev) => {
                if (!prev) return prev;
                if (msg.type === "order_voided") {
                  return { ...prev, data: prev.data.filter((o) => o.id !== order.id) };
                }
                const idx = prev.data.findIndex((o) => o.id === order.id);
                if (idx === -1) return { ...prev, data: [order, ...prev.data] };
                const next = [...prev.data];
                next[idx] = order;
                return { ...prev, data: next };
              },
            );
            queryClient.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
            // A takeaway order arriving / changing / being voided must refresh
            // the takeaway feed (the open-list patch above doesn't reach it).
            if (order.order_type === "takeaway" || takeawayFeedHas(order.id)) {
              bustTakeawayFeed();
            }
            break;
          }

          // ── plan-041 — Cloud-minted ORD code reconciled onto a LAN order ─
          // Payload: { id, order_code }. The order was created offline/LAN with
          // a provisional code; the workstation just synced it and adopted the
          // Cloud-assigned ORD-#### value. Swap it in both cache slots so the
          // cashier sees the final code without a refetch.
          case "order.code_assigned": {
            const p = msg.payload as { id?: string; order_code?: string } | undefined;
            const orderId = p?.id;
            const newCode = p?.order_code;
            if (!orderId || !newCode) break;

            // D1 — was this order already paid while it carried the provisional
            // code? If so, any printed receipt shows the stale code; offer a
            // reprint with the finalized ORD-####. Cashier-initiated (a toast
            // action) to avoid surprise paper. Read the prior cache state
            // BEFORE patching; if the order isn't cached we can't tell, so we
            // stay silent rather than risk a false prompt.
            const prevDetail = queryClient.getQueryData<{ data: CustomerOrder }>(
              orderKeys.detail(shopSlug, orderId),
            );
            const prevList = queryClient.getQueryData<PaginatedResponse<CustomerOrder>>(
              orderKeys.list(shopSlug, openFilters),
            );
            const prevOrder =
              prevDetail?.data ?? prevList?.data.find((o) => o.id === orderId);
            const wasPaid = Number(prevOrder?.paid_amount ?? 0) > 0;

            queryClient.setQueryData<{ data: CustomerOrder }>(
              orderKeys.detail(shopSlug, orderId),
              (prev) =>
                prev ? { ...prev, data: { ...prev.data, order_code: newCode } } : prev,
            );
            queryClient.setQueryData<PaginatedResponse<CustomerOrder>>(
              orderKeys.list(shopSlug, openFilters),
              (prev) => {
                if (!prev) return prev;
                const data = prev.data.map((o) =>
                  o.id === orderId ? { ...o, order_code: newCode } : o,
                );
                return { ...prev, data };
              },
            );

            if (wasPaid && workstationPrintService.enabled) {
              toast(t("pos.order.code_finalized", { code: newCode }), {
                action: {
                  label: t("pos.order.reprint_receipt"),
                  onClick: () => {
                    void workstationPrintService
                      .printPaymentReceipt({ orderId, reprintReason: "code_finalized" })
                      .catch(() => toast.error(t("pos.order.reprint_failed")));
                  },
                },
              });
            }
            // Finalized code on a takeaway order → refresh the drawer's copy.
            if (prevOrder?.order_type === "takeaway" || takeawayFeedHas(orderId)) {
              bustTakeawayFeed();
            }
            break;
          }

          // ── Payment event ───────────────────────────────────────────────
          // Server payload: { order_id, payment_id } — NOT a full CustomerOrder.
          case "order_paid": {
            const p = msg.payload as { order_id?: string; payment_id?: string } | undefined;
            const orderId = p?.order_id;
            if (orderId) {
              queryClient.setQueryData<PaginatedResponse<CustomerOrder>>(
                orderKeys.list(shopSlug, openFilters),
                (prev) =>
                  prev
                    ? { ...prev, data: prev.data.filter((o) => o.id !== orderId) }
                    : prev,
              );
              // A paid takeaway order drops out of the active feed — refresh so
              // the badge count + drawer stop showing it. Guarded on membership
              // so a dine-in payment doesn't trigger a needless takeaway refetch.
              if (takeawayFeedHas(orderId)) bustTakeawayFeed();
            }
            queryClient.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
            break;
          }

          // ── Item-level event (from KDS) ─────────────────────────────────
          // Payload: { order_id, item_id, status, ... }
          case "order_item.status_changed": {
            const p = msg.payload as {
              order_id?: string;
              item_id?: string;
              status?: string;
            } | undefined;
            const orderId = p?.order_id;
            const itemId = p?.item_id;
            const newStatus = p?.status;
            if (!orderId || !itemId || !newStatus) break;

            // Patch item inside cached detail
            queryClient.setQueryData<{ data: CustomerOrder }>(
              orderKeys.detail(shopSlug, orderId),
              (prev) => {
                if (!prev) return prev;
                const items = prev.data.items?.map((item) =>
                  item.id === itemId
                    ? { ...item, status: newStatus as typeof item.status }
                    : item,
                );
                return { ...prev, data: { ...prev.data, items } };
              },
            );
            // Patch matching entry in the list
            queryClient.setQueryData<PaginatedResponse<CustomerOrder>>(
              orderKeys.list(shopSlug, openFilters),
              (prev) => {
                if (!prev) return prev;
                const data = prev.data.map((o) => {
                  if (o.id !== orderId) return o;
                  const items = o.items?.map((item) =>
                    item.id === itemId
                      ? { ...item, status: newStatus as typeof item.status }
                      : item,
                  );
                  return { ...o, items };
                });
                return { ...prev, data };
              },
            );
            break;
          }

          default:
            break;
        }
      };

      ws.onerror = () => {};

      ws.onclose = () => {
        setIsConnected(false);
        // #1806 S2 — mất kết nối tới máy trạm nghĩa là KHÔNG BIẾT GÌ, không
        // phải "không có sự cố". Giữ nguyên banner cũ sẽ hiển thị một trạng
        // thái có thể đã hết từ lâu, và người dùng học được rằng nó không đáng
        // tin. `alert.snapshot` lúc kết nối lại sẽ dựng lại sự thật.
        workstationAlerts.clear();
        if (!unmountedRef.current) scheduleReconnect();
      };
    }

    connect();

    const onOnline = () => {
      if (reconnectTimerRef.current) clearTimeout(reconnectTimerRef.current);
      reconnectTimerRef.current = null;
      reconnectDelayRef.current = RECONNECT_DELAY_MS;
      connect();
    };
    window.addEventListener("online", onOnline);

    return () => {
      unmountedRef.current = true;
      setIsConnected(false);
      window.removeEventListener("online", onOnline);
      if (reconnectTimerRef.current) clearTimeout(reconnectTimerRef.current);
      if (wsRef.current) {
        wsRef.current.onclose = null;
        wsRef.current.close();
        wsRef.current = null;
      }
    };
  }, [shopSlug, queryClient]); // eslint-disable-line react-hooks/exhaustive-deps

  return { isConnected };
}
