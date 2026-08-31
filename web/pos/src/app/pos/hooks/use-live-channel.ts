import { useWorkstationSocket } from "@/hooks/use-workstation-socket";
import type { ApiMode } from "@/services/workstation/base-url-resolver";

/**
 * How often the WS-driven lists re-fetch while there is no live channel.
 * Bounds how long a table freed on another device can linger as "occupied".
 */
export const LIST_POLL_MS = 15_000;

export interface UseLiveChannelResult {
  /** True only when the workstation is reachable AND its socket is connected. */
  hasLiveChannel: boolean;
  /**
   * Pass straight into the WS-driven list queries. `undefined` means "a live
   * channel is pushing invalidations, polling would be waste".
   */
  listRefetchInterval: number | undefined;
}

/**
 * Owns the workstation socket and answers the only question the POS surface
 * asks about it: is there a live channel, or must the lists poll?
 *
 * #541 — Without a realtime channel, a table freed by a void on *another*
 * device has no push to reach us, so the lists must poll to bound how long they
 * show stale state.
 *
 * #1792 — That gate used to read `workstationReachable` alone, which is only an
 * HTTP probe of `/api/lan/health` (`workstation-provider.tsx`) and says nothing
 * about the socket. So "workstation up over HTTP, socket down" — a tunnel or
 * proxy that blocks `Upgrade`, the exact case `use-workstation-socket.ts` warns
 * about — got NEITHER push NOR polling, and the POS sat frozen. There is no
 * passive safety net either: `query-provider` sets `refetchOnWindowFocus:false`.
 * A transient drop is bounded by the socket's own backoff (≤30s); a persistent
 * one was unbounded. `isConnected` was returned by the hook and read by nobody.
 *
 * godx-kds already keys its polling off the socket this way
 * (`src/hooks/use-orders.ts`) — pos-web was the outlier.
 */
export function useLiveChannel(
  shopSlug: string,
  workstationReachable: boolean,
  mode: ApiMode,
): UseLiveChannelResult {
  // Reachability answers only "did a health probe reach the LAN box?". It does
  // NOT answer "which side owns POS data right now?". In particular the
  // operator can press Test connection while staying in manual Cloud mode; a
  // successful probe must not mount the workstation socket and then disable
  // polling for queries that apiFetch still routes to Cloud (#2978).
  const lanChannelEligible = mode !== "cloud" && workstationReachable;
  const { isConnected } = useWorkstationSocket(lanChannelEligible ? shopSlug : "");

  const hasLiveChannel = lanChannelEligible && isConnected;

  return {
    hasLiveChannel,
    listRefetchInterval: hasLiveChannel ? undefined : LIST_POLL_MS,
  };
}
