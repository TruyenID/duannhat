/**
 * useCardTerminal — drive a Verifone P400 (VescaJS) card charge from pos-web.
 *
 * The workstation owns the terminal; this hook starts the async charge, polls to
 * a terminal state, and on approval refreshes the order (the workstation already
 * recorded the payment). See docs/guide/pos-card-terminal-p400-vesca.md and
 * services/card-terminal-service.ts.
 */
import { useCallback, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { getT } from "@/providers/app-provider";
import { cardTerminalService } from "@/services/card-terminal-service";
import { orderKeys, orderPaymentKeys, tableKeys } from "./query-keys";

export type CardTerminalPhase =
  | "idle"
  | "processing"
  | "approved"
  | "declined"
  /**
   * The charge ended without an answer — the workstation lost its driver, the
   * charge hit the 15-minute ceiling, or staff abandoned it. Deliberately NOT
   * folded into `declined`: the card may already have been captured, so the UI
   * has to send someone to read the P400 instead of reporting a failure.
   */
  | "unknown";

export function useCardTerminal(shopSlug: string, orderId: string) {
  const qc = useQueryClient();
  const [phase, setPhase] = useState<CardTerminalPhase>("idle");
  const [message, setMessage] = useState<string | null>(null);
  const sessionRef = useRef<string | null>(null);

  /** Start a charge and resolve true on approval, false otherwise. */
  const charge = useCallback(async (): Promise<boolean> => {
    const t = getT();
    setPhase("processing");
    setMessage(null);
    sessionRef.current = null;
    try {
      const snap = await cardTerminalService.chargeAndWait(orderId, {
        onSession: (id) => {
          sessionRef.current = id;
        },
      });

      if (snap.status === "approved") {
        setPhase("approved");
        qc.invalidateQueries({ queryKey: orderKeys.detail(shopSlug, orderId) });
        qc.invalidateQueries({ queryKey: orderKeys.all(shopSlug) });
        qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
        qc.invalidateQueries({ queryKey: orderPaymentKeys.list(shopSlug, orderId) });
        toast.success(t("pos.toast.payment_success"));
        return true;
      }

      if (snap.status === "unknown") {
        // No outcome, so claim none. The order stays open and untouched; staff
        // read the terminal and decide. A capture that lands late is still
        // recorded by the workstation, so charging again on a hunch is the one
        // way to take the money twice.
        setPhase("unknown");
        const msg = t("pos.dialog.payment.terminal_unknown");
        setMessage(snap.error ? `${msg} (${snap.error})` : msg);
        toast.warning(msg, { duration: 15_000 });
        return false;
      }

      // declined / canceled
      setPhase("declined");
      const msg = snap.error || "Card declined";
      setMessage(msg);
      toast.error(msg);
      return false;
    } catch (err) {
      // A 409 means another charge already owns the terminal — say which one,
      // because a bare "already in progress" leaves the cashier with nothing to
      // act on and no way to find the session that is blocking them.
      if (err instanceof ApiError && err.status === 409) {
        const active = err.body.active_session as
          | { order_id?: string; amount?: number; started_at?: string }
          | undefined;
        setPhase("declined");
        const msg = active?.order_id
          ? t("pos.dialog.payment.terminal_busy_detail", {
              order: active.order_id,
              amount: String(active.amount ?? ""),
            })
          : ((err.body.message as string) ?? err.message);
        setMessage(msg);
        toast.error(msg);
        return false;
      }
      setPhase("declined");
      const msg =
        err instanceof ApiError
          ? ((err.body.message as string) ?? err.message)
          : "Card terminal unavailable";
      setMessage(msg);
      toast.error(msg);
      return false;
    }
  }, [orderId, shopSlug, qc]);

  /** Ask the terminal to return the card (valid while processing). */
  const cancel = useCallback(async () => {
    if (!sessionRef.current) return;
    try {
      await cardTerminalService.cancel(sessionRef.current);
    } catch {
      /* the poll will still observe the terminal state */
    }
  }, []);

  const reset = useCallback(() => {
    setPhase("idle");
    setMessage(null);
    sessionRef.current = null;
  }, []);

  return { phase, message, charge, cancel, reset };
}
