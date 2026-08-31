/**
 * WebSocket client for the workstation hub.
 *
 * Protocol:
 *   connect → send {type:"auth", payload:{token}} → receive {type:"auth_ok"}
 *   then receive {type, payload, timestamp} broadcast events
 */

export type WsEvent = {
  type: string;
  payload: unknown;
  timestamp: string;
};

type EventHandler = (event: WsEvent) => void;

const WS_URL =
  (import.meta as any).env?.VITE_WS_URL ?? "ws://localhost:6969/ws";

const RECONNECT_BASE_MS = 2000;
const RECONNECT_MAX_MS = 30000;

export function createWsClient(onEvent: EventHandler, token: string) {
  let ws: WebSocket | null = null;
  let stopped = false;
  let delay = RECONNECT_BASE_MS;
  let timer: ReturnType<typeof setTimeout> | null = null;

  function connect() {
    if (stopped) return;
    console.debug("[ws] connecting", WS_URL, "token:", token ? token.slice(0, 8) + "…" : "(empty)");
    ws = new WebSocket(WS_URL);

    ws.onopen = () => {
      ws!.send(JSON.stringify({ type: "auth", payload: { token } }));
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
        console.debug("[ws] event:", msg.type, msg.payload);
        onEvent(msg);
      } catch {
        // ignore malformed frames
      }
    };

    ws.onclose = (e) => {
      console.debug("[ws] closed", e.code, e.reason);
      ws = null;
      if (!stopped) schedule();
    };

    ws.onerror = (e) => {
      console.error("[ws] error", e);
      ws?.close();
    };
  }

  function schedule() {
    timer = setTimeout(() => {
      delay = Math.min(delay * 1.5, RECONNECT_MAX_MS);
      connect();
    }, delay);
  }

  function disconnect() {
    stopped = true;
    if (timer) clearTimeout(timer);
    if (ws) {
      // Avoid "closed before connection established" — only close if past CONNECTING
      if (ws.readyState === WebSocket.CONNECTING) {
        ws.onopen = null;
        ws.onclose = null;
        ws.onerror = null;
        ws.onmessage = null;
        // Let it open then immediately close
        const _ws = ws;
        _ws.addEventListener("open", () => _ws.close());
      } else {
        ws.close();
      }
      ws = null;
    }
  }

  return { connect, disconnect };
}
