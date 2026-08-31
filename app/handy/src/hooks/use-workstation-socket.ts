import React, { useEffect, useRef } from 'react';
import { AppState } from 'react-native';
import { useQueryClient } from '@tanstack/react-query';

import { getToken, getWorkstationUrl } from '@/lib/auth';
import { useDevice } from '@/lib/device-context';
import type { CustomerOrder, OrderItemStatus } from '@/types/pos';

const RECONNECT_DELAY_MS = 3_000;
const MAX_RECONNECT_DELAY_MS = 30_000;

/** Returns true once WS is open and auth_ok received. */
export function useWorkstationSocket(shopSlug: string): { isConnected: boolean } {
  const queryClient = useQueryClient();
  const { shopSlug: deviceShopSlug } = useDevice();
  const wsRef = useRef<WebSocket | null>(null);
  const reconnectTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const reconnectDelayRef = useRef(RECONNECT_DELAY_MS);
  const unmountedRef = useRef(false);
  const [isConnected, setIsConnected] = React.useState(false);

  const slug = shopSlug || deviceShopSlug;

  useEffect(() => {
    if (!slug) return;
    unmountedRef.current = false;

    async function connect() {
      if (unmountedRef.current) return;

      if (wsRef.current) {
        wsRef.current.onclose = null;
        wsRef.current.close();
        wsRef.current = null;
      }

      const [wsUrl, token] = await Promise.all([getWorkstationUrl(), getToken()]);
      if (!wsUrl || !token || unmountedRef.current) return;

      const url = wsUrl.replace(/^http/, 'ws') + '/ws';
      if (__DEV__) console.log('[ws] connecting', url, 'slug=', slug);
      let ws: WebSocket;
      try {
        ws = new WebSocket(url);
      } catch (err) {
        if (__DEV__) console.log('[ws] construct failed', err);
        scheduleReconnect();
        return;
      }
      wsRef.current = ws;

      ws.onopen = () => {
        if (__DEV__) console.log('[ws] open → sending auth');
        ws.send(JSON.stringify({ type: 'auth', payload: { token } }));
      };

      ws.onmessage = (e) => {
        let msg: { type: string; payload?: unknown };
        try {
          msg = JSON.parse(e.data as string);
        } catch {
          return;
        }

        if (__DEV__) console.log('[ws] recv', msg.type);

        switch (msg.type) {
          case 'auth_ok':
            reconnectDelayRef.current = RECONNECT_DELAY_MS;
            setIsConnected(true);
            break;

          // ─── Order-level events ────────────────────────────────────────────
          // WS payload IS the full CustomerOrder object — write directly into
          // cache without a network round-trip.
          case 'order_created':
          case 'order_updated':
          case 'order_voided': {
            const order = msg.payload as CustomerOrder | undefined;
            if (!order?.id) {
              // Payload missing — fall back to invalidating the list
              queryClient.invalidateQueries({ queryKey: ['orders', slug, 'list'] });
              break;
            }
            // 1. Write full order into the detail cache slot
            queryClient.setQueryData<CustomerOrder>(
              ['orders', slug, 'detail', order.id],
              order,
            );
            // 2. Patch the list cache: replace the matching entry or prepend
            queryClient.setQueryData<CustomerOrder[]>(
              ['orders', slug, 'list', { status: 'open,dining' }],
              (prev) => {
                if (!prev) return prev;
                if (msg.type === 'order_voided') {
                  return prev.filter((o) => o.id !== order.id);
                }
                const idx = prev.findIndex((o) => o.id === order.id);
                if (idx === -1) return [order, ...prev];
                const next = [...prev];
                next[idx] = order;
                return next;
              },
            );
            // 3. Tables may have changed (table freed/occupied)
            queryClient.invalidateQueries({ queryKey: ['tables', slug] });
            break;
          }

          case 'order_paid': {
            // Server payload is { order_id, payment_id } — NOT a full CustomerOrder.
            const p = msg.payload as { order_id?: string; payment_id?: string } | undefined;
            const orderId = p?.order_id;
            if (orderId) {
              queryClient.setQueryData<CustomerOrder[]>(
                ['orders', slug, 'list', { status: 'open,dining' }],
                (prev) => prev?.filter((o) => o.id !== orderId),
              );
            }
            queryClient.invalidateQueries({ queryKey: ['tables', slug] });
            break;
          }

          // ─── Item-level event (from KDS) ───────────────────────────────────
          // Payload is { order_id, item_id, status, ... } — not a full order.
          // Patch only the affected item inside the detail cache; if the detail
          // is not cached yet (order not yet visited), do nothing — useFocusEffect
          // will fetch on first navigate.
          case 'order_item.status_changed': {
            const p = msg.payload as {
              order_id?: string;
              item_id?: string;
              status?: string;
            } | undefined;
            const orderId = p?.order_id;
            const itemId = p?.item_id;
            const newStatus = p?.status;

            if (!orderId || !itemId || !newStatus) break;

            // Patch the item inside the cached order detail
            queryClient.setQueryData<CustomerOrder>(
              ['orders', slug, 'detail', orderId],
              (prev) => {
                if (!prev) return prev; // not cached — skip, useFocusEffect handles it
                const items = prev.items?.map((item) =>
                  item.id === itemId
                    ? { ...item, status: newStatus as OrderItemStatus }
                    : item,
                );
                return { ...prev, items };
              },
            );

            // Also patch the list entry so TableCard dot badge updates
            queryClient.setQueryData<CustomerOrder[]>(
              ['orders', slug, 'list', { status: 'open,dining' }],
              (prev) => {
                if (!prev) return prev;
                return prev.map((o) => {
                  if (o.id !== orderId) return o;
                  const items = o.items?.map((item) =>
                    item.id === itemId ? { ...item, status: newStatus as typeof item.status } : item,
                  );
                  return { ...o, items };
                });
              },
            );
            break;
          }

          // ─── Provisional → final code swap (plan-041) ─────────────────────
          // Workstation broadcasts this after an order.create sync reconciles
          // the Cloud-minted ORD-#### code back onto the local order. Swap the
          // provisional WS-#### code in both caches so the UI shows the
          // canonical code (mirrors pos-web's handler).
          case 'order.code_assigned': {
            const p = msg.payload as { id?: string; order_code?: string } | undefined;
            const orderId = p?.id;
            const newCode = p?.order_code;
            if (!orderId || !newCode) break;

            queryClient.setQueryData<CustomerOrder>(
              ['orders', slug, 'detail', orderId],
              (prev) => (prev ? { ...prev, order_code: newCode } : prev),
            );
            queryClient.setQueryData<CustomerOrder[]>(
              ['orders', slug, 'list', { status: 'open,dining' }],
              (prev) =>
                prev?.map((o) => (o.id === orderId ? { ...o, order_code: newCode } : o)),
            );
            break;
          }

          default:
            break;
        }
      };

      ws.onerror = (err) => {
        if (__DEV__) console.log('[ws] error', (err as { message?: string })?.message ?? err);
      };

      ws.onclose = (ev) => {
        if (__DEV__) console.log('[ws] close', ev?.code, ev?.reason);
        setIsConnected(false);
        if (!unmountedRef.current) scheduleReconnect();
      };
    }

    function scheduleReconnect() {
      if (unmountedRef.current) return;
      const delay = reconnectDelayRef.current;
      reconnectDelayRef.current = Math.min(delay * 2, MAX_RECONNECT_DELAY_MS);
      reconnectTimerRef.current = setTimeout(() => {
        reconnectTimerRef.current = null;
        connect();
      }, delay);
    }

    connect();

    const appStateSub = AppState.addEventListener('change', (next) => {
      if (next === 'active') {
        if (reconnectTimerRef.current) clearTimeout(reconnectTimerRef.current);
        reconnectTimerRef.current = null;
        reconnectDelayRef.current = RECONNECT_DELAY_MS;
        connect();
      }
    });

    return () => {
      unmountedRef.current = true;
      setIsConnected(false);
      appStateSub.remove();
      if (reconnectTimerRef.current) clearTimeout(reconnectTimerRef.current);
      if (wsRef.current) {
        wsRef.current.onclose = null;
        wsRef.current.close();
        wsRef.current = null;
      }
    };
  }, [slug, queryClient]);

  return { isConnected };
}
