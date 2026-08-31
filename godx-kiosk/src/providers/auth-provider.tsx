import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from "react";
import { router, usePathname } from "expo-router";
import { useQueryClient } from "@tanstack/react-query";
import {
  getDeviceToken,
  setDeviceToken,
  clearDeviceToken,
  pairDevice,
  apiFetch,
  setUnauthorizedHandler,
  ApiError,
} from "../lib/api";
import { isInPaymentFlow } from "../lib/payment-flow-routes";

interface DeviceInfo {
  id: string;
  name: string;
  type: string;
  status: string;
  branch_id: string;
  branch?: {
    id: string;
    name: string;
    address?: string;
    phone?: string;
    currency?: string;
    timezone?: string;
    locale?: string;
  };
  organization_id?: string;
  organization?: {
    id: string;
    name: string;
    slug?: string;
  };
}

interface AuthContextValue {
  isLoading: boolean;
  isAuthenticated: boolean;
  device: DeviceInfo | null;
  pair: (code: string) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [isLoading, setIsLoading] = useState(true);
  const [device, setDevice] = useState<DeviceInfo | null>(null);
  const [pendingLogout, setPendingLogout] = useState(false);
  const pathname = usePathname();
  const queryClient = useQueryClient();

  // Verify stored token on mount
  useEffect(() => {
    (async () => {
      try {
        const token = await getDeviceToken();
        if (token) {
          const res = await apiFetch<{ data: DeviceInfo }>("/api/v1/kiosk/me");
          setDevice(res.data);
        }
      } catch (e) {
        // Token invalid or expired — clear it
        await clearDeviceToken();

        // Only log unexpected errors (not 401/403 which are expected)
        if (e instanceof ApiError && e.status !== 401 && e.status !== 403) {
          console.warn("[AuthProvider] Token verification failed:", e.message);
        }
      } finally {
        setIsLoading(false);
      }
    })();
  }, []);

  // Register 401 handler: just flips a flag. The drain effect below decides
  // when to actually log out — keeps the handler trivially idempotent under
  // concurrent 401s (multiple polling requests failing at once).
  useEffect(() => {
    setUnauthorizedHandler(() => setPendingLogout(true));
    return () => setUnauthorizedHandler(null);
  }, []);

  // Drain pending logout when safe: not on a payment-flow screen.
  // `clearDeviceToken` already ran inside apiFetch when 401 hit, so this
  // effect only handles the React-side cleanup (clear cache, clear device).
  useEffect(() => {
    if (!pendingLogout) return;
    if (isInPaymentFlow(pathname)) {
      console.warn(
        `[AuthProvider] 401 received but in payment flow (${pathname}); deferring logout.`,
      );
      return;
    }
    queryClient.clear();
    setDevice(null);
    setPendingLogout(false);
    router.replace("/login");
  }, [pendingLogout, pathname, queryClient]);

  const pair = useCallback(async (code: string) => {
    const res = await pairDevice(code);
    await setDeviceToken(res.device_token);

    // Validate response shape before setting state
    const deviceData = res.device as Record<string, unknown>;
    const orgData = deviceData.organization as Record<string, unknown> | undefined;
    setDevice({
      id: String(deviceData.id ?? ""),
      name: String(deviceData.name ?? ""),
      type: String(deviceData.type ?? ""),
      status: String(deviceData.status ?? ""),
      branch_id: String(deviceData.branch_id ?? ""),
      branch: deviceData.branch as DeviceInfo["branch"],
      organization_id: deviceData.organization_id
        ? String(deviceData.organization_id)
        : undefined,
      organization: orgData
        ? {
            id: String(orgData.id ?? ""),
            name: String(orgData.name ?? ""),
            slug: orgData.slug ? String(orgData.slug) : undefined,
          }
        : undefined,
    });
  }, []);

  const logout = useCallback(async () => {
    await clearDeviceToken();
    queryClient.clear(); // Clear all cached queries
    setDevice(null);
    router.replace("/login");
  }, [queryClient]);

  return (
    <AuthContext.Provider
      value={{
        isLoading,
        isAuthenticated: !!device,
        device,
        pair,
        logout,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within <AuthProvider>");
  return ctx;
}
