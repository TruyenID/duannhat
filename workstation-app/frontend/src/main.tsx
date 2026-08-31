import React, { useEffect, useState } from "react";
import ReactDOM from "react-dom/client";
import { BrowserRouter, Routes, Route } from "react-router";
import { AppProvider } from "./providers/app-provider";
import "./app.css";
import { MainLayout } from "./layouts/MainLayout";
import { Dashboard } from "./pages/Dashboard";
import { Orders } from "./pages/Orders";
import { Menu } from "./pages/Menu";
import { PeripheralsList } from "./pages/peripherals/PeripheralsList";
import { PeripheralNew } from "./pages/peripherals/PeripheralNew";
import { PeripheralEdit } from "./pages/peripherals/PeripheralEdit";
import { PrinterEdit } from "./pages/peripherals/PrinterEdit";
import { Reports } from "./pages/Reports";
import { Sync } from "./pages/Sync";
import { SyncRecovery } from "./pages/SyncRecovery";
import { Settings } from "./pages/Settings";
import { Pairing } from "./pages/Pairing";
import { getDeviceStatus } from "./lib/api";
import { initSentry, captureException } from "./lib/sentry";
import { WsProvider } from "./lib/ws-context";
import { TerminalBridge } from "./providers/terminal-bridge";

// Initialise error tracking BEFORE React mounts so a crash during
// pairing-status fetch is captured. Silent no-op when VITE_SENTRY_DSN
// is unset (dev / tests / unwired Wails builds).
initSentry();

class ErrorBoundary extends React.Component<
  { children: React.ReactNode },
  { error: Error | null }
> {
  constructor(props: { children: React.ReactNode }) {
    super(props);
    this.state = { error: null };
  }
  static getDerivedStateFromError(error: Error) {
    return { error };
  }
  componentDidCatch(error: Error, info: React.ErrorInfo) {
    captureException(error, {
      tags: { boundary: "global" },
      extra: { componentStack: info.componentStack ?? "" },
    });
  }
  render() {
    if (this.state.error) {
      return (
        <div style={{ padding: 40, color: "#ef4444", background: "#1e293b", fontFamily: "monospace", minHeight: "100vh" }}>
          <h1 style={{ fontSize: 24, marginBottom: 16 }}>Render Error</h1>
          <pre style={{ whiteSpace: "pre-wrap" }}>{this.state.error.message}</pre>
          <pre style={{ whiteSpace: "pre-wrap", marginTop: 8, fontSize: 12, opacity: 0.7 }}>{this.state.error.stack}</pre>
        </div>
      );
    }
    return this.props.children;
  }
}

function App() {
  const [paired, setPaired] = useState<boolean | null>(null);
  const [needsRepair, setNeedsRepair] = useState(false);
  const [deviceToken, setDeviceToken] = useState("");

  useEffect(() => {
    checkPairing();
    // Re-check periodically so a mid-session cloud 401 (token revoked, e.g.
    // after a cloud reseed) flips the UI back to the pairing screen instead of
    // leaving a silent zombie: LAN server up, sync dead. See issue #437.
    const id = setInterval(checkPairing, 20000);
    return () => clearInterval(id);
  }, []);

  async function checkPairing() {
    try {
      const status = await getDeviceStatus();
      setPaired(status.paired);
      setNeedsRepair(!!status.needs_repair);
      if (status.token) setDeviceToken(status.token);
    } catch {
      setPaired(false);
    }
  }

  if (paired === null) {
    return (
      <div style={{ minHeight: "100vh", display: "flex", alignItems: "center", justifyContent: "center" }}>
        <div style={{ color: "#94a3b8", fontSize: 14 }}>...</div>
      </div>
    );
  }

  if (!paired) {
    return <Pairing needsRepair={needsRepair} onPaired={() => { setPaired(true); setNeedsRepair(false); checkPairing(); }} />;
  }

  return (
    <WsProvider token={deviceToken}>
      {/* Always-mounted hidden host for the P400 (VescaJS) card terminal —
          bridges pos-web ↔ the LAN terminal. See pos-card-terminal-p400-vesca.md. */}
      <TerminalBridge />
      <BrowserRouter>
        <Routes>
          <Route element={<MainLayout />}>
            <Route index element={<Dashboard />} />
            <Route path="orders" element={<Orders />} />
            <Route path="menu" element={<Menu />} />
            <Route path="peripherals" element={<PeripheralsList />} />
            <Route path="peripherals/new" element={<PeripheralNew />} />
            <Route path="peripherals/printer/:id" element={<PrinterEdit />} />
            <Route path="peripherals/:id" element={<PeripheralEdit />} />
            <Route path="reports" element={<Reports />} />
            <Route path="sync" element={<Sync />} />
            <Route path="sync-recovery" element={<SyncRecovery />} />
            <Route path="settings" element={<Settings />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </WsProvider>
  );
}

ReactDOM.createRoot(document.getElementById("root") as HTMLElement).render(
  <ErrorBoundary>
    <AppProvider>
      <App />
    </AppProvider>
  </ErrorBoundary>
);
