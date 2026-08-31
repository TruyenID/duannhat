import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import { Toaster } from "@godxjp/ui";
import { QueryProvider } from "@/providers/query-provider";
import { AuthProvider } from "@/providers/auth-provider";
import { AppProvider } from "@/providers/app-provider";
import { WorkstationProvider } from "@/providers/workstation-provider";
import { ErrorBoundary } from "@/components/error-boundary";
import { ErrorFallback } from "@/components/error-fallback";
import { OfflineShell } from "@/components/offline-shell";
import { OfflineBanner } from "@/components/offline-banner";
import { AuthRecoveryBanner } from "@/components/auth-recovery-banner";
import { WorkstationAlertBanner } from "@/components/workstation-alert-banner";
import { LightActionReplayer } from "@/components/light-action-replayer";
import { initSentry } from "@/lib/sentry";
import { installDomMutationGuard } from "@/lib/dom-mutation-guard";
import App from "./App.tsx";
import "./styles/globals.css";

// Harden the DOM against Google Translate (and similar extensions) BEFORE
// React mounts: they re-parent text nodes, which makes React's removeChild
// throw NotFoundError on unmount (e.g. switching report tabs) and crash the
// whole tree into the ErrorBoundary. See dom-mutation-guard.ts.
installDomMutationGuard();

// Initialise error tracking BEFORE React mounts so a crash during
// the first provider's setup is captured. Silent no-op when
// VITE_SENTRY_DSN is unset (dev / tests / unwired deploys).
initSentry();

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <ErrorBoundary fallback={(error, reset) => <ErrorFallback error={error} reset={reset} />}>
      {/* #1481 — basename follows Vite's base: "/pos/" when embedded in the
          workstation, "/" for the Amplify build. Without it, client routes live
          at the root (/pairing) instead of /pos/pairing, so a reload under /pos
          loads the workstation's own frontend instead of pos-web. */}
      <BrowserRouter basename={import.meta.env.BASE_URL}>
        <AppProvider>
          <QueryProvider>
            <AuthProvider>
              <WorkstationProvider>
                <App />
                {/* #1170 — dưới AppProvider vì nó cần t(); trên Toaster vì nó
                    nói qua toast. Không render gì. */}
                <OfflineShell />
                {/* #1501 — tầng 2: nói ra rằng đang offline VÀ dữ liệu cũ tới
                    đâu. Đặt ngoài <App/> để mọi route đều có, và vì nó `fixed`
                    nên không đụng vào layout `h-screen` của màn POS. */}
                <WorkstationAlertBanner />
                <AuthRecoveryBanner />
                <OfflineBanner />
                {/* #1501 — chạy hàng đợi hành động NHẸ (không tiền) khi mạng
                    trở lại. Thanh toán / mở ca / đóng ca không đi đường này:
                    chúng bị khoá hẳn khi offline. */}
                <LightActionReplayer />
                <Toaster
                  position="top-right"
                  richColors
                  closeButton
                  duration={3000}
                />
              </WorkstationProvider>
            </AuthProvider>
          </QueryProvider>
        </AppProvider>
      </BrowserRouter>
    </ErrorBoundary>
  </StrictMode>,
);
