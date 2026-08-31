import {
  createContext,
  useCallback,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import { useNavigate } from "react-router-dom";
import { useQueryClient } from "@tanstack/react-query";
import {
  clearSession,
  getToken,
  getDeviceInfo,
  persistSession,
  type DeviceInfo,
} from "@/lib/auth";
import { setAuthRecoveryHandler, setUnauthorizedHandler } from "@/lib/api";

export interface AuthContextValue {
  device: DeviceInfo | null;
  token: string | null;
  isAuthenticated: boolean;
  /** Soft auth failure reason (e.g. 503 verify unavailable); null when idle. */
  authRecoveryReason: string | null;
  setPaired: (token: string, device: DeviceInfo) => void;
  logout: () => void;
  dismissAuthRecovery: () => void;
}

export const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(() => getToken());
  const [device, setDevice] = useState<DeviceInfo | null>(() => getDeviceInfo());
  const [authRecoveryReason, setAuthRecoveryReason] = useState<string | null>(null);
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const setPaired = useCallback((nextToken: string, nextDevice: DeviceInfo) => {
    persistSession(nextToken, nextDevice);
    setToken(nextToken);
    setDevice(nextDevice);
    setAuthRecoveryReason(null);
    navigate("/", { replace: true });
  }, [navigate]);

  const logout = useCallback(() => {
    clearSession();
    setToken(null);
    setDevice(null);
    setAuthRecoveryReason(null);
    queryClient.clear();
    navigate("/pairing", { replace: true });
  }, [navigate, queryClient]);

  const dismissAuthRecovery = useCallback(() => {
    setAuthRecoveryReason(null);
  }, []);

  useEffect(() => {
    setUnauthorizedHandler(() => {
      clearSession();
      setToken(null);
      setDevice(null);
      setAuthRecoveryReason(null);
      queryClient.clear();
      navigate("/pairing", { replace: true });
    });
    setAuthRecoveryHandler((reason) => {
      setAuthRecoveryReason(reason);
    });
    return () => {
      setUnauthorizedHandler(null);
      setAuthRecoveryHandler(null);
    };
  }, [navigate, queryClient]);

  const value = useMemo<AuthContextValue>(
    () => ({
      device,
      token,
      isAuthenticated: !!token,
      authRecoveryReason,
      setPaired,
      logout,
      dismissAuthRecovery,
    }),
    [device, token, authRecoveryReason, setPaired, logout, dismissAuthRecovery],
  );

  return <AuthContext value={value}>{children}</AuthContext>;
}
