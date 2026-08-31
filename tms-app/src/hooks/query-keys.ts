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
