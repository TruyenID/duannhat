import { Platform } from "react-native";
import { compareVersionDesc, normalizeBaseUrl } from "@/src/lib/url";
import type { Unsubscribe, WorkstationInfo } from "./types";

export { normalizeBaseUrl };

const SERVICE_TYPE = "ws-app";
const PROTOCOL = "tcp";
const DOMAIN = "local.";

export interface ZeroconfService {
  name: string;
  host?: string;
  port?: number;
  addresses?: string[];
  txt?: Record<string, string>;
}

type Listener = (list: WorkstationInfo[]) => void;

/**
 * Browse `_ws-app._tcp` and expose every resolved workstation.
 * Unlike kiosk, we do not filter by branch — pairing happens inside pos-web.
 */
class WorkstationDiscovery {
  private zeroconf: any = null;
  private scanning = false;
  private services: Map<string, ZeroconfService> = new Map();
  private current: WorkstationInfo[] = [];
  private listeners: Set<Listener> = new Set();

  private ensureZeroconf(): any {
    if (this.zeroconf) return this.zeroconf;
    try {
      // Optional native module — absent in Expo Go / web.
      // eslint-disable-next-line @typescript-eslint/no-require-imports
      const ZeroconfMod = require("react-native-zeroconf");
      const Zeroconf = ZeroconfMod.default ?? ZeroconfMod;
      this.zeroconf = new Zeroconf();
      this.zeroconf.on("resolved", (svc: ZeroconfService) => {
        if (!svc?.name) return;
        this.services.set(svc.name, svc);
        this.recompute();
      });
      this.zeroconf.on("remove", (name: string) => {
        if (this.services.delete(name)) this.recompute();
      });
      this.zeroconf.on("error", (err: Error) => {
        console.warn("[pos-shell] zeroconf error", err);
      });
      return this.zeroconf;
    } catch (err) {
      console.warn(
        "[pos-shell] react-native-zeroconf unavailable (Expo Go / web). " +
          "Build a dev client to enable mDNS discovery.",
        err,
      );
      return null;
    }
  }

  start(): void {
    if (Platform.OS === "web") return;
    if (this.scanning) return;
    const z = this.ensureZeroconf();
    if (!z) return;
    try {
      z.scan(SERVICE_TYPE, PROTOCOL, DOMAIN);
      this.scanning = true;
    } catch (err) {
      console.warn("[pos-shell] mDNS scan failed", err);
    }
  }

  stop(): void {
    if (!this.scanning || !this.zeroconf) return;
    try {
      this.zeroconf.stop();
    } catch {
      // already stopped
    }
    this.scanning = false;
    this.services.clear();
    this.setCurrent([]);
  }

  onChange(cb: Listener): Unsubscribe {
    this.listeners.add(cb);
    cb(this.current);
    return () => {
      this.listeners.delete(cb);
    };
  }

  list(): WorkstationInfo[] {
    return this.current;
  }

  private recompute(): void {
    const next: WorkstationInfo[] = [];
    for (const svc of this.services.values()) {
      const ws = serviceToWorkstation(svc);
      if (ws) next.push(ws);
    }
    this.setCurrent(sortWorkstations(next));
  }

  private setCurrent(list: WorkstationInfo[]): void {
    const prevKey = workstationListKey(this.current);
    const nextKey = workstationListKey(list);
    this.current = list;
    if (prevKey !== nextKey) {
      for (const l of this.listeners) l(list);
    }
  }
}

/**
 * Newest-first, then by name. Kept a free function (rather than inline in
 * `recompute`) because the class can only be exercised with the native mDNS
 * client attached — a unit test cannot reach it, and an ordering the setup
 * screen shows to an operator is not something to leave unpinned.
 *
 * Version compare is NUMERIC: a plain string sort ranks `0.9.0` above
 * `0.10.0`, so the list would offer an older workstation first from the first
 * x.10 release onward.
 */
export function sortWorkstations(list: WorkstationInfo[]): WorkstationInfo[] {
  return [...list].sort((a, b) => {
    const byVersion = compareVersionDesc(a.version, b.version);
    if (byVersion !== 0) return byVersion;
    return a.name.localeCompare(b.name);
  });
}

/**
 * Change-detection key for the discovered list. It covers every DISPLAYED
 * field, not just `baseUrl`: a workstation that is renamed, upgraded, or
 * re-paired to another branch keeps its address, so a baseUrl-only key left
 * the setup screen showing the old name and version indefinitely with nothing
 * to hint the row was stale.
 */
export function workstationListKey(list: WorkstationInfo[]): string {
  return list
    .map((w) => `${w.baseUrl} ${w.name} ${w.version} ${w.branchId}`)
    .join("|");
}

/**
 * Exported for unit tests: this is where an mDNS TXT record becomes the URL the
 * WebView will load, so a wrong branch here points a tablet at the wrong shop.
 */
export function serviceToWorkstation(svc: ZeroconfService): WorkstationInfo | null {
  const txt = svc.txt ?? {};
  const baseUrl = txt.proxy_url ?? buildBaseUrlFromService(svc);
  if (!baseUrl) return null;
  return {
    name: txt.name ?? txt.store ?? svc.name,
    branchId: txt.branch_id ?? "",
    baseUrl: normalizeBaseUrl(baseUrl),
    version: txt.version ?? "0.0.0",
  };
}

function buildBaseUrlFromService(svc: ZeroconfService): string {
  const address = svc.addresses?.find((ip) => /^\d+\.\d+\.\d+\.\d+$/.test(ip));
  if (!address || !svc.port) return "";
  return `http://${address}:${svc.port}`;
}

export const workstationDiscovery = new WorkstationDiscovery();
