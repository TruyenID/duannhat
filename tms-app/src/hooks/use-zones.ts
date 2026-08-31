/**
 * useZones — fetches zones + tables using omnify-generated services.
 *
 * Merges zones + tables client-side (backend returns separately).
 * Uses TanStack Query with 15s polling + AppState foreground refetch.
 */

import { useQuery } from "@tanstack/react-query";
import { zoneService } from "../services/zone-service";
import { tableService } from "../services/table-service";
import { zoneKeys } from "./query-keys";
import type { Zone, ZoneWithTables, Table } from "../types/tms";

const POLL_INTERVAL = 15_000;
const DUMMY_SLUG = "_";

async function fetchZonesWithTables(): Promise<ZoneWithTables[]> {
  const [zonesRes, tablesRes] = await Promise.all([
    zoneService.list(),
    tableService.list(),
  ]);

  // Generated service returns PaginatedResponse or { data: T[] }
  const rawZones: Zone[] = Array.isArray(zonesRes)
    ? zonesRes
    : ((zonesRes as { data?: Zone[] }).data ?? []);
  const rawTables: Table[] = Array.isArray(tablesRes)
    ? tablesRes
    : ((tablesRes as { data?: Table[] }).data ?? []);

  // Group tables by zone_id
  const tablesByZone = new Map<string, Table[]>();
  for (const table of rawTables) {
    const list = tablesByZone.get(table.zone_id) ?? [];
    list.push(table);
    tablesByZone.set(table.zone_id, list);
  }

  return rawZones.map((zone) => ({
    ...zone,
    tables: tablesByZone.get(zone.id) ?? [],
  }));
}

export function useZones() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: zoneKeys.list(DUMMY_SLUG),
    queryFn: fetchZonesWithTables,
    refetchInterval: POLL_INTERVAL,
  });

  return {
    zones: data ?? [],
    isLoading,
    error: error instanceof Error ? error.message : error ? String(error) : null,
    refresh: () => refetch(),
  };
}
