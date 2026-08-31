// src/hooks/use-kiosk-printers.ts
//
// Reads the branch's printer config from Cloud (issue #44 Phase B) and exposes
// whether a receipt printer is configured. Cloud owns the config; the kiosk
// mirrors it to show configured printers in Settings and to tell "no receipt
// printer configured" apart from "no workstation on the LAN".

import { useQuery } from '@tanstack/react-query';
import { fetchKioskPrinters } from '../lib/api';
import { findReceiptPrinter } from '../lib/printer-utils';
import { useAuth } from '../providers/auth-provider';
import { printerKeys } from './query-keys';
import type { KioskPrinter } from '../types/printer';

// Printer config changes rarely (an admin edits it), so cache generously — this
// hook mounts on Settings AND on every success screen; a long staleTime keeps
// them all served from one cached fetch.
const STALE_MS = 5 * 60_000;

export interface UseKioskPrintersResult {
  printers: KioskPrinter[];
  receiptPrinter: KioskPrinter | undefined;
  hasReceiptPrinter: boolean;
  /** True once a fetch has SUCCEEDED — distinguishes "loaded, none" from "not yet / errored". */
  loaded: boolean;
  isLoading: boolean;
  isError: boolean;
  refetch: () => void;
}

export function useKioskPrinters(): UseKioskPrintersResult {
  // Gate on auth: Settings is reachable before pairing (the hidden gesture), and
  // firing this unauthenticated would 401 and trip the logout handler.
  const { isAuthenticated } = useAuth();

  const query = useQuery({
    queryKey: printerKeys.all,
    queryFn: fetchKioskPrinters,
    enabled: isAuthenticated,
    staleTime: STALE_MS,
  });

  const printers = query.data ?? [];
  const receiptPrinter = findReceiptPrinter(printers);

  return {
    printers,
    receiptPrinter,
    hasReceiptPrinter: receiptPrinter != null,
    loaded: query.isSuccess,
    isLoading: query.isLoading,
    isError: query.isError,
    refetch: () => {
      void query.refetch();
    },
  };
}
