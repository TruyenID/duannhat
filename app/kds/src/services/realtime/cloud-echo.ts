import Echo from "laravel-echo";
import Pusher from "pusher-js";
import { getReverbConfig } from "@/services/kds/reverb-config";
import { CLOUD_URL } from "@/services/base-url-resolver";
import type { RealtimeEvent, Listener } from "./lan-ws";

/**
 * CloudEchoClient — Reverb subscription via Laravel Echo + Pusher.js.
 *
 * Subscribes to private-branch.{branchId}.kds-events (Phase 1 Task 1.4
 * registered the channel callback authorizing KDS devices in matching
 * branch). Auth happens via cloud /api/v1/devices/broadcasting/auth
 * (Phase 0 Task 0.3) with Bearer device_token.
 *
 * Used as cloud-fallback when workstation LAN is unreachable. Phase 5
 * RealtimeProvider swaps between LanWsClient and this based on
 * resolveBaseUrl().via.
 */
export class CloudEchoClient {
  private echo: Echo<"pusher"> | null = null;
  private listeners = new Set<Listener>();
  private closed = false;
  private token: string;
  private branchId: string;

  constructor(
    token: string,
    branchId: string,
  ) {
    this.token = token;
    this.branchId = branchId;
  }

  on(listener: Listener): () => void {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  async connect(): Promise<void> {
    if (this.closed) return;
    if (this.echo) return; // already connected

    const cfg = await getReverbConfig();

    // Pusher needs to be on the window for Echo to find it
    (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

    this.echo = new Echo({
      broadcaster: "pusher",
      key: cfg.app_key,
      cluster: cfg.cluster,
      wsHost: cfg.host,
      wsPort: cfg.port,
      wssPort: cfg.port,
      forceTLS: cfg.scheme === "https",
      enabledTransports: ["ws", "wss"],
      authEndpoint: `${CLOUD_URL}/api/v1/devices/broadcasting/auth`,
      auth: {
        headers: {
          Authorization: `Bearer ${this.token}`,
          Accept: "application/json",
        },
      },
    });

    // Subscribe to branch-scoped KDS events channel
    this.echo
      .private(`branch.${this.branchId}.kds-events`)
      .listen(".order_item.status_changed", (payload: unknown) =>
        this.dispatch({ type: "order_item.status_changed", payload }),
      );

    // Future: subscribe to additional events (order_created, order_updated)
    // when cloud broadcasts them on the same channel.
  }

  close(): void {
    this.closed = true;
    this.echo?.disconnect();
    this.echo = null;
  }

  private dispatch(event: RealtimeEvent): void {
    for (const listener of this.listeners) {
      try {
        listener(event);
      } catch (err) {
        console.error("[CloudEchoClient] listener error", err);
      }
    }
  }
}
