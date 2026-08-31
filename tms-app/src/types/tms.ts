/** Backend enum: free | occupied | reserved | cleaning | out_of_service */
export type TableStatus =
  | "free"
  | "occupied"
  | "reserved"
  | "cleaning"
  | "out_of_service";

/** Raw table from GET /api/v1/tms/tables */
export interface Table {
  id: string;
  code: string;
  name: string;
  seat_count: number;
  status: TableStatus;
  paid_at: string | null;
  call_requested_at: string | null;
  zone_id: string;
  zone?: { id: string; name: string };
}

/**
 * Visual state derived from table data.
 * Priority: call_staff > recently_paid > occupied > cleaning > free
 */
export type TableDisplayState =
  | "free"
  | "occupied"
  | "call_staff"
  | "recently_paid"
  | "cleaning";

/** Raw zone from GET /api/v1/tms/zones */
export interface Zone {
  id: string;
  name: string;
  branch_id: string;
}

/** Zone with tables merged client-side */
export interface ZoneWithTables extends Zone {
  tables: Table[];
}
