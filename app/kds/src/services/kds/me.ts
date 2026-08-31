import { apiFetch } from "@/lib/api";

export interface KdsDeviceCapabilities {
  supports_offline: boolean;
  supports_wake_lock: boolean;
  supports_audio: boolean;
}

/**
 * Shape persisted to localStorage at pair time + returned by GET /api/v1/kds/me.
 * `branch_id`/`organization_id` come from the pairing response (legacy fields,
 * still useful for branch-scoped WS channel routing in RealtimeProvider).
 * `branch` + `capabilities` come from KdsDeviceResource (cloud /kds/me).
 */
export interface KdsDeviceInfo {
  id: string;
  name: string;
  type: "kds";
  status: string;
  branch_id: string;
  organization_id?: string;
  paired_at?: string | null;
  last_seen_at?: string | null;
  branch?: { id: string; name: string; code: string } | null;
  capabilities?: KdsDeviceCapabilities;
}

interface MeResponse {
  data: KdsDeviceInfo;
}

export async function getMe(opts?: { silent401?: boolean }): Promise<KdsDeviceInfo> {
  const res = await apiFetch<MeResponse>("/api/v1/kds/me", {
    method: "GET",
    silent401: opts?.silent401 ?? false,
  });
  return res.data;
}
