"use client";

import { useTranslation } from "@/providers/app-provider";
import type { Device } from "@/types/models/Device";

/**
 * Online/offline derived from `devices.last_seen_at` — the heartbeat every
 * authed device request refreshes (throttled server-side to one write per
 * 60s, `DeviceService::LAST_SEEN_THROTTLE_SECONDS`).
 *
 * The 5-minute window sits deliberately between that throttle (a live device
 * can look up to ~60s stale) and the backend's offline alert threshold
 * (15 min, `DeviceOfflineDetectionJob`) — tighter than the throttle would
 * flag live devices, looser than the alert would say "online" about a device
 * the system is already alerting on.
 *
 * Never seen ⇒ em dash, per the repo's table convention — "offline" would
 * claim a measurement about a device that has not reported once.
 */
const ONLINE_WINDOW_MS = 5 * 60 * 1000;

export type DeviceConnectionCellProps = {
  device: Device;
};

export function DeviceConnectionCell({ device }: DeviceConnectionCellProps) {
  const { t } = useTranslation();

  if (!device.last_seen_at) {
    return (
      <span data-slot="device-connection-cell" className="text-xs text-muted-foreground">
        —
      </span>
    );
  }

  const online = Date.now() - new Date(device.last_seen_at).getTime() < ONLINE_WINDOW_MS;

  return (
    <span data-slot="device-connection-cell" className="inline-flex items-center gap-1.5 text-xs">
      <span
        aria-hidden
        className={
          online ? "size-2 rounded-full bg-success" : "size-2 rounded-full bg-muted-foreground/40"
        }
      />
      <span className={online ? "text-success" : "text-muted-foreground"}>
        {online ? t("device.connection.online") : t("device.connection.offline")}
      </span>
    </span>
  );
}
