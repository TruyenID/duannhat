import { apiFetch } from "@/lib/api";
import { CLOUD_URL } from "../base-url-resolver";

export interface ReverbConfig {
  app_key: string;
  cluster: string;
  host: string;
  port: number;
  scheme: "http" | "https";
}

interface Resp {
  data: ReverbConfig;
}

/**
 * Fetch per-brand Reverb client config (app_key + host + port + scheme).
 * Always hits cloud (workstation doesn't proxy this). Phase 0 Task 0.2.
 */
export async function getReverbConfig(): Promise<ReverbConfig> {
  const res = await apiFetch<Resp>(`/api/v1/devices/reverb-config`, {
    method: "GET",
    baseUrl: CLOUD_URL, // force cloud — Reverb config is cloud-only
  });
  return res.data;
}
