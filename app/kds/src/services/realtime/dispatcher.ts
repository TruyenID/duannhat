import { LanWsClient } from "./lan-ws";
import { CloudEchoClient } from "./cloud-echo";
import type { Listener, RealtimeEvent } from "./lan-ws";

export type RealtimeMode = "workstation" | "cloud";

/**
 * Common interface for any realtime backend. LanWsClient + CloudEchoClient
 * both satisfy this contract so the RealtimeProvider can swap them
 * transparently based on resolveBaseUrl().via.
 */
export interface RealtimeBackend {
  on(listener: Listener): () => void;
  connect(): void | Promise<void>;
  close(): void;
}

export interface CreateRealtimeArgs {
  mode: RealtimeMode;
  token: string;
  branchId: string;
}

/**
 * Pick a realtime backend based on the resolver mode.
 * - workstation → LanWsClient (WS to workstation /ws, first-message auth)
 * - cloud       → CloudEchoClient (Reverb private channel via Echo+Pusher.js)
 */
export function createRealtimeDispatcher(args: CreateRealtimeArgs): RealtimeBackend {
  if (args.mode === "workstation") {
    return new LanWsClient(args.token);
  }
  return new CloudEchoClient(args.token, args.branchId);
}

export type { Listener, RealtimeEvent };
