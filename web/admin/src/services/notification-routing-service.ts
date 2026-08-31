/**
 * Notification channel-route admin service — wraps
 * /api/v1/hq/{brandSlug}/notifications/channel-routes* (plan-012 T3.9 backend + T3.11 frontend).
 */

import { apiFetch } from "@/lib/api";

export type NotificationChannel = "in_app" | "realtime" | "email" | "push";
export type NotificationPriority = "low" | "normal" | "high" | "urgent";

export interface ChannelRouteRow {
  id: string;
  organization_id: string;
  brand_id: string | null;
  type: string;
  channels: NotificationChannel[];
  priority_overrides: Partial<Record<NotificationPriority, NotificationChannel[]>> | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface UpsertChannelRouteInput {
  type: string;
  channels: NotificationChannel[];
  priority_overrides?: Partial<Record<NotificationPriority, NotificationChannel[]>> | null;
}

export const routingService = {
  async list(brandSlug: string): Promise<ChannelRouteRow[]> {
    const res = await apiFetch<{ data: ChannelRouteRow[] }>(
      `/api/v1/hq/${brandSlug}/notifications/channel-routes`
    );
    return res.data;
  },

  async upsert(brandSlug: string, routes: UpsertChannelRouteInput[]): Promise<ChannelRouteRow[]> {
    const res = await apiFetch<{ data: ChannelRouteRow[] }>(
      `/api/v1/hq/${brandSlug}/notifications/channel-routes`,
      { method: "PUT", body: JSON.stringify({ routes }) }
    );
    return res.data;
  },

  async destroy(brandSlug: string, type: string): Promise<void> {
    await apiFetch<void>(
      `/api/v1/hq/${brandSlug}/notifications/channel-routes/${encodeURIComponent(type)}`,
      { method: "DELETE" }
    );
  },
};
