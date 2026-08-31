/**
 * /me/notification-preferences service — plan-012 T3.10 backend + T3.11 frontend.
 *
 * Per-(type × channel) opt-outs + master mute + quiet hours. Rows stored
 * server-side; the master row carries master_mute + quiet_from/to/tz.
 */

import { apiFetch } from "@/lib/api";

import type { NotificationChannel } from "./notification-routing-service";

export interface PreferenceRow {
  type: string;
  channel: NotificationChannel;
  enabled: boolean;
}

export interface QuietHours {
  from: string | null;
  to: string | null;
  tz: string | null;
}

export interface PreferencesPayload {
  preferences: PreferenceRow[];
  master_mute: boolean;
  quiet_hours: QuietHours;
}

export const preferenceService = {
  async get(): Promise<PreferencesPayload> {
    const res = await apiFetch<{ data: PreferencesPayload }>("/api/v1/me/notification-preferences");
    return res.data;
  },

  async upsert(
    type: string,
    channel: NotificationChannel,
    enabled: boolean
  ): Promise<PreferenceRow> {
    const res = await apiFetch<{ data: PreferenceRow }>(
      `/api/v1/me/notification-preferences/${type}/${channel}`,
      { method: "PUT", body: JSON.stringify({ enabled }) }
    );
    return res.data;
  },

  async setMasterMute(masterMute: boolean): Promise<{ master_mute: boolean }> {
    const res = await apiFetch<{ data: { master_mute: boolean } }>(
      "/api/v1/me/notification-preferences/master-mute",
      { method: "PUT", body: JSON.stringify({ master_mute: masterMute }) }
    );
    return res.data;
  },

  async setQuietHours(from: string, to: string, tz: string): Promise<QuietHours> {
    const res = await apiFetch<{ data: QuietHours }>(
      "/api/v1/me/notification-preferences/quiet-hours",
      { method: "PUT", body: JSON.stringify({ from, to, tz }) }
    );
    return res.data;
  },

  async types(): Promise<string[]> {
    const res = await apiFetch<{ data: string[] }>("/api/v1/me/notifications/types");
    return res.data;
  },
};
