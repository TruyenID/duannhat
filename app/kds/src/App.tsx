import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom";
import { AppProvider } from "./providers/AppProvider";
import { KdsToaster } from "./components/kds-toaster";
import { I18nProvider } from "./i18n";
import { AuthProvider } from "./providers/AuthProvider";
import { useAuth } from "./providers/use-auth";
import { QueryProvider } from "./providers/QueryProvider";
import { RealtimeProvider } from "./providers/RealtimeProvider";
import { WakeLockProvider } from "./providers/WakeLockProvider";
import { ErrorBoundary } from "./components/error-boundary";
import PairingPage from "./app/pairing/page";
import DashboardPage from "./app/dashboard/page";

function Splash() {
  return (
    <div className="grid h-screen place-items-center bg-background text-foreground">
      <p className="text-muted-foreground">…</p>
    </div>
  );
}

function RequirePaired({ children }: { children: React.ReactNode }) {
  const { state } = useAuth();
  if (state === "loading") return <Splash />;
  if (state === "unpaired") return <Navigate to="/pairing" replace />;
  return <>{children}</>;
}

function RequireUnpaired({ children }: { children: React.ReactNode }) {
  const { state } = useAuth();
  if (state === "loading") return <Splash />;
  if (state === "paired") return <Navigate to="/" replace />;
  return <>{children}</>;
}

function AppRoutes() {
  return (
    <Routes>
      <Route
        path="/pairing"
        element={
          <RequireUnpaired>
            <PairingPage />
          </RequireUnpaired>
        }
      />
      <Route
        path="/"
        element={
          <RequirePaired>
            <WakeLockProvider>
              <DashboardPage />
            </WakeLockProvider>
          </RequirePaired>
        }
      />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}

export default function App() {
  return (
    <ErrorBoundary>
      <AppProvider>
        <I18nProvider>
          <AuthProvider>
            <QueryProvider>
              <RealtimeProvider>
                <BrowserRouter>
                  <AppRoutes />
                </BrowserRouter>
                <KdsToaster
                  position="top-right"
                  richColors
                  closeButton
                  duration={3000}
                />
              </RealtimeProvider>
            </QueryProvider>
          </AuthProvider>
        </I18nProvider>
      </AppProvider>
    </ErrorBoundary>
  );
}
