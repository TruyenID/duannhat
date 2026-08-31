import { useEffect, useMemo, useState, type ReactNode } from "react";
import {
  clearDeviceToken,
  getDeviceInfo,
  getDeviceToken,
  setDeviceToken as persistToken,
  type DeviceInfo,
} from "@/services/auth/device-token";
import { setUnauthorizedHandler } from "@/lib/api";
import { getMe } from "@/services/kds/me";
import { clearOrdersSnapshot } from "@/lib/idb";
import { AuthContext, type AuthCtx, type AuthState } from "./use-auth";

export type { AuthState };

export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>("loading");
  const [device, setDevice] = useState<DeviceInfo | null>(null);

  // Boot: hydrate from localStorage + verify token via /me
  useEffect(() => {
    let cancelled = false;
    async function init() {
      const token = getDeviceToken();
      const info = getDeviceInfo();
      if (!token || !info) {
        if (!cancelled) setState("unpaired");
        return;
      }
      // Token exists locally — verify it's still valid by hitting /me
      try {
        const fresh = await getMe({ silent401: true });
        if (cancelled) return;
        // Update device info with fresh server data (may have changed name etc.)
        const merged: DeviceInfo = {
          id: fresh.id,
          name: fresh.name,
          type: "kds",
          branch_id: fresh.branch_id,
          branch_name: fresh.branch?.name ?? info.branch_name,
          organization_id: fresh.organization_id,
          // KdsDeviceResource returns paired_at as string | null; DeviceInfo
          // (localStorage shape) uses string | undefined, so coerce at the boundary.
          paired_at: fresh.paired_at ?? undefined,
        };
        // Refresh stored info — token unchanged
        persistToken(token, merged);
        setDevice(merged);
        setState("paired");
      } catch (err) {
        if (cancelled) return;
        // 401 = revoked or expired. Network errors = keep as paired (offline-tolerant).
        const status = (err as { status?: number })?.status;
        if (status === 401) {
          clearDeviceToken();
          void clearOrdersSnapshot(); // best-effort
          setDevice(null);
          setState("unpaired");
        } else {
          // Network error or 5xx — assume still paired, let UI handle gracefully
          setDevice(info);
          setState("paired");
        }
      }
    }
    void init();
    return () => {
      cancelled = true;
    };
  }, []);

  // Wire global 401 handler: any apiFetch call returning 401 triggers logout
  useEffect(() => {
    setUnauthorizedHandler(() => {
      clearDeviceToken();
      void clearOrdersSnapshot(); // best-effort
      setDevice(null);
      setState("unpaired");
    });
    return () => setUnauthorizedHandler(null);
  }, []);

  const ctx = useMemo<AuthCtx>(
    () => ({
      state,
      device,
      setPaired: (_token, info) => {
        // Token already persisted by pairing flow (PairForm calls setDeviceToken).
        // This just transitions in-memory state.
        setDevice(info);
        setState("paired");
      },
      logout: () => {
        clearDeviceToken();
        void clearOrdersSnapshot(); // best-effort
        setDevice(null);
        setState("unpaired");
      },
    }),
    [state, device],
  );

  return <AuthContext.Provider value={ctx}>{children}</AuthContext.Provider>;
}
