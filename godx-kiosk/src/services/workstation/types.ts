/**
 * Workstation discovery + routing types.
 *
 * The kiosk discovers the LAN workstation via mDNS (service _ws-app._tcp.local.)
 * and routes API calls to it when reachable, falling back to Cloud when not.
 */

export interface WorkstationInfo {
  /** Human-readable display name from TXT record `name` (eg "Branch Tokyo - WS1"). */
  name: string;
  /** UUID of the branch this workstation serves. Used to filter mDNS results. */
  branchId: string;
  /** Full base URL the kiosk can call directly (eg "http://192.168.1.10:8080"). */
  proxyUrl: string;
  /** Server version from TXT record `version`. Used to pick highest version on ties. */
  version: string;
}

export type Unsubscribe = () => void;

export interface WorkstationDiscoveryService {
  /** Start mDNS browse. Idempotent. */
  start(branchId: string): void;
  /** Stop mDNS browse + release resources. */
  stop(): void;
  /** Register a listener for workstation changes. Returns unsubscribe. */
  onChange(cb: (ws: WorkstationInfo | null) => void): Unsubscribe;
  /** Latest discovered workstation matching the configured branch, or null. */
  current(): WorkstationInfo | null;
}
