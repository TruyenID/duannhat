/**
 * Plan-023 M5 T5.9 — wraps /api/v1/me/notification-preferences/digest.
 */

import { apiFetch } from "@/lib/api";

export type DigestCadence = "off" | "daily" | "weekly";
export type DigestPriority = "low" | "normal" | "high" | "urgent";

export interface DigestPreferenceRow {
  cadence: DigestCadence;
  delivery_time: string; // HH:MM
  timezone: string;
  weekday: number | null; // 0=Sun … 6=Sat (null when cadence != weekly)
  include_priorities: DigestPriority[];
  last_sent_at: string | null;
}

export interface DigestPreferencePayload {
  cadence: DigestCadence;
  delivery_time: string;
  timezone: string;
  weekday?: number | null;
  include_priorities?: DigestPriority[];
}

export const notificationDigestPreferenceService = {
  async show(): Promise<DigestPreferenceRow> {
    const res = await apiFetch<{ data: DigestPreferenceRow }>(
      "/api/v1/me/notification-preferences/digest"
    );
    return res.data;
  },

  async update(payload: DigestPreferencePayload): Promise<DigestPreferenceRow> {
    const res = await apiFetch<{ data: DigestPreferenceRow }>(
      "/api/v1/me/notification-preferences/digest",
      {
        method: "PATCH",
        body: JSON.stringify(payload),
      }
    );
    return res.data;
  },
};
