import { createContext, useCallback, useContext, useEffect, useMemo, useRef, type ReactNode } from "react";
import { type WsEvent } from "./ws";

type Listener = (event: WsEvent) => void;

interface WsContextValue {
  subscribe: (fn: Listener) => () => void;
}

const WsContext = createContext<WsContextValue | null>(null);

// ── Singleton WS — lives outside React, survives HMR/StrictMode ──────────────

// Default to the page origin (ws:// or wss:// to match http/https) so realtime
// works both natively and through a tunnel/reverse proxy, mirroring api.ts.
const WS_URL =
  (import.meta as any).env?.VITE_WS_URL ??
  (typeof window !== "undefined"
    ? window.location.origin.replace(/^http/, "ws") + "/ws"
    : "ws://localhost:8080/ws");
const RECONNECT_BASE_MS = 2000;
const RECONNECT_MAX_MS = 30000;

const listeners = new Set<Listener>();
let ws: WebSocket | null = null;
let currentToken = "";
let delay = RECONNECT_BASE_MS;
let started = false;

function broadcast(event: WsEvent) {
  listeners.forEach((fn) => fn(event));
}

function connect() {
  if (ws) return; // already connected or connecting
  console.debug("[ws] connecting, token:", currentToken ? currentToken.slice(0, 8) + "…" : "(empty)");
  ws = new WebSocket(WS_URL);

  ws.onopen = () => {
    ws!.send(JSON.stringify({ type: "auth", payload: { token: currentToken } }));
  };

  ws.onmessage = (e) => {
    try {
      const msg: WsEvent = JSON.parse(e.data);
      if (msg.type === "auth_ok") {
        delay = RECONNECT_BASE_MS;
        console.debug("[ws] auth_ok");
        return;
      }
      if (msg.type === "ping") return;
      console.debug("[ws] event:", msg.type);
      broadcast(msg);
    } catch {
      // ignore malformed frames
    }
  };

  ws.onclose = (e) => {
    console.debug("[ws] closed", e.code, e.reason);
    ws = null;
    if (started) {
      setTimeout(() => {
        delay = Math.min(delay * 1.5, RECONNECT_MAX_MS);
        connect();
      }, delay);
    }
  };

  ws.onerror = () => {
    ws?.close();
  };
}

function startSingleton(token: string) {
  currentToken = token;
  if (started) {
    // Token updated — reconnect with new token
    if (ws) { ws.onclose = null; ws.close(); ws = null; }
    connect();
    return;
  }
  started = true;
  connect();
}

// ── Provider ─────────────────────────────────────────────────────────────────

export function WsProvider({ token, children }: { token: string; children: ReactNode }) {
  useEffect(() => {
    if (!token) return;
    startSingleton(token);
  }, [token]);

  const subscribe = useCallback((fn: Listener) => {
    listeners.add(fn);
    return () => listeners.delete(fn);
  }, []);

  const value = useMemo(() => ({ subscribe }), [subscribe]);

  return <WsContext value={value}>{children}</WsContext>;
}

// ── Hook ─────────────────────────────────────────────────────────────────────

export function useWsEvents(
  onEvent: (event: WsEvent) => void,
  eventTypes?: string[],
) {
  const ctx = useContext(WsContext);
  const onEventRef = useRef(onEvent);
  onEventRef.current = onEvent;

  useEffect(() => {
    if (!ctx) return;
    return ctx.subscribe((event) => {
      if (eventTypes && !eventTypes.includes(event.type)) return;
      onEventRef.current(event);
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ctx]);
}
