"use client";

/**
 * Plan-032 T7.3 — Manual-settle dialog for expired sessions (Decision 8b).
 *
 * Manager reconciles an expired session post-hoc. Required input is the
 * `closing_counts` grid (manager re-counts the drawer) + `manual_settle_reason`
 * (≥ 20 chars). Two collapsible advanced sections expose the Decision 8b
 * overrides:
 *   - opening_counts_override — replaces recorded opening counts. Each
 *     supplied override emits a separate `till_session_opening_overridden`
 *     audit row server-side.
 *   - post_hoc_cash_events — inserts cash events the cashier never logged,
 *     marked manual_adjustment=true.
 *
 * This is NOT a 1:1 transplant of pos-web's close-page (that's a follow-up
 * iteration once admin-web adopts the same denomination grid widget). For
 * v1 admin-web ships a denominations-only closing grid + reason fields,
 * which covers the common path (cashier disappeared, manager recovers the
 * paper count, no surprise tender mix-ups).
 *
 * #1178 — the grid must never render blank. `closing_counts` is required
 * server-side, so no inputs (denominations failed to load, or every row was
 * filtered out) meant an unavoidable 422 on submit. The load/empty state is
 * explicit now, and an emptied drawer is sent as zero-quantity rows behind a
 * deliberate ack instead of as an empty array.
 *
 * The dialog also shows the money it is reconciling against: opening float,
 * expected cash (from `/pos/till/sessions/{id}/reconciliation` — the session
 * row's `expected_cash_amount` is NULL until close), the running counted
 * total, and the live variance. Counting a drawer with no target on screen
 * meant the manager only learned the variance from the settle response.
 */

import { useMemo, useState } from "react";
import { Banknote, Coins } from "lucide-react";
import {
  Alert,
  AlertDescription,
  AlertTitle,
  Badge,
  Button,
  Checkbox,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Label,
  Spinner,
  Textarea,
} from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { useDenominations } from "@/hooks/api/use-denominations";
import { useSessionReconciliation } from "@/hooks/api/use-till-sessions-admin";
import { formatCurrency } from "@/lib/currency";
import type { ManualSettleInput, StaleSession } from "@/services/till-session-admin-service";

/** Mirrors `manual_settle_reason` in ManualSettleTillSessionRequest (min:20, max:2000). */
const REASON_MIN_LENGTH = 20;
const REASON_MAX_LENGTH = 2000;

export interface ManualSettleDialogProps {
  open: boolean;
  shopSlug: string;
  session: StaleSession | null;
  isPending?: boolean;
  /**
   * The till's cash-variance tolerance. Beyond it the backend demands a
   * variance reason (422 VARIANCE_REASON_REQUIRED) — when the caller knows the
   * number, the dialog enforces the same rule up front instead of letting the
   * manager discover it via a failed submit. Omit when unknown (the stale
   * list rows don't carry it).
   */
  varianceTolerance?: number | null;
  onOpenChange: (open: boolean) => void;
  onConfirm: (input: ManualSettleInput) => void;
}

interface CountEntry {
  denomination_id: string;
  quantity: number;
}

