/**
 * TMS Query Key Factories — wraps omnify-generated key factories.
 *
 * TMS uses "_" as dummy slug since device-scoped (no brand/shop slug).
 */

import { createZoneQueryKeys } from "../types/models/base/ZoneQueryKeys";
import { createTableQueryKeys } from "../types/models/base/TableQueryKeys";

export const zoneKeys = createZoneQueryKeys("zones");
export const tableKeys = createTableQueryKeys("tables");

export const deviceKeys = {
  me: ["device", "me"] as const,
};

export const orderKeys = {
  byTable: (tableId: string) => ['order', 'table', tableId] as const,
  byCode: (orderCode: string) => ['order', 'code', orderCode] as const,
};

export const printerKeys = {
  all: ['kiosk-printers'] as const,
};

export const paymentKeys = {
  status: (paymentId: string) => ['payment', 'status', paymentId] as const,
  splitPreview: (orderId: string, allocationsHash: string) =>
    ['payment', 'split-preview', orderId, allocationsHash] as const,
  effectiveOptions: (revision?: number) =>
    ['payment', 'effective-options', revision ?? 'latest'] as const,
};
