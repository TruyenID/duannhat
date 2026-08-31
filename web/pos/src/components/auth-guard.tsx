import { Navigate, useLocation } from "react-router-dom";
import type { ReactNode } from "react";
import { useAuth } from "@/providers/use-auth";
import { getToken } from "@/lib/auth";

export interface AuthGuardProps {
  children: ReactNode;
}

export function AuthGuard({ children }: AuthGuardProps) {
  const { isAuthenticated } = useAuth();
  const location = useLocation();

  // localStorage is the source of truth. In-memory auth state can drift out of
  // sync with it — e.g. api.ts clears the token on repeated 401s before the
  // provider's state update lands — leaving a "logged-in" shell whose requests
  // all go out tokenless. Treat a missing stored token as unauthenticated so
  // we bounce to /pairing instead of rendering a dead POS (#472).
  if (!isAuthenticated || getToken() == null) {
    const redirect = encodeURIComponent(location.pathname + location.search);
    return <Navigate to={`/pairing?redirect=${redirect}`} replace />;
  }

  return <>{children}</>;
}
