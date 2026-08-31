import type { TableStatusValue } from "../types";

export interface TableStatusMeta {
  label: string;
  badge: string;
}

type TFn = (key: string) => string;

// Colour palette matched to the shop's canonical TableStatusMenu (admin-web):
// free=emerald, occupied=amber, reserved=blue, cleaning=gray, out_of_service=red.
const STATUS_STYLE: Record<TableStatusValue, { i18n: string; badge: string }> = {
  free: {
    i18n: "pos.table_status.free",
    badge:
      "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300",
  },
  occupied: {
    i18n: "pos.table_status.occupied",
    badge:
      "bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300",
  },
  reserved: {
    i18n: "pos.table_status.reserved",
    badge: "bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300",
  },
  cleaning: {
    i18n: "pos.table_status.cleaning",
    badge: "bg-gray-100 text-gray-700 dark:bg-gray-500/20 dark:text-gray-300",
  },
  out_of_service: {
    i18n: "pos.table_status.out_of_service",
    badge: "bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300",
  },
};

export function getTableStatusMeta(t: TFn): Record<TableStatusValue, TableStatusMeta> {
  const result = {} as Record<TableStatusValue, TableStatusMeta>;
  for (const [key, val] of Object.entries(STATUS_STYLE)) {
    result[key as TableStatusValue] = {
      label: t(val.i18n),
      badge: val.badge,
    };
  }
  return result;
}
