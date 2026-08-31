// src/hooks/use-auto-print-status.ts
//
// Surfaces the result of the workstation's confirm-time AUTO-print for an order.
//
// The workstation is the single print authority — it auto-prints the paid
// receipt when a payment is confirmed. The kiosk never sees that print happen,
// so if it fails (printer offline / paper jam / LAN drop) the success screen
// would otherwise look fine while the customer walks away with no bill. The
// workstation broadcasts a `print_status` event; this hook listens for the one
// matching the current order and exposes whether the auto-print failed so the
// success screen can prompt a reprint.

import { useState } from 'react';
import { useWorkstationSubscribe } from '../providers/workstation-provider';

export interface AutoPrintStatus {
  /** True when the workstation reported the auto-print FAILED for this order. */
  failed: boolean;
  /** Machine-readable reason (e.g. "printer_offline"), when provided. */
  reason: string | null;
}

interface PrintStatusPayload {
  order_id?: string;
  kind?: string;
  status?: string;
  reason?: string;
}

export function useAutoPrintStatus(orderId: string): AutoPrintStatus {
  const [failed, setFailed] = useState(false);
  const [reason, setReason] = useState<string | null>(null);

  useWorkstationSubscribe('print_status', (payload) => {
    const p = (payload ?? {}) as PrintStatusPayload;
    if (!orderId || p.order_id !== orderId) return;
    // Only the payment receipt matters here; ignore other slip kinds.
    if (p.kind && p.kind !== 'payment_receipt') return;
    if (p.status === 'failed') {
      setFailed(true);
      setReason(p.reason ?? null);
    } else if (p.status === 'success') {
      // A later success (e.g. workstation retried) clears the warning.
      setFailed(false);
      setReason(null);
    }
  });

  return { failed, reason };
}
