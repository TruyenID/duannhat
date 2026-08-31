/**
 * Presentation helpers for the settlement screens (#1157).
 *
 * NO CATALOGUE LIVES HERE. Provider codes, settlement statuses, batch statuses
 * and settlement kinds are all owned by the backend (and by the providers), and
 * a copy of that list in the frontend is a list that goes stale without ever
 * failing a test. So the filter options are derived from the rows the server
 * actually returned, and an unrecognised code renders as itself, tidied up.
 */

/** A gateway connection the brand owns, as offered in the connection filter. */
export interface SettlementConnectionOption {
  id: string;
  label: string;
}

/** Everything a settlement tab needs from the page shell. */
export interface SettlementTabProps {
  brandSlug: string;
  connections: SettlementConnectionOption[];
  /** `"all"` or a connection id. */
  connectionId: string;
  /** `"all"` or a status code discovered in the data. */
  status: string;
  page: number;
  perPage: number;
  setFilter: (key: string, value: string) => void;
  setPage: (page: number) => void;
}

/** `pending_payout` → `Pending payout`. Never maps to a fixed vocabulary. */
export function humanizeCode(code: string | null | undefined): string {
  if (!code) return "—";
  const spaced = code.replace(/[_-]+/g, " ").trim();
  if (spaced === "") return "—";
  return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

/**
 * Distinct non-empty values of one field across the loaded rows, sorted.
 *
 * These become filter options. They describe THE CURRENT PAGE, which is a real
 * limitation: a status that appears only on page 3 is not offered until you get
 * there. That is still better than a hardcoded enum that omits a status the
 * backend added last week — this list is at least never wrong about what exists.
 */
export function distinctValues<T>(
  rows: T[],
  pick: (row: T) => string | null | undefined
): string[] {
  const seen = new Set<string>();
  for (const row of rows) {
    const value = pick(row);
    if (typeof value === "string" && value !== "") seen.add(value);
  }
  return Array.from(seen).sort();
}

/**
 * Union of aging bucket keys across rows, in the order the API emitted them.
 *
 * Bucket edges come from `payments.settlement.aging_buckets` config, so the
 * columns must follow the response. Insertion order is the server's own
 * ascending order; sorting alphabetically would put "10-14d" before "4-7d".
 */
export function agingBucketKeys(rows: Array<{ buckets: Record<string, unknown> }>): string[] {
  const keys: string[] = [];
  for (const row of rows) {
    for (const key of Object.keys(row.buckets ?? {})) {
      if (!keys.includes(key)) keys.push(key);
    }
  }
  return keys;
}
