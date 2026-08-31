/**
 * TMS Table Service — wraps omnify-generated TableBaseService for device-scoped API.
 */

import { createTableBaseService } from "../types/models/base/TableService";
import { apiFetch } from "../lib/api";

if (typeof globalThis !== "undefined") {
  (globalThis as Record<string, unknown>).apiFetch = apiFetch;
}

const baseService = createTableBaseService(
  (_slug: string, path = "") => `/api/v1/tms/tables${path}`,
);

/** Device-scoped table service — no slug needed. */
export const tableService = {
  list: (filters = {}) => baseService.list("_", filters),
  getById: (id: string) => baseService.getById("_", id),
  lookup: () => baseService.lookup("_"),
};
