import { Platform } from "react-native";
import type { Unsubscribe, WorkstationDiscoveryService, WorkstationInfo } from "./types";

const SERVICE_TYPE = "ws-app";
const PROTOCOL = "tcp";
const DOMAIN = "local.";

interface ZeroconfService {
  name: string;
  host?: string;
  port?: number;
  addresses?: string[];
  txt?: Record<string, string>;
}

type Listener = (ws: WorkstationInfo | null) => void;

class WorkstationDiscovery implements WorkstationDiscoveryService {
  private zeroconf: any = null;
  private scanning = false;
  private branchId = "";
  private services: Map<string, ZeroconfService> = new Map();
  private currentWs: WorkstationInfo | null = null;
  private listeners: Set<Listener> = new Set();

  private ensureZeroconf(): any {
    if (this.zeroconf) return this.zeroconf;
    try {
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
        // eslint-disable-next-line no-console
        console.warn("[workstation-discovery] zeroconf error", err);
      });
      return this.zeroconf;
    } catch (err) {
      // eslint-disable-next-line no-console
      console.warn(
        "[workstation-discovery] react-native-zeroconf unavailable (Expo Go / web). " +
          "Build a dev client to enable mDNS discovery.",
        err,
      );
      return null;
    }
  }

  start(branchId: string): void {
    if (Platform.OS === "web") return;
    if (this.scanning && this.branchId === branchId) return;
    if (this.scanning) this.stop();
    this.branchId = branchId;
    const z = this.ensureZeroconf();
    if (!z) return;
    try {
      z.scan(SERVICE_TYPE, PROTOCOL, DOMAIN);
      this.scanning = true;
    } catch (err) {
      // eslint-disable-next-line no-console
      console.warn("[workstation-discovery] scan failed", err);
    }
  }

  stop(): void {
    if (!this.scanning || !this.zeroconf) return;
    try {
      this.zeroconf.stop();
    } catch {
      // ignore — already stopped
    }
    this.scanning = false;
    this.services.clear();
    this.setCurrent(null);
  }

  onChange(cb: Listener): Unsubscribe {
    this.listeners.add(cb);
    // Fire current value immediately so subscribers can render initial state.
    cb(this.currentWs);
    return () => {
      this.listeners.delete(cb);
    };
  }

  current(): WorkstationInfo | null {
    return this.currentWs;
  }

  /**
   * Recompute the best workstation match from currently-resolved services.
   * Filter rule: TXT.branch_id matches the configured branch.
   * Tie-break: highest TXT.version (semver lexicographic — fine for current 0.x).
   */
  private recompute(): void {
    if (!this.branchId) return;
    let best: WorkstationInfo | null = null;
    for (const svc of this.services.values()) {
      const txt = svc.txt ?? {};
      const branchId = txt.branch_id ?? "";
      if (branchId !== this.branchId) continue;
      const ws = serviceToWorkstation(svc, txt);
      if (!ws) continue;
      if (!best || ws.version > best.version) {
        best = ws;
      }
    }
    this.setCurrent(best);
  }

  private setCurrent(ws: WorkstationInfo | null): void {
    const changed =
      this.currentWs?.proxyUrl !== ws?.proxyUrl ||
      this.currentWs?.version !== ws?.version;
    this.currentWs = ws;
    if (changed) {
      for (const l of this.listeners) l(ws);
    }
  }
}

function serviceToWorkstation(
  svc: ZeroconfService,
  txt: Record<string, string>,
): WorkstationInfo | null {
  const proxyUrl = txt.proxy_url ?? buildProxyUrlFromService(svc);
  if (!proxyUrl) return null;
  return {
    name: txt.name ?? txt.store ?? svc.name,
    branchId: txt.branch_id ?? "",
    proxyUrl,
    version: txt.version ?? "0.0.0",
  };
}

function buildProxyUrlFromService(svc: ZeroconfService): string {
  const address = svc.addresses?.find((ip) => /^\d+\.\d+\.\d+\.\d+$/.test(ip));
  if (!address || !svc.port) return "";
  return `http://${address}:${svc.port}`;
}

export const workstationDiscovery: WorkstationDiscoveryService = new WorkstationDiscovery();
