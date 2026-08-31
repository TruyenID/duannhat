/**
 * Plan-023 M2 T2.2 — Echo/Pusher stub for Playwright specs.
 *
 * The real realtime path imports laravel-echo + pusher-js, opens a WS to
 * the per-brand Reverb app, and listens on `user.{userId}.notifications`
 * (see admin-web/src/hooks/notifications/use-notification-realtime.ts).
 * Running that against the test runner would require a live Reverb
 * server — out of scope for CI. Instead we:
 *
 *   1. Replace `window.Pusher` + `window.Echo` constructors with stubs
 *      that record listeners synchronously into `window.__echoListeners`.
 *   2. Expose `emitEcho(page, channel, event, payload)` so a spec can
 *      drive the listener synchronously, no WS handshake required.
 *
 * The hook's WS-disconnect fallback (10s timeout → 60s polling) is
 * disabled by the stub because the stub never declares itself
 * "disconnected". Specs that care about that branch should mock the
 * connection state explicitly.
 */
import type { Page } from "@playwright/test";

declare global {
  interface Window {
    __echoListeners?: Record<string, (payload: unknown) => void>;
    __echoChannels?: Record<string, { listen: (event: string, cb: (payload: unknown) => void) => unknown }>;
    Pusher?: unknown;
    Echo?: unknown;
  }
}

/**
 * Install the Echo + Pusher stubs on `window` before any page script runs.
 * Call this once per spec in `test.beforeEach` (after `signInAs`).
 */
export async function installEchoStub(page: Page): Promise<void> {
  await page.addInitScript(() => {
    window.__echoListeners = {};
    window.__echoChannels = {};

    function makeChannel(name: string) {
      const channel = {
        listen(event: string, cb: (payload: unknown) => void) {
          const key = `${name}::${event}`;
          window.__echoListeners![key] = cb;
          return channel;
        },
        notification(cb: (payload: unknown) => void) {
          window.__echoListeners![`${name}::.notification`] = cb;
          return channel;
        },
        stopListening() {
          return channel;
        },
        leave() {
          delete window.__echoChannels![name];
        },
      };
      window.__echoChannels![name] = channel;
      return channel;
    }

    // Minimal Pusher constructor — the real hook reads its `connection`
    // object for connected/disconnected events. We expose a no-op bind.
    class PusherStub {
      connection = {
        bind: (_event: string, _cb: () => void) => {
          // No-op: stub never emits connection events.
        },
      };
    }
    window.Pusher = PusherStub;

    // Minimal Echo constructor — only the methods the hook touches.
    class EchoStub {
      connector = { pusher: new PusherStub() };
      private(name: string) {
        return makeChannel(`private-${name}`);
      }
      channel(name: string) {
        return makeChannel(name);
      }
      leave(name: string) {
        delete window.__echoChannels![name];
      }
      disconnect() {
        window.__echoListeners = {};
        window.__echoChannels = {};
      }
    }
    window.Echo = EchoStub;
  });
}

/**
 * Drive a registered listener as if a server-side broadcast arrived.
 * Use within a spec after `installEchoStub` + page navigation.
 */
export async function emitEcho(page: Page, channel: string, event: string, payload: unknown): Promise<void> {
  await page.evaluate(
    ([c, e, p]) => {
      const key = `private-${c}::${e}`;
      const cb = window.__echoListeners?.[key];
      if (cb) cb(p);
    },
    [channel, event, payload] as const,
  );
}

/**
 * Read the list of channels the page has subscribed to so far. Useful to
 * assert that a mount fired its `private(...)` call before we emit.
 */
export async function subscribedChannels(page: Page): Promise<string[]> {
  return page.evaluate(() => Object.keys(window.__echoChannels ?? {}));
}
