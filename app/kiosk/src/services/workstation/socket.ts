/**
 * WebSocket client for workstation real-time events.
 *
 * Connects to ws://<workstation>/ws?token=<device_token> when workstation is
 * available, listens for broadcast events, reconnects with exponential backoff.
 *
 * Events from workstation match the Go hub broadcast format:
 *   { "type": "order_created" | "order_updated" | "menu_updated" | ..., "payload": {...} }
 */

type EventHandler = (payload: unknown) => void;

interface ServerMessage {
  type: string;
  payload?: unknown;
  timestamp?: string;
}

const RECONNECT_BASE_MS = 1_000;
const RECONNECT_MAX_MS = 30_000;
const HEARTBEAT_MS = 30_000;

export class WorkstationSocket {
  private ws: WebSocket | null = null;
  private url: string | null = null;
  private token: string | null = null;
  private handlers: Map<string, Set<EventHandler>> = new Map();
  private connectAttempts = 0;
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private heartbeatTimer: ReturnType<typeof setInterval> | null = null;
  private intentionalClose = false;
  private statusListeners: Set<(connected: boolean) => void> = new Set();
  private connected = false;

  /** Open connection to workstation. Idempotent — replaces existing connection. */
  connect(proxyUrl: string, token: string): void {
    const nextUrl = httpToWs(proxyUrl);
    if (this.ws && this.url === nextUrl && this.token === token) return;
    this.disconnect();
    this.url = nextUrl;
    this.token = token;
    this.intentionalClose = false;
    this.openOnce();
  }

  /** Close + clear reconnect state. */
  disconnect(): void {
    this.intentionalClose = true;
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    if (this.heartbeatTimer) {
      clearInterval(this.heartbeatTimer);
      this.heartbeatTimer = null;
    }
    if (this.ws) {
      try {
        this.ws.close();
      } catch {
        // ignore
      }
      this.ws = null;
    }
    this.setConnected(false);
  }

  on(type: string, handler: EventHandler): () => void {
    let set = this.handlers.get(type);
    if (!set) {
      set = new Set();
      this.handlers.set(type, set);
    }
    set.add(handler);
    return () => {
      set?.delete(handler);
    };
  }

  onStatusChange(cb: (connected: boolean) => void): () => void {
    this.statusListeners.add(cb);
    cb(this.connected);
    return () => {
      this.statusListeners.delete(cb);
    };
  }

  isConnected(): boolean {
    return this.connected;
  }

  private openOnce(): void {
    if (!this.url || !this.token) return;
    const sep = this.url.includes("?") ? "&" : "?";
    const fullUrl = `${this.url}${sep}token=${encodeURIComponent(this.token)}`;
    try {
      this.ws = new WebSocket(fullUrl);
    } catch (err) {
      // eslint-disable-next-line no-console
      console.warn("[workstation-socket] open failed", err);
      this.scheduleReconnect();
      return;
    }
    this.ws.onopen = () => {
      this.connectAttempts = 0;
      this.setConnected(true);
      this.startHeartbeat();
    };
    this.ws.onmessage = (ev) => this.dispatch(ev.data);
    this.ws.onerror = () => {
      // onerror is followed by onclose; we handle reconnect there.
    };
    this.ws.onclose = () => {
      this.setConnected(false);
      this.stopHeartbeat();
      this.ws = null;
      if (!this.intentionalClose) this.scheduleReconnect();
    };
  }

  private scheduleReconnect(): void {
    if (this.intentionalClose) return;
    const delay = Math.min(
      RECONNECT_BASE_MS * Math.pow(2, this.connectAttempts),
      RECONNECT_MAX_MS,
    );
    this.connectAttempts += 1;
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      this.openOnce();
    }, delay);
  }

  private startHeartbeat(): void {
    this.stopHeartbeat();
    this.heartbeatTimer = setInterval(() => {
      if (this.ws?.readyState === WebSocket.OPEN) {
        try {
          this.ws.send(JSON.stringify({ type: "ping" }));
        } catch {
          // ignore — next onclose will trigger reconnect
        }
      }
    }, HEARTBEAT_MS);
  }

  private stopHeartbeat(): void {
    if (this.heartbeatTimer) {
      clearInterval(this.heartbeatTimer);
      this.heartbeatTimer = null;
    }
  }

  private dispatch(raw: unknown): void {
    if (typeof raw !== "string") return;
    let msg: ServerMessage;
    try {
      msg = JSON.parse(raw) as ServerMessage;
    } catch {
      return;
    }
    if (!msg?.type) return;
    const set = this.handlers.get(msg.type);
    if (!set) return;
    for (const handler of set) {
      try {
        handler(msg.payload);
      } catch (err) {
        // eslint-disable-next-line no-console
        console.warn("[workstation-socket] handler threw", msg.type, err);
      }
    }
  }

  private setConnected(connected: boolean): void {
    if (this.connected === connected) return;
    this.connected = connected;
    for (const l of this.statusListeners) l(connected);
  }
}

function httpToWs(httpUrl: string): string {
  return httpUrl.replace(/^http:/, "ws:").replace(/^https:/, "wss:") + "/ws";
}

export const workstationSocket = new WorkstationSocket();
