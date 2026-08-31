/**
 * Verifone P400 (VescaJS) card-terminal service.
 *
 * The P400 lives on the LAN behind the workstation (pos-web can't reach it
 * directly). pos-web starts an async charge on the workstation, which drives the
 * terminal via its Wails frontend, then polls for the result. On approval the
 * workstation has ALREADY recorded the card payment — pos-web just refreshes the
 * order. See docs/guide/pos-card-terminal-p400-vesca.md.
 *
 * Addressed like every other LAN device — straight at the workstation, never
 * through apiFetch. The card terminal used to be the one exception, so the
 * Cloud/LAN toggle decided whether card payments worked at all: in Cloud mode
 * the charge went to the backend, which has no `/pos/terminal/*` route, and the
 * cashier got a raw framework 404. The printer
 * (workstation-print-service) and the 釣銭機 (workstation-cash-changer-service)
 * never had that problem because they resolve the workstation directly. The
 * routing mode is about where ORDERS live; it was never a statement about where
 * the hardware in this room is.
 *
 * Cloud cannot be a fallback here: the P400 sits on the shop LAN behind NAT and
 * only the workstation can open ws:// to it, so `hasWorkstation()` gating is the
 * honest failure mode rather than a retry against a host that has no such route.
 */
import { ApiError } from "@/lib/api";
import { getToken } from "@/lib/auth";
import {
  getWorkstationUrl,
  hasWorkstation,
} from "./workstation/base-url-resolver";

const BASE = "/api/v1/pos/terminal/charge";

/** Live-read, not cached: the operator can re-pair a workstation mid-shift. */
function currentBase(): string {
  return hasWorkstation() ? getWorkstationUrl().replace(/\/+$/, "") : "";
}

/** True when a workstation is configured — the only place the P400 is reachable. */
export function cardTerminalAvailable(): boolean {
  return currentBase() !== "";
}

