/** A workstation advertised on `_ws-app._tcp` (or entered manually). */
export interface WorkstationInfo {
  /** Display name from TXT `name` / `store`, or service name. */
  name: string;
  /** Optional branch UUID from TXT `branch_id`. */
  branchId: string;
  /** Base URL, e.g. `http://192.168.1.10:8080`. */
  baseUrl: string;
  /** TXT `version`, used for display / tie-break. */
  version: string;
}

export type Unsubscribe = () => void;
