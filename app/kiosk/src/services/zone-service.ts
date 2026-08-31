/**
 * TMS Zone Service — wraps omnify-generated ZoneBaseService for device-scoped API.
 *
 * TMS app doesn't use slugs — all endpoints are device-scoped via bearer token.
 * URL: /api/v1/tms/zones (no slug param).
 */

import { createZoneBaseService } from "../types/models/base/ZoneService";
import { apiFetch } from "../lib/api";

// Inject apiFetch into global scope for generated service (uses ambient declaration)
if (typeof globalThis !== "undefined") {
  (globalThis as Record<string, unknown>).apiFetch = apiFetch;
}

const baseService = createZoneBaseService(
  (_slug: string, path = "") => `/api/v1/tms/zones${path}`,
);

/** Device-scoped zone service — no slug needed. */
export const zoneService = {
  list: (filters = {}) => baseService.list("_", filters),
  getById: (id: string) => baseService.getById("_", id),
  lookup: () => baseService.lookup("_"),
};
