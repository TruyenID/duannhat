import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";
import type { OrderPriority } from "@/services/kds/orders";

/** Tailwind-aware className merger. */
export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

/**
 * Server-derived priority → Tailwind border class. Cloud's KdsOrderResource
 * emits the priority enum at fixed thresholds (normal <5min, warning 5-10min,
 * critical >=10min) so this is purely a presentation map — no client compute.
 */
export function priorityColorClass(priority: OrderPriority): string {
  switch (priority) {
    case "critical":
      return "border-red-500 animate-pulse";
    case "warning":
      return "border-amber-500";
    case "normal":
    default:
      return "border-green-500";
  }
}

/** Minutes since the given ISO timestamp. Returns 0 if null/missing. */
export function ageInMinutes(openedAt: string | Date | null | undefined): number {
  if (openedAt == null) return 0;
  const opened =
    typeof openedAt === "string" ? new Date(openedAt).getTime() : openedAt.getTime();
  return Math.max(0, Math.floor((Date.now() - opened) / 60_000));
}

/**
 * Threshold past which the offline-snapshot banner switches red and warns the
 * cook to verify each ticket. 5 min picked deliberately: that is roughly the
 * timescale on which a finished dish can leave the floor (server "closes" the
 * order), so beyond it the cached list almost certainly contains phantom
 * orders. Tune here if a kitchen reports false positives.
 */
export const SNAPSHOT_STALE_THRESHOLD_MINUTES = 5;

/** Whether the snapshot taken at `fetchedAt` exceeds the stale threshold. */
export function isSnapshotStale(
  fetchedAt: string,
  thresholdMinutes: number = SNAPSHOT_STALE_THRESHOLD_MINUTES,
): boolean {
  return ageInMinutes(fetchedAt) >= thresholdMinutes;
}
