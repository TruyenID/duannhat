/**
 * Admin broadcast composer service — plan-012 T4.11 backend + T4.12 frontend.
 */

import { apiFetch } from "@/lib/api";

import type { NotificationChannel, NotificationPriority } from "./notification-routing-service";

export interface BroadcastInput {
  audience_id?: string | null;
  audience_inline?: Record<string, unknown> | null;
  template_id?: string | null;
  template_inline?: {
    key?: string;
    content?: Record<string, { title: string; body: string }>;
  } | null;
  params?: Record<string, unknown>;
  channels: NotificationChannel[];
  priority: NotificationPriority;
  scheduled_for?: string | null;
}

export interface BroadcastResult {
  id: string;
  type: string;
  scheduled_for: string | null;
}

export const broadcastService = {
  async send(brandSlug: string, input: BroadcastInput): Promise<BroadcastResult> {
    const res = await apiFetch<{ data: BroadcastResult }>(
      `/api/v1/hq/${brandSlug}/notifications/broadcast`,
      { method: "POST", body: JSON.stringify(input) }
    );
    return res.data;
  },

  async cancel(brandSlug: string, id: string): Promise<void> {
    await apiFetch<void>(`/api/v1/hq/${brandSlug}/notifications/${id}`, {
      method: "DELETE",
    });
  },
};
