import { useEffect, useRef } from "react";

/**
 * TerminalBridge — hidden host for the Verifone P400 (VescaJS) card terminal.
 *
 * pos-web is a browser and can't open ws:// to the P400 (mixed-content), so the
 * workstation bridges it. This component (always mounted in the workstation UI)
 * runs the VescaJS FullFeatured-WS SDK inside a hidden iframe (`vesca-bridge.html`,
 * reused from godx-kiosk) and shuttles between the Go relay and the P400:
 *
 *   Go /api/terminal/next  ──►  postMessage REQUEST  ──►  iframe (VescaJS) ──ws──►  P400
 *   Go /api/terminal/result ◄──  window message RESULT/ERROR  ◄─────────────────────┘
 *
 * See docs/guide/pos-card-terminal-p400-vesca.md. The endpoints are localOnly and
 * same-origin (the frontend is served by the workstation LAN server), so plain
 * relative fetch is used with no auth.
 */

type NextCommand = {
  session_id: string;
  cancel: boolean;
  request?: Record<string, unknown>;
  host: string;
  port: number;
};

const POLL_MS = 1200; // Go returns 204 while idle/processing; ≥1s is plenty.

export function TerminalBridge() {
  const iframeRef = useRef<HTMLIFrameElement | null>(null);
  const readyRef = useRef(false);
  // The session currently driven on the terminal — used to correlate the
  // RESULT/ERROR the iframe posts back with the Go session to report to.
  const sessionRef = useRef<string | null>(null);

  useEffect(() => {
    let stopped = false;

    async function postResult(sessionId: string, body: Record<string, unknown>) {
      try {
        await fetch("/api/terminal/result", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ session_id: sessionId, ...body }),
        });
      } catch {
        /* transient — Go/pos-web will observe the stuck session and retry/cancel */
      }
    }

    // Messages FROM the iframe (VescaJS bridge).
    function onMessage(e: MessageEvent) {
      if (e.source !== iframeRef.current?.contentWindow) return;
      let msg: { type?: string; data?: unknown };
      try {
        msg = typeof e.data === "string" ? JSON.parse(e.data) : e.data;
      } catch {
        return;
      }
      const sid = sessionRef.current;
      switch (msg.type) {
        case "READY":
          readyRef.current = true;
          break;
        case "RESULT":
          if (sid) {
            sessionRef.current = null;
            void postResult(sid, { result: (msg.data as Record<string, unknown>) ?? {} });
          }
          break;
        case "ERROR":
        case "INIT_ERROR":
          if (sid) {
            sessionRef.current = null;
            void postResult(sid, { error: describeError(msg) });
          }
          break;
        // STATUS_EVENT (S507 …) is progress only; pos-web polls Go for status.
      }
    }
    window.addEventListener("message", onMessage);

    function toIframe(payload: Record<string, unknown>) {
      iframeRef.current?.contentWindow?.postMessage(JSON.stringify(payload), "*");
    }

    // Poll the Go relay for the next command.
    async function poll() {
      if (stopped) return;
      try {
        const res = await fetch("/api/terminal/next", { headers: { Accept: "application/json" } });
        if (res.status === 200) {
          const { data } = (await res.json()) as { data: NextCommand };
          if (data.cancel) {
            toIframe({ type: "CANCEL" });
          } else if (data.request && readyRef.current) {
            sessionRef.current = data.session_id;
            toIframe({
              type: "REQUEST",
              host: data.host,
              port: data.port,
              request: data.request,
            });
          }
        }
      } catch {
        /* workstation server transiently unreachable — retry next tick */
      } finally {
        if (!stopped) window.setTimeout(poll, POLL_MS);
      }
    }
    const startTimer = window.setTimeout(poll, POLL_MS);

    return () => {
      stopped = true;
      window.clearTimeout(startTimer);
      window.removeEventListener("message", onMessage);
    };
  }, []);

  return (
    <iframe
      ref={iframeRef}
      src="/vesca-bridge.html"
      title="card-terminal-bridge"
      aria-hidden
      tabIndex={-1}
      style={{ position: "absolute", width: 0, height: 0, border: 0, visibility: "hidden" }}
    />
  );
}

function describeError(msg: { message?: string; data?: unknown }): string {
  if (msg.message) return msg.message;
  const d = msg.data as { ErrorEvent?: { Message?: string; ErrorCode?: number } } | undefined;
  if (d?.ErrorEvent) return d.ErrorEvent.Message ?? `terminal error ${d.ErrorEvent.ErrorCode ?? ""}`.trim();
  return "card terminal error";
}
