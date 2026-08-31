// src/hooks/use-terminal-cancel.ts
import { useCallback, useEffect, useRef } from 'react';
import type { PaymentStatus } from '../types/kiosk';
import type { TerminalStatus, VescaErrorEvent } from '../types/terminal';

interface UseTerminalCancelOptions {
  /** Send the cancel signal to the terminal (terminal-provider's `cancel`). */
  cancel: () => void;
  /** Mark the backend payment as failed (usePayment's `fail`). */
  fail: (reason?: string, errorCode?: string) => Promise<void>;
  /** One-shot fetch of the backend payment status (usePayment's `checkStatus`). */
  checkStatus: () => Promise<PaymentStatus | null>;
  /** Current terminal status from terminal-provider. */
  terminalStatus: TerminalStatus;
  /** Current terminal error from terminal-provider (used to forward reason/code to `fail`). */
  terminalError: VescaErrorEvent | null;
  /** Called when `checkStatus` returns 'succeeded' — caller navigates to the success screen. */
  onSuccess: () => void;
  /** Called after `fail()` resolves when status was not 'succeeded' — caller navigates back. */
  onCancelled: () => void;
}

interface UseTerminalCancelResult {
  execute: () => Promise<void>;
}

const CANCEL_WAIT_MS = 3000;

/**
 * Encapsulates the kiosk's terminal-cancel flow with ghost-payment protection.
 *
 * Flow:
 *  1. Send cancel command to terminal (POS_FORCE_CANCEL).
 *  2. Wait up to 3s for terminal to acknowledge (status flips to error/ready/idle).
 *  3. Re-check backend payment status — the terminal may have captured funds
 *     just before our cancel landed, in which case status is now 'succeeded'.
 *  4. If 'succeeded': skip fail(), invoke `onSuccess` so the caller can navigate to
 *     the success/receipt screen instead of stranding the customer.
 *  5. Else: call fail() so backend marks payment 'failed', then invoke
 *     `onCancelled` so the caller can navigate back to the previous step.
 *
 * Without step 3, ghost payments occur: terminal captures funds, backend flips
 * to 'failed', customer is charged with no receipt.
 *
 * The 3s wait preserves the contract from terminal-connection-fix-plan.md
 * "Thay đổi 4" — the app must wait for the terminal to ack the cancel before
 * navigating away, otherwise a stale REQUEST may still be in flight.
 */
export function useTerminalCancel(
  options: UseTerminalCancelOptions,
): UseTerminalCancelResult {
  const {
    cancel,
    fail,
    checkStatus,
    terminalStatus,
    terminalError,
    onSuccess,
    onCancelled,
  } = options;

  const cancelResolveRef = useRef<(() => void) | null>(null);

  // Resolve the pending cancel-wait promise as soon as the terminal acks the
  // cancel by flipping to a terminal state (error/ready/idle/success). 'success'
  // is included so that the ghost-payment path (terminal captured funds during
  // the wait) short-circuits the 3s timeout — the post-wait status check still
  // detects 'succeeded' and routes to onSuccess.
  useEffect(() => {
    if (
      cancelResolveRef.current &&
      (terminalStatus === 'error' ||
        terminalStatus === 'ready' ||
        terminalStatus === 'idle' ||
        terminalStatus === 'success')
    ) {
      cancelResolveRef.current();
      cancelResolveRef.current = null;
    }
  }, [terminalStatus]);

  const execute = useCallback(async () => {
    cancel();

    // Wait for the terminal to ack the cancel, or fall through after 3s.
    await new Promise<void>((resolve) => {
      cancelResolveRef.current = resolve;
      setTimeout(() => {
        cancelResolveRef.current = null;
        resolve();
      }, CANCEL_WAIT_MS);
    });

    // Ghost-payment guard: the terminal may have captured funds during the wait.
    const status = await checkStatus();
    if (status === 'succeeded') {
      onSuccess();
      return;
    }

    await fail(
      terminalError?.ErrorEvent?.Message,
      terminalError?.ErrorEvent?.Errorcodedetail,
    );
    onCancelled();
  }, [cancel, fail, checkStatus, terminalError, onSuccess, onCancelled]);

  return { execute };
}
