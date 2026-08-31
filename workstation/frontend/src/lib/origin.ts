/**
 * Runtime origin helpers — the single source of truth for "where is the Go
 * server?" in the frontend.
 *
 * The workstation frontend is a STATIC bundle embedded into the Go binary. It
 * cannot read WS_APP_SERVER_PORT from .env directly — that value lives in the
 * Go process. The port reaches the frontend through exactly one channel: the
 * Wails webview is opened at `http://localhost:<WS_APP_SERVER_PORT>` (see
 * cmd/workstation/main.go), so `window.location` already carries the real,
 * .env-configured port. Deriving from window.location is therefore how the
 * frontend "reads the port from .env" — never hardcode a port literal.
 *
 * The non-browser branch (`typeof window === "undefined"`) is only reached by
 * unit tests / SSR, where there is no server at all, so it deliberately carries
 * no port. It is never hit inside the webview.
 */

/** Base URL for REST calls, e.g. `http://localhost:6868`. */
export function httpOrigin(): string {
  if (typeof window !== "undefined") return window.location.origin;
  return "http://localhost"; // tests/SSR only — no server, port irrelevant
}

/** Base URL for WebSocket calls, e.g. `ws://localhost:6868` (or wss for https). */
export function wsOrigin(): string {
  if (typeof window !== "undefined") {
    // ws:// for http, wss:// for https — matches the page scheme so realtime
    // works natively AND through an https tunnel/reverse proxy.
    return window.location.origin.replace(/^http/, "ws");
  }
  return "ws://localhost"; // tests/SSR only
}
