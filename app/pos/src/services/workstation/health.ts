import { normalizeBaseUrl, posUrlFromBase } from "@/src/lib/url";

export { posUrlFromBase };

const HEALTH_PATH = "/api/lan/health";
const DEFAULT_TIMEOUT_MS = 4000;

export async function checkWorkstationHealth(
  baseUrl: string,
  timeoutMs = DEFAULT_TIMEOUT_MS,
): Promise<boolean> {
  const root = normalizeBaseUrl(baseUrl);
  if (!root) return false;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(`${root}${HEALTH_PATH}`, {
      method: "GET",
      signal: controller.signal,
    });
    return res.ok;
  } catch {
    return false;
  } finally {
    clearTimeout(timer);
  }
}