async function call<T>(path: string, init: RequestInit = {}): Promise<T> {
  const base = currentBase();
  if (!base) throw new ApiError(0, { message: "workstation unreachable" });

  let res: Response;
  try {
    // eslint-disable-next-line no-restricted-globals -- LAN-only endpoint: apiFetch resolves a Cloud base URL, and /api/v1/pos/terminal/* exists ONLY on the workstation
    res = await fetch(`${base}${path}`, {
      ...init,
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${getToken() ?? ""}`,
        ...(init.headers ?? {}),
      },
    });
  } catch {
    // A LAN fetch failure is not an HTTP status — synthesise 0 so callers can
    // tell "workstation is off" from "the terminal refused", same as print does.
    throw new ApiError(0, { message: "workstation unreachable" });
  }

  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(res.status, body);
  return body as T;
}

export type TerminalStatus =
  | "queued"
  | "processing"
  | "approved"
  | "declined"
  /**
   * The workstation gave up on the session: its driver stopped reporting, the
   * charge ran past the 15-minute ceiling, or staff abandoned it. The card MAY
   * already have been captured, which is exactly why this is not `declined` —
   * staff must read the P400 slip before charging again. A result arriving late
   * still records the payment (the bridge keeps recent sessions addressable).
   */
  | "unknown"
  | "canceled";

export interface TerminalSnapshot {
  session_id: string;
  order_id: string;
  status: TerminalStatus;
  payment_id: string;
  amount: number;
  error: string;
  /** Settled by the workstation rather than by a terminal result. */
  expired?: boolean;
  started_at?: string;
  ended_at?: string;
}

function isTerminal(s: TerminalStatus): boolean {
  // `unknown` MUST be terminal. Left out, the poll below spins forever on a
  // session the workstation has already closed.
  return (
    s === "approved" || s === "declined" || s === "canceled" || s === "unknown"
  );
}

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

/**
 * How long the status poll tolerates a workstation that will not answer before
 * it stops guessing. Not a transaction deadline — see chargeAndWait.
 */
const UNREACHABLE_GIVE_UP_MS = 60_000;

export interface ChargeOptions {
  /** Poll cadence in ms (default 1200; tests pass 0). */
  pollMs?: number;
  /** Abort polling (does NOT cancel the terminal — call cancel() for that). */
  signal?: AbortSignal;
  /** Called once the session id is known, so callers can wire a cancel button. */
  onSession?: (sessionId: string) => void;
  /** How long to keep retrying an unreachable workstation (tests shorten it). */
  unreachableGiveUpMs?: number;
}

export const cardTerminalService = {
  /** Start an async card charge for an order → returns the session id. */
  start: (orderId: string) =>
    call<{ data: { session_id: string; order_id: string; total: number } }>(BASE, {
      method: "POST",
      body: JSON.stringify({ order_id: orderId }),
    }),

  /** Poll the current state of a charge session. */
  status: (sessionId: string) =>
    call<{ data: TerminalSnapshot }>(`${BASE}/${encodeURIComponent(sessionId)}`),

  /** What is holding the terminal right now, or null when it is idle. */
  current: async (): Promise<TerminalSnapshot | null> => {
    const body = await call<{ data?: TerminalSnapshot } | null>(
      "/api/v1/pos/terminal/current",
    );
    return body?.data ?? null;
  },

  /**
   * Give up on a transaction the workstation can no longer drive. Settles as
   * `unknown`, never `canceled` — whoever presses this does not know whether the
   * card was captured, and the record has to say so.
   */
  abandon: (actorUserId?: string) =>
    call<{ data: TerminalSnapshot }>("/api/v1/pos/terminal/abandon", {
      method: "POST",
      headers: actorUserId ? { "X-Actor-User-Id": actorUserId } : {},
    }),

  /** Ask the terminal to return the card / abort the in-flight charge. */
  cancel: (sessionId: string) =>
    call<{ data: unknown }>(`${BASE}/${encodeURIComponent(sessionId)}/cancel`, {
      method: "POST",
    }),

  /**
   * Start a charge and poll until the terminal reaches a terminal state
   * (approved / declined / canceled / unknown). Returns the final snapshot.
   *
   * There is deliberately NO time limit on the transaction itself. Nobody can
   * say how long a swipe takes, and the workstation is the one place that
   * decides: it settles a session whose driver went quiet, and caps a charge at
   * 15 minutes. Adding a second, shorter deadline here would be the bug it looks
   * like a fix for — the client giving up first leaves the workstation session
   * alive, and the next customer meets the 409 this whole change exists to kill.
   *
   * The one thing the client must bound is a workstation that stops answering.
   */
  async chargeAndWait(orderId: string, opts: ChargeOptions = {}): Promise<TerminalSnapshot> {
    const pollMs = opts.pollMs ?? 1200;
    const { data: started } = await cardTerminalService.start(orderId);
    opts.onSession?.(started.session_id);

    let unreachableFor = 0;
    for (;;) {
      if (opts.signal?.aborted) {
        throw new DOMException("aborted", "AbortError");
      }
      if (pollMs > 0) await sleep(pollMs);
      try {
        const { data: snap } = await cardTerminalService.status(started.session_id);
        unreachableFor = 0;
        if (isTerminal(snap.status)) return snap;
      } catch {
        // A blip is not an answer: the P400 may well still be running, so keep
        // asking. Only sustained silence means we can no longer find out.
        unreachableFor += Math.max(pollMs, 1);
        if (unreachableFor >= (opts.unreachableGiveUpMs ?? UNREACHABLE_GIVE_UP_MS)) {
          // RETURN, never throw. Callers turn a thrown error into "card
          // declined", and saying declined about a card that may have been
          // charged is the one answer that must never be given.
          return {
            session_id: started.session_id,
            order_id: orderId,
            status: "unknown",
            payment_id: "",
            amount: started.total,
            error: "lost contact with the workstation while the card was being processed",
          };
        }
      }
    }
  },
};
