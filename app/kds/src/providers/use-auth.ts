import { createContext, useContext } from "react";
import type { DeviceInfo } from "@/services/auth/device-token";

export type AuthState = "loading" | "paired" | "unpaired";

export interface AuthCtx {
  state: AuthState;
  device: DeviceInfo | null;
  setPaired: (token: string, info: DeviceInfo) => void;
  logout: () => void;
}

export const AuthContext = createContext<AuthCtx | null>(null);

export function useAuth(): AuthCtx {
  const c = useContext(AuthContext);
  if (!c) throw new Error("useAuth must be used inside AuthProvider");
  return c;
}
