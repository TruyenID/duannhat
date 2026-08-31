import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import {
  clearStoredWorkstationUrl,
  envWorkstationUrl,
  getStoredWorkstationUrl,
  setStoredWorkstationUrl,
} from "@/src/lib/storage";
import { normalizeBaseUrl } from "@/src/lib/url";
import { checkWorkstationHealth } from "@/src/services/workstation/health";
import { bootStatusFor, type BootStatus } from "@/src/lib/boot-status";


interface WorkstationContextValue {
  bootStatus: BootStatus;
  baseUrl: string | null;
  healthy: boolean;
  selectWorkstation: (url: string) => Promise<boolean>;
  clearWorkstation: () => Promise<void>;
  refreshHealth: () => Promise<boolean>;
}

const WorkstationContext = createContext<WorkstationContextValue | null>(null);

export function WorkstationProvider({ children }: { children: ReactNode }) {
  const [bootStatus, setBootStatus] = useState<BootStatus>("loading");
  const [baseUrl, setBaseUrl] = useState<string | null>(null);
  const [healthy, setHealthy] = useState(false);

  const refreshHealth = useCallback(async (): Promise<boolean> => {
    if (!baseUrl) {
      setHealthy(false);
      return false;
    }
    const ok = await checkWorkstationHealth(baseUrl);
    setHealthy(ok);
    return ok;
  }, [baseUrl]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      const stored = (await getStoredWorkstationUrl()) ?? envWorkstationUrl();
      if (cancelled) return;
      if (!stored) {
        setBootStatus(bootStatusFor(stored));
        return;
      }
      const normalized = normalizeBaseUrl(stored);
      setBaseUrl(normalized);
      setBootStatus(bootStatusFor(stored));
      const ok = await checkWorkstationHealth(normalized);
      if (cancelled) return;
      setHealthy(ok);
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const selectWorkstation = useCallback(async (url: string): Promise<boolean> => {
    const normalized = normalizeBaseUrl(url);
    if (!normalized) return false;
    const ok = await checkWorkstationHealth(normalized);
    if (!ok) return false;
    await setStoredWorkstationUrl(normalized);
    setBaseUrl(normalized);
    setHealthy(true);
    setBootStatus("ready");
    return true;
  }, []);

  const clearWorkstation = useCallback(async () => {
    await clearStoredWorkstationUrl();
    setBaseUrl(null);
    setHealthy(false);
    setBootStatus("needs_setup");
  }, []);

  const value = useMemo(
    () => ({
      bootStatus,
      baseUrl,
      healthy,
      selectWorkstation,
      clearWorkstation,
      refreshHealth,
    }),
    [bootStatus, baseUrl, healthy, selectWorkstation, clearWorkstation, refreshHealth],
  );

  return (
    <WorkstationContext.Provider value={value}>{children}</WorkstationContext.Provider>
  );
}

export function useWorkstation(): WorkstationContextValue {
  const ctx = useContext(WorkstationContext);
  if (!ctx) {
    throw new Error("useWorkstation must be used within WorkstationProvider");
  }
  return ctx;
}