export function ManualSettleDialog({
  open,
  shopSlug,
  session,
  isPending = false,
  varianceTolerance,
  onOpenChange,
  onConfirm,
}: ManualSettleDialogProps) {
  const { t, locale } = useTranslation();
  const currency = session?.default_currency_code;
  const denomsQuery = useDenominations(shopSlug, currency);
  // Expected cash is NOT on the session row — `expected_cash_amount` is only
  // written at close, so an expired shift has none. Read the same
  // reconciliation the settle math runs on, so the manager counts against a
  // real target instead of guessing (#1178).
  const reconQuery = useSessionReconciliation(shopSlug, session?.id ?? null, open);
  // `GET /shops/{slug}/denominations` already filters `is_active = true`
  // server-side; this only guards an explicitly-inactive row. Compare against
  // `false` rather than truthiness — an API build that omits the field used to
  // drop EVERY row here, leaving the grid empty and settlement impossible
  // (#1178).
  const denoms = useMemo(
    () => (denomsQuery.data?.data ?? []).filter((d) => d.is_active !== false),
    [denomsQuery.data?.data]
  );

  const [closingCounts, setClosingCounts] = useState<Record<string, number>>({});
  const [emptyDrawer, setEmptyDrawer] = useState(false);
  const [reason, setReason] = useState("");
  const [closingNote, setClosingNote] = useState("");
  const [overrideOpening, setOverrideOpening] = useState(false);
  const [overrideAcknowledged, setOverrideAcknowledged] = useState(false);
  const [openingOverride, setOpeningOverride] = useState<Record<string, number>>({});

  // Reset state through onOpenChange instead of useEffect — keeps setState
  // calls in event handlers (react-compiler warning).
  const handleOpenChange = (next: boolean) => {
    if (!next) {
      setClosingCounts({});
      setEmptyDrawer(false);
      setReason("");
      setClosingNote("");
      setOverrideOpening(false);
      setOverrideAcknowledged(false);
      setOpeningOverride({});
    }
    onOpenChange(next);
  };

  const handleEmptyDrawerChange = (next: boolean) => {
    setEmptyDrawer(next);
    if (next) setClosingCounts({});
  };

  const reasonLen = reason.trim().length;
  const reasonValid = reasonLen >= REASON_MIN_LENGTH && reasonLen <= REASON_MAX_LENGTH;
  const overrideReady = !overrideOpening || overrideAcknowledged;
  // The backend requires closing_counts to be non-empty (a settled shift must
  // carry a count), so an all-zero drawer has to be sent as explicit zero rows
  // — the `emptyDrawer` ack — rather than as an empty array. Blocking submit
  // here is what keeps a 422 from surfacing as a bare "settle failed" toast.
  const countedSomething = Object.values(closingCounts).some((qty) => qty > 0);
  const countsReady = denoms.length > 0 && (emptyDrawer || countedSomething);

  // Running drawer total from the grid — the same sum the server rebuilds from
  // the posted counts, so what the manager sees is what gets settled.
  const countedCash = emptyDrawer
    ? 0
    : denoms.reduce((sum, d) => sum + Number(d.value) * (closingCounts[d.id] ?? 0), 0);
  const openingFloat = session?.opening_float_amount ?? null;
  const expectedCash = reconQuery.data?.data.cash.expected_cash ?? null;
  const variance = expectedCash === null ? null : countedCash - expectedCash;
  // Mirror close()'s guard: past tolerance the backend demands a reason, and
  // `closing_note` is the field it accepts. Only enforced when the caller told
  // us the tolerance AND we know the variance.
  const varianceNeedsReason =
    variance !== null &&
    varianceTolerance != null &&
    Math.abs(variance) > varianceTolerance &&
    countsReady;
  const varianceReasonReady = !varianceNeedsReason || closingNote.trim() !== "";

  const canSubmit =
    Boolean(session) &&
    countsReady &&
    reasonValid &&
    overrideReady &&
    varianceReasonReady &&
    !isPending;

  const fmt = (amount: number) => formatCurrency(amount, locale, currency ?? "JPY");

  const buildEntries = (map: Record<string, number>): CountEntry[] =>
    Object.entries(map)
      .filter(([, qty]) => qty > 0)
      .map(([denomination_id, quantity]) => ({ denomination_id, quantity }));

  const handleSubmit = () => {
    if (!canSubmit || !session) return;
    const closing = emptyDrawer
      ? denoms.map((d) => ({ denomination_id: d.id, quantity: 0 }))
      : buildEntries(closingCounts);
    const override = overrideOpening ? buildEntries(openingOverride) : null;

    onConfirm({
      closing_counts: closing,
      tender_details: [],
      closing_note: closingNote.trim() === "" ? null : closingNote.trim(),
      manual_settle_reason: reason.trim(),
      opening_counts_override: override && override.length > 0 ? override : null,
      post_hoc_cash_events: null,
    });
  };

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        data-slot="manual-settle-dialog"
        className="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
        onEscapeKeyDown={(e) => isPending && e.preventDefault()}
        onPointerDownOutside={(e) => isPending && e.preventDefault()}
      >
        <DialogHeader>
          <DialogTitle>
            {session ? t("till_sessions.manual_settle.title", { code: session.session_code }) : ""}
          </DialogTitle>
          <DialogDescription>{t("till_sessions.manual_settle.description")}</DialogDescription>
        </DialogHeader>

        <div className="space-y-5">
          {/* Money reference — what the drawer SHOULD hold, and what the grid
              currently adds up to. Without it the manager counts blind and
              only learns the variance from the settle response. */}
          <section data-slot="manual-settle-summary" className="rounded-md border bg-muted/40 p-3">
            <dl className="grid grid-cols-1 gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
              <div className="flex items-baseline justify-between gap-2">
                <dt className="text-muted-foreground">{t("till_sessions.detail.opening_float")}</dt>
                <dd className="font-mono">{openingFloat === null ? "—" : fmt(openingFloat)}</dd>
              </div>
              <div className="flex items-baseline justify-between gap-2">
                <dt className="text-muted-foreground">{t("till_sessions.detail.expected_cash")}</dt>
                <dd className="font-mono">
                  {reconQuery.isLoading ? (
                    <Spinner className="size-3.5" />
                  ) : expectedCash === null ? (
                    "—"
                  ) : (
                    fmt(expectedCash)
                  )}
                </dd>
              </div>
              <div className="flex items-baseline justify-between gap-2">
                <dt className="text-muted-foreground">{t("till_sessions.detail.counted_cash")}</dt>
                <dd className="font-mono">{fmt(countedCash)}</dd>
              </div>
              <div className="flex items-baseline justify-between gap-2">
                <dt className="text-muted-foreground">{t("till_sessions.detail.cash_variance")}</dt>
                <dd
                  className={
                    variance === null || variance === 0
                      ? "font-mono"
                      : variance > 0
                        ? "font-mono text-emerald-600"
                        : "font-mono text-red-600"
                  }
                >
                  {variance === null ? "—" : fmt(variance)}
                </dd>
              </div>
            </dl>
            {varianceTolerance != null && (
              <p className="mt-2 text-xs text-muted-foreground">
                {t("till_sessions.manual_settle.tolerance_hint", {
                  tolerance: fmt(varianceTolerance),
                })}
              </p>
            )}
          </section>

          {/* Closing counts grid */}
          <section>
            <div className="mb-2 flex items-center justify-between">
              <Label>{t("till_sessions.detail.counted_cash")}</Label>
              {session && <Badge variant="outline">{session.default_currency_code}</Badge>}
            </div>
            {denomsQuery.isLoading ? (
              <div className="flex items-center justify-center py-6">
                <Spinner className="size-5" />
              </div>
            ) : denomsQuery.isError || denoms.length === 0 ? (
              // Never render a silent blank here: with no inputs the manager
              // cannot count, and submit would post an empty closing_counts
              // (#1178). Say what happened and offer a retry.
              <Alert variant="destructive">
                <AlertTitle>{t("till_sessions.manual_settle.denominations_missing")}</AlertTitle>
                <AlertDescription className="space-y-2">
                  <p>
                    {denomsQuery.isError
                      ? t("common.error_loading")
                      : t("till_sessions.manual_settle.denominations_empty_hint", {
                          currency: session?.default_currency_code ?? "",
                        })}
                  </p>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => denomsQuery.refetch()}
                    disabled={denomsQuery.isFetching}
                  >
                    {denomsQuery.isFetching && <Spinner className="mr-2 size-4" />}
                    {t("common.retry")}
                  </Button>
                </AlertDescription>
              </Alert>
            ) : (
              <>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  {denoms.map((d) => (
                    <div
                      key={d.id}
                      className="flex items-center justify-between rounded-md border bg-card p-2 text-sm"
                    >
                      <div>
                        <div className="font-mono text-xs text-muted-foreground">
                          {d.kind === "coin" ? <Coins className="mr-1 inline h-3.5 w-3.5 align-text-bottom" aria-hidden /> : <Banknote className="mr-1 inline h-3.5 w-3.5 align-text-bottom" aria-hidden />}
                          {Number(d.value).toLocaleString()}{" "}
                          {d.currency_code}
                        </div>
                        {d.label && <div className="text-xs">{d.label}</div>}
                      </div>
                      <Input
                        type="number"
                        min={0}
                        className="w-20 text-right"
                        value={closingCounts[d.id]?.toString() ?? ""}
                        onChange={(e) =>
                          setClosingCounts((prev) => ({
                            ...prev,
                            [d.id]: Math.max(0, Number(e.target.value) || 0),
                          }))
                        }
                        disabled={isPending || emptyDrawer}
                      />
                    </div>
                  ))}
                </div>

                {/* An emptied drawer is a real outcome on a stuck shift — it
                    still has to be COUNTED, so this posts every denomination
                    at quantity 0 instead of nothing at all. */}
                <label className="mt-2 flex items-center gap-2 text-sm">
                  <Checkbox
                    checked={emptyDrawer}
                    onCheckedChange={(v) => handleEmptyDrawerChange(Boolean(v))}
                    disabled={isPending}
                  />
                  <span>{t("till_sessions.manual_settle.empty_drawer_label")}</span>
                </label>

                {!countsReady && (
                  <p className="mt-1.5 text-xs text-muted-foreground">
                    {t("till_sessions.manual_settle.counts_required")}
                  </p>
                )}
              </>
            )}
          </section>

          {/* Reason */}
          <section className="space-y-1.5">
            <Label htmlFor="manual-settle-reason">
              {t("till_sessions.manual_settle.reason_label")}
            </Label>
            <Textarea
              id="manual-settle-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder={t("till_sessions.manual_settle.reason_placeholder")}
              rows={3}
              maxLength={REASON_MAX_LENGTH}
              aria-invalid={reasonLen > 0 && reasonLen < REASON_MIN_LENGTH ? true : undefined}
              disabled={isPending}
            />
            {/* `20` is a MINIMUM, not a cap — a bare "23/20" reads as an
                overflow. Spell the rule out. */}
            <div className="flex justify-end text-xs">
              <span className={reasonValid ? "text-muted-foreground" : "text-destructive"}>
                {t("till_sessions.manual_settle.reason_counter", {
                  count: reasonLen,
                  min: REASON_MIN_LENGTH,
                })}
              </span>
            </div>
          </section>

          {/* Closing note — the field close() accepts as the cash-variance
              reason. Labelled optional, but REQUIRED once the variance passes
              the till's tolerance, which is why the label flips. */}
          <section className="space-y-1.5">
            <Label htmlFor="manual-settle-closing-note">
              {varianceNeedsReason
                ? t("till_sessions.manual_settle.variance_reason_label")
                : t("common.note_optional")}
            </Label>
            <Input
              id="manual-settle-closing-note"
              value={closingNote}
              onChange={(e) => setClosingNote(e.target.value)}
              aria-invalid={varianceNeedsReason && !varianceReasonReady ? true : undefined}
              disabled={isPending}
            />
            {varianceNeedsReason && !varianceReasonReady && (
              <p className="text-xs text-destructive">
                {t("till_sessions.manual_settle.variance_reason_required", {
                  variance: fmt(variance ?? 0),
                })}
              </p>
            )}
          </section>

          {/* Advanced: override opening counts */}
          <section className="space-y-3 rounded-md border border-dashed p-3">
            <div className="flex items-center justify-between">
              <Label className="font-medium">
                {t("till_sessions.manual_settle.advanced_section")}
              </Label>
              <div className="flex items-center gap-2">
                <Checkbox
                  id="manual-settle-override-toggle"
                  checked={overrideOpening}
                  onCheckedChange={(v) => setOverrideOpening(Boolean(v))}
                  disabled={isPending}
                />
                <Label htmlFor="manual-settle-override-toggle" className="cursor-pointer text-sm">
                  {t("till_sessions.manual_settle.override_opening_label")}
                </Label>
              </div>
            </div>

            {overrideOpening && (
              <>
                <Alert>
                  <AlertTitle>{t("till_sessions.manual_settle.override_opening_label")}</AlertTitle>
                  <AlertDescription>
                    {t("till_sessions.manual_settle.override_opening_warning")}
                  </AlertDescription>
                </Alert>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  {denoms.map((d) => (
                    <div
                      key={d.id}
                      className="flex items-center justify-between rounded-md border bg-card p-2 text-sm"
                    >
                      <div className="font-mono text-xs text-muted-foreground">
                        {Number(d.value).toLocaleString()} {d.currency_code}
                      </div>
                      <Input
                        type="number"
                        min={0}
                        className="w-20 text-right"
                        value={openingOverride[d.id]?.toString() ?? ""}
                        onChange={(e) =>
                          setOpeningOverride((prev) => ({
                            ...prev,
                            [d.id]: Math.max(0, Number(e.target.value) || 0),
                          }))
                        }
                        disabled={isPending}
                      />
                    </div>
                  ))}
                </div>
                <label className="flex items-center gap-2 text-xs">
                  <Checkbox
                    checked={overrideAcknowledged}
                    onCheckedChange={(v) => setOverrideAcknowledged(Boolean(v))}
                    disabled={isPending}
                  />
                  <span>{t("till_sessions.manual_settle.override_opening_warning")}</span>
                </label>
              </>
            )}
          </section>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => handleOpenChange(false)} disabled={isPending}>
            {t("till_sessions.manual_settle.cancel")}
          </Button>
          <Button onClick={handleSubmit} disabled={!canSubmit}>
            {isPending && <Spinner className="mr-2 size-4" />}
            {t("till_sessions.manual_settle.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
