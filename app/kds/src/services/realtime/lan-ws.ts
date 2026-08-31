import { WORKSTATION_URL } from "../base-url-resolver";

export interface RealtimeEvent {
  type: string;
  payload: unknown;
  timestamp?: string;
}

export type Listener = (event: RealtimeEvent) => void;

interface WebSocketFactory {
  (url: string): WebSocket;
}

const DEFAULT_FACTORY: WebSocketFactory = (u) => new WebSocket(u);
const INITIAL_RECONNECT_MS = 1_000;
const MAX_RECONNECT_MS = 30_000;

/**
 * LanWsClient — WebSocket client to workstation /ws endpoint.
 *
 * Protocol (matches workstation Task 2.4 + 2.3):
 *  1. Open WS
 *  2. Send {type:"auth", payload:{token: deviceToken}} within 5s
 *  3. Server replies {type:"auth_ok"} or closes 4401 (bad) / 4408 (timeout)
 *  4. After auth_ok: receive branch-scoped events (order_created,
 *     order_updated, order_paid, order_item.status_changed)
 *
 * Reconnect with exponential backoff (1s..30s) on close/error.
 * Server-initiated 4401 (revoked token) stops auto-reconnect.
 */
export class LanWsClient {
  private ws: WebSocket | null = null;
  private listeners = new Set<Listener>();
  private reconnectMs = INITIAL_RECONNECT_MS;
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private closed = false;
  private authedOk = false;
  private factory: WebSocketFactory;
  private token: string;
  private host: string;

  constructor(
    token: string,
    host: string = WORKSTATION_URL,
    factory: WebSocketFactory = DEFAULT_FACTORY,
  ) {
    this.token = token;
    this.host = host;
    this.factory = factory;
  }

  /** Subscribe to events. Returns unsubscribe function. */
  on(listener: Listener): () => void {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  /** Open the connection. Idempotent — calling on an already-open client is a no-op. */
  connect(): void {
    if (this.closed) return;
    if (this.ws && this.ws.readyState === WebSocket.OPEN) return;

    // Plan-038 T9.3 — pass `?since=<ISO>` so workstation replays any
    // KDS-relevant events the tablet missed during the disconnect window
    // (default 60s server-side). Cursor is the last event timestamp we
    // saw, persisted by RealtimeProvider in localStorage.
    let wsUrl = this.host.replace(/^http/, "ws") + "/ws";
    try {
      const last =
        typeof localStorage !== "undefined"
          ? localStorage.getItem("kds_last_event_at")
          : null;
      if (last) {
        wsUrl += "?since=" + encodeURIComponent(last);
      }
    } catch {
      // SecurityError in private browsing — proceed without replay.
    }

    this.ws = this.factory(wsUrl);
    this.authedOk = false;

    this.ws.addEventListener("open", () => this.handleOpen());
    this.ws.addEventListener("message", (e) => this.handleMessage((e as MessageEvent).data));
    this.ws.addEventListener("close", (e) => this.handleClose((e as CloseEvent).code, (e as CloseEvent).reason));
    this.ws.addEventListener("error", () => this.handleError());
  }

  /** Close the connection permanently. Idempotent — safe to call multiple times. */
  close(): void {
    this.closed = true;
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    if (this.ws) {
      try {
        this.ws.close(1000, "client close");
      } catch {
        // ignore
      }
      this.ws = null;
    }
    this.authedOk = false;
  }

  /** True if WebSocket open + auth_ok received. */
  get isReady(): boolean {
    return this.authedOk && this.ws?.readyState === WebSocket.OPEN;
  }

  // ─── private ─────────────────────────────────────────────────────────────

  private handleOpen(): void {
    // Send auth as first message
    this.ws?.send(
      JSON.stringify({
        type: "auth",
        payload: { token: this.token },
      }),
    );
  }

  private handleMessage(data: unknown): void {
    if (typeof data !== "string") return;
    let parsed: RealtimeEvent;
    try {
      parsed = JSON.parse(data) as RealtimeEvent;
    } catch {
      return;
    }

    if (parsed.type === "auth_ok") {
      this.authedOk = true;
      this.reconnectMs = INITIAL_RECONNECT_MS;
      // Notify listeners so RealtimeProvider can set isConnected = true
      for (const listener of this.listeners) {
        try { listener(parsed); } catch { /* ignore */ }
      }
      return;
    }
    if (parsed.type === "auth_fail") {
      this.closed = true;
      for (const listener of this.listeners) {
        try { listener(parsed); } catch { /* ignore */ }
      }
      return;
    }

    // Regular event — fan out to listeners
    for (const listener of this.listeners) {
      try {
        listener(parsed);
      } catch (err) {
        console.error("[LanWsClient] listener error", err);
      }
    }
  }

  private handleClose(code: number, _reason: string): void {
    this.authedOk = false;
    this.ws = null;

    // 4401 = bad token (server-initiated, won't fix on retry)
    if (code === 4401) {
      this.closed = true;
      return;
    }

    this.scheduleReconnect();
  }

  private handleError(): void {
    // Browser doesn't expose error details on WebSocket errors —
    // close event will follow and trigger reconnect logic
    this.authedOk = false;
  }

  private scheduleReconnect(): void {
    if (this.closed) return;
    if (this.reconnectTimer) return;

    const delay = this.reconnectMs;
    this.reconnectMs = Math.min(this.reconnectMs * 2, MAX_RECONNECT_MS);

    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      this.connect();
    }, delay);
  }
}
