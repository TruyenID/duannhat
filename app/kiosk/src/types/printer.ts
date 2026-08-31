// Cloud-owned printer config, read-only replica for the kiosk.
// Source: GET /api/v1/kiosk/printers (device.auth:kiosk). Cloud owns the config
// (name / roles / LAN address); the workstation still pushes the bytes.

export type PrinterRole =
  | 'kitchen_printer'
  | 'bar_printer'
  | 'hall_printer'
  | 'receipt_printer'
  | string;

/** One row from GET /api/v1/kiosk/printers (subset of PrinterResource we use). */
export interface KioskPrinter {
  id: string;
  name: string;
  roles: PrinterRole[];
  connection_type: string;
  address: string | null;
  paper_width: number | null;
  cut_type: string | null;
  encoding: string | null;
  is_active: boolean;
  last_seen_at?: string | null;
}
