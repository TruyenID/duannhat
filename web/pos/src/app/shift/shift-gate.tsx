/**
 * ShiftGate — Plan 030 wrapper around /shop/:shopSlug + Plan-032 expire-modal.
 *
 * Plan-030: If the till has no open session, redirects to
 *   /shop/:shopSlug/shift/open. Pure read-only useTillCurrent call.
 *
 * Plan-032 T8.1 (Decision 11): When `useTillCurrent` polling tick reports
 * `open_session === null` AFTER previously reporting a session, render a
 * BLOCKING modal that requires explicit user confirmation to navigate.
 * The auto-navigate path (legacy below) is still used on initial mount
 * when there was no prior session — that's "cashier opens the app, no
 * shift" which doesn't need the modal interruption. The modal exists
 * specifically for the "cashier was mid-action when system expired the
 * session" case where dropping context silently would lose work
 * (counting drawer, reprinting receipt, taking a note).
 */

import { useEffect, useRef, useState } from "react";
import { Navigate, useNavigate, useParams } from "react-router-dom";
import { ApiError } from "@/lib/api";
import { HelpButton } from "@/help/help-button";
import { WorkstationUrlEditor } from "@/app/pos/components/workstation-url-editor";
import { useTillCurrent } from "@/hooks/api/use-till";
import { useTranslation } from "@/providers/app-provider";
import { useWorkstation } from "@/providers/workstation-provider";
import { hasWorkstation } from "@/services/workstation/base-url-resolver";

export function ShiftGate({ children }: { children: React.ReactNode }) {
  const { shopSlug = "" } = useParams<{ shopSlug: string }>();
  const current = useTillCurrent(shopSlug);
  const navigate = useNavigate();
  const { t } = useTranslation();
  const { mode, setMode } = useWorkstation();

  const hadSessionRef = useRef(false);
  const [showExpiredModal, setShowExpiredModal] = useState(false);

  useEffect(() => {
    if (!current.isFetched) return;

    const hasSession = Boolean(current.data?.open_session);
    if (hadSessionRef.current && !hasSession) {
      // Session disappeared between polls — expired or remote-closed.
      setShowExpiredModal(true);
    }
    hadSessionRef.current = hasSession;
  }, [current.isFetched, current.data?.open_session]);

  if (current.isLoading) {
    return null; // Don't flash either screen while resolving.
  }

  // Shop-context failure guard. `GET /pos/till/current` 404s when the shop
  // slug doesn't resolve (ResolvePosShop → abort 404 'Shop not found') and
  // 403s when the device has no access. Without this branch `current.data`
  // is `undefined`, so the redirect below would wrongly funnel a non-existent
  // shop into the Open-shift screen (issue #544). Distinguish the error from
  // a legitimate "no open session" (which is `isSuccess` with data) and show
  // a blocking error instead of redirecting.
  if (current.isError) {
    const status =
      current.error instanceof ApiError ? current.error.status : undefined;
    const message =
      status === 404
        ? t("shift.gate.error.not_found")
        : status === 403
          ? t("shift.gate.error.no_access")
          : t("shift.gate.error.generic");

    // A 404/403 is a real shop-context problem — switching Cloud↔Workstation
    // wouldn't help. Anything else (status undefined = network failure) means
    // the currently-pinned target is unreachable: Cloud is down (mode
    // "cloud"/"auto"), OR the paired workstation address is wrong — e.g. the
    // shop changed the workstation's port (mode "workstation"). Either way
    // "Retry" alone just re-issues the same doomed request, and the cashier
    // has no way to reach Settings from this full-screen gate — so surface
    // both escape hatches here: flip to the other side, or fix the address.
    const isNetworkFailure = status === undefined;
    const canSwitchToWorkstation =
      isNetworkFailure && mode !== "workstation" && hasWorkstation();
    const canSwitchToCloud = isNetworkFailure && mode === "workstation";

    return (
      <div className="flex min-h-screen items-center justify-center bg-muted/30 p-4">
        <div className="relative mx-4 w-full max-w-md rounded-lg border bg-background p-6 text-center shadow-sm">
          {/* A blocking screen with no way back to Settings is exactly where an
              operator needs to be told which of the two causes they are looking
              at — a bad shop context or an unreachable target. */}
          <div className="absolute right-2 top-2">
            <HelpButton topic="shift-gate-error" />
          </div>
          <h1 className="text-lg font-semibold">{t("shift.gate.error.title")}</h1>
          <p className="mt-3 text-sm text-muted-foreground">{message}</p>
          <div className="mt-6 flex flex-col items-center gap-2">
            <button
              type="button"
              className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
              onClick={() => current.refetch()}
            >
              {t("shift.gate.error.retry")}
            </button>
            {canSwitchToWorkstation && (
              <button
                type="button"
                className="rounded-md border px-4 py-2 text-sm font-medium text-foreground hover:bg-muted"
                onClick={() => {
                  setMode("workstation");
                  current.refetch();
                }}
              >
                {t("shift.gate.error.use_workstation")}
              </button>
            )}
            {canSwitchToCloud && (
              <button
                type="button"
                className="rounded-md border px-4 py-2 text-sm font-medium text-foreground hover:bg-muted"
                onClick={() => {
                  setMode("cloud");
                  current.refetch();
                }}
              >
                {t("shift.gate.error.use_cloud")}
              </button>
            )}
          </div>
          {isNetworkFailure && mode === "workstation" && (
            <div className="mt-6 border-t pt-4 text-left">
              <p className="mb-2 text-xs text-muted-foreground">
                {t("shift.gate.error.wrong_port_hint")}
              </p>
              <WorkstationUrlEditor />
            </div>
          )}
        </div>
      </div>
    );
  }

  // Initial-mount path: no prior session, no current session → safe to
  // navigate without the modal (cashier just opened the app). Only redirect
  // on a successful fetch — an errored query is handled above so a 404 shop
  // never reaches the Open-shift screen.
  if (current.isSuccess && !current.data?.open_session && !showExpiredModal) {
    return <Navigate to={`/shop/${shopSlug}/shift/open`} replace />;
  }

  // Plan-032 T8.1 — blocking modal. NOT a regular Dialog: no close button,
  // no ESC/backdrop dismiss, single confirm action that triggers the
  // navigation explicitly. We use a hand-rolled overlay rather than the
  // library Dialog so the "can't be dismissed" contract is enforced at
  // the markup level — Radix Dialog with controlled `open` could still be
  // bypassed by an integration that listens for backdrop clicks.
  return (
    <>
      {children}
      {showExpiredModal && (
        <div
          data-slot="shift-expired-modal"
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60"
          role="dialog"
          aria-modal="true"
          aria-labelledby="shift-expired-title"
        >
          <div className="relative mx-4 max-w-md rounded-lg bg-background p-6 shadow-lg">
            <div className="absolute right-2 top-2">
              <HelpButton topic="shift-expired" />
            </div>
            <h2 id="shift-expired-title" className="text-lg font-semibold">
              {t("shift.expired_modal.title")}
            </h2>
            <p className="mt-3 text-sm text-muted-foreground">
              {t("shift.expired_modal.body")}
            </p>
            <div className="mt-6 flex justify-end">
              <button
                type="button"
                className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                onClick={() => {
                  setShowExpiredModal(false);
                  navigate(`/shop/${shopSlug}/shift/open`, { replace: true });
                }}
              >
                {t("shift.expired_modal.confirm")}
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
