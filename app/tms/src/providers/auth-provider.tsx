import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from "react";
import { useQueryClient } from "@tanstack/react-query";
import {
  getDeviceToken,
  setDeviceToken,
  clearDeviceToken,
  DeviceTypeMismatchError,
  pairDevice,
  apiFetch,
  ApiError,
} from "../lib/api";

interface DeviceInfo {
  id: string;
  name: string;
  type: string;
  status: string;
  branch_id: string;
  branch?: { id: string; name: string };
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
  const queryClient = useQueryClient();

  // Verify stored token on mount
  useEffect(() => {
    (async () => {
      try {
        const token = await getDeviceToken();
        if (token) {
          const res = await apiFetch<{ data: DeviceInfo }>("/api/v1/tms/me");
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

  const pair = useCallback(async (code: string) => {
    const res = await pairDevice(code);

    // #935 — success-path guard against an OLD backend that ignored
    // expected_type: never store a token for a non-tms device (the TMS
    // surface would 403 on every call and render empty screens).
    const deviceData = res.device as Record<string, unknown>;
    if (String(deviceData.type ?? "") !== "tms") {
      throw new DeviceTypeMismatchError(String(deviceData.type ?? ""));
    }

    await setDeviceToken(res.device_token);
    setDevice({
      id: String(deviceData.id ?? ""),
      name: String(deviceData.name ?? ""),
      type: String(deviceData.type ?? ""),
      status: String(deviceData.status ?? ""),
      branch_id: String(deviceData.branch_id ?? ""),
      branch: deviceData.branch as DeviceInfo["branch"],
    });
  }, []);

  const logout = useCallback(async () => {
    await clearDeviceToken();
    queryClient.clear(); // Clear all cached queries
    setDevice(null);
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
