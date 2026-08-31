import { createContext, useContext } from "react";

export interface RealtimeCtx {
  /**
   * Record an idempotency key sent by the local client. Per-operation hooks
   * (useMarkPreparing/Ready/Served, useRevertItem) record one UUID each.
   * useBumpAll records `${batchKey}:${itemId}` for every item it expects
   * cloud to bump.
   */
  recordBumpKey: (key: string) => void;
  /** True when the WS connection is open and auth_ok has been received. */
  isConnected: boolean;
}

export const RealtimeContext = createContext<RealtimeCtx | null>(null);

export function useRealtime(): RealtimeCtx {
  const c = useContext(RealtimeContext);
  if (!c) throw new Error("useRealtime must be used inside RealtimeProvider");
  return c;
}
