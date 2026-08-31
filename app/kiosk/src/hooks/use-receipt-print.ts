// src/hooks/use-receipt-print.ts
//
// Drives receipt printing through the workstation (single print authority).
// The kiosk no longer talks to a Star printer directly — it just asks the
// workstation to (re)print the paid receipt for an order id.

import { useCallback, useState } from 'react';
import { WorkstationUnavailableError, printKioskReceipt } from '../lib/api';

export type PrintStatus = 'idle' | 'printing' | 'success' | 'error';

export interface UseReceiptPrintReturn {
  status: PrintStatus;
  error: string | null;
  /** i18n key describing a known failure cause, when we have one. */
  errorKey: string | null;
  /** Ask the workstation to print the receipt for this order id. */
  print: (orderId: string) => Promise<void>;
  /** Reset state (call when navigating away). */
  reset: () => void;
}

/** Delay between the initial print attempt and the single automatic retry. */
const RETRY_DELAY_MS = 2000;

const delay = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

export function useReceiptPrint(): UseReceiptPrintReturn {
  const [status, setStatus] = useState<PrintStatus>('idle');
  const [error, setError] = useState<string | null>(null);
  const [errorKey, setErrorKey] = useState<string | null>(null);

  const print = useCallback(async (orderId: string) => {
    if (!orderId) {
      setStatus('error');
      setError('no_order');
      setErrorKey(null);
      return;
    }
    setStatus('printing');
    setError(null);
    setErrorKey(null);
    // One automatic retry. The workstation print path fails transiently more
    // often than it fails hard — printer waking from sleep, a brief LAN drop —
    // so a single retry clears most cases without making staff tap "reprint".
    for (let attempt = 0; attempt < 2; attempt++) {
      try {
        await printKioskReceipt(orderId);
        setStatus('success');
        setError(null);
        setErrorKey(null);
        return;
      } catch (err) {
        // No workstation on this LAN is a configuration problem, not a
        // transient one — retrying can only find the same nothing. Say so
        // plainly: before issue #44 this path silently 404'd against Cloud.
        if (err instanceof WorkstationUnavailableError) {
          setStatus('error');
          setError(null);
          setErrorKey('workstation.print_no_workstation');
          return;
        }
        if (attempt === 0) {
          await delay(RETRY_DELAY_MS);
          continue;
        }
        setStatus('error');
        setError(err instanceof Error ? err.message : String(err));
        setErrorKey(null);
      }
    }
  }, []);

  const reset = useCallback(() => {
    setStatus('idle');
    setError(null);
    setErrorKey(null);
  }, []);

  return { status, error, errorKey, print, reset };
}
