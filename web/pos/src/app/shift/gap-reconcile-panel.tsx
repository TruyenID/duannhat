/**
 * Gap-reconcile panel — /shop/:shopSlug/shift/open.
 *
 * plan-044 R2. During the previous shift's close (精算) → next shift's open (レジ開け)
 * window there is no open cashier shift, yet payments can still be taken. Those land
 * with till_session_id = NULL ("gap payments"). This panel lists them so the cashier
 * CONFIRMS which belong to the new shift; confirmed ids are stamped to the new session
 * (backend + workstation) at open.
 *
 * Cash gap payments are claimable too — the staff physically HELD the cash aside (not
 * folded into the opening float), so it is real money owed to this shift. Ticking any
 * cash row requires the "held-separately" acknowledgement, which gates the open submit
 * (enforced in open-page via {@link GapClaimState}).
 *
 * Non-blocking: a fetch error or empty gap renders nothing and never stops the cashier
 * opening a shift.
 */

import { useEffect, useMemo, useState } from "react";
import {
  Alert,
  AlertDescription,
  AlertTitle,
  Badge,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Checkbox,
} from "@godxjp/ui";
import { CoinsIcon, InfoIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { HelpButton } from "@/help/help-button";
import { useGapPreview } from "@/hooks/api/use-till";
import { CURRENCIES, type CurrencyCode, formatMoney } from "./currency";
import type { GapClaimState } from "./gap-claim";

export function GapReconcilePanel({
  shopSlug,
  fallbackCurrency,
  onChange,
  t,
}: {
  shopSlug: string;
  /** Currency to format with when the preview payload omits one. */
  fallbackCurrency: CurrencyCode;
  onChange: (state: GapClaimState) => void;
  t: (k: string, p?: Record<string, string>) => string;
}) {
  const gap = useGapPreview(shopSlug);
  const [selected, setSelected] = useState<Record<string, boolean>>({});
  const [ack, setAck] = useState(false);

  const payments = useMemo(() => gap.data?.payments ?? [], [gap.data]);

  const cur =
    (gap.data?.currency_code &&
      gap.data.currency_code in CURRENCIES &&
      CURRENCIES[gap.data.currency_code as CurrencyCode]) ||
    CURRENCIES[fallbackCurrency];

  const claimedIds = useMemo(
    () => payments.filter((p) => selected[p.id]).map((p) => p.id),
    [payments, selected],
  );
  const cashSelected = useMemo(
    () => payments.some((p) => selected[p.id] && p.is_cash),
    [payments, selected],
  );

  // Report selection up so open-page can gate submit + build the payload. Reset
  // the ack whenever no cash is selected so a stale tick can't leak into submit.
  useEffect(() => {
    const effectiveAck = cashSelected ? ack : false;
    onChange({ claimedIds, cashSelected, ack: effectiveAck });
  }, [claimedIds, cashSelected, ack, onChange]);

  // Nothing to reconcile — no prior shift, empty gap, still loading, or a soft
  // fetch error. Render nothing so the open flow is never blocked.
  if (!gap.data || !gap.data.previous_session || payments.length === 0) {
    return null;
  }

  function toggle(id: string) {
    setSelected((prev) => ({ ...prev, [id]: !prev[id] }));
  }

  const ackRequiredUnmet = cashSelected && !ack;

  return (
    <Card className="mb-4 gap-0 border-amber-200 p-0 dark:border-amber-700/40">
      <CardHeader className="flex flex-row items-center justify-between border-b border-amber-200/70 bg-amber-50/60 px-5 py-4 dark:border-amber-700/30 dark:bg-amber-950/20">
        <CardTitle className="text-[15px] font-semibold text-amber-900 dark:text-amber-100">
          {t("shift.open.gap_reconcile.section")}
        </CardTitle>
        <div className="flex items-center gap-1">
          <Badge
            variant="secondary"
            className="text-[11px] font-medium tabular-nums"
          >
            {t("shift.open.gap_reconcile.count_badge", {
              count: String(payments.length),
            })}
          </Badge>
          {/* Ticking a cash row here moves real money between two shifts'
              books. Explain that where the tick is made. */}
          <HelpButton topic="gap-reconcile" className="size-7" />
        </div>
      </CardHeader>
      <CardContent className="px-5 py-5">
        <p className="mb-4 text-[13px] leading-relaxed text-muted-foreground">
          {t("shift.open.gap_reconcile.description")}
        </p>

        <div className="overflow-hidden rounded-md border">
          {payments.map((p, i) => {
            const checked = !!selected[p.id];
            return (
              <label
                key={p.id}
                htmlFor={`gap-${p.id}`}
                className={cn(
                  "flex cursor-pointer items-center gap-3 px-3.5 py-3 text-[14px]",
                  i > 0 && "border-t",
                  checked && "bg-primary/5",
                )}
              >
                <Checkbox
                  id={`gap-${p.id}`}
                  checked={checked}
                  onCheckedChange={() => toggle(p.id)}
                />
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                  <div className="flex items-center gap-2">
                    <span className="truncate font-medium tabular-nums">
                      {p.order_code || p.order_id || "—"}
                    </span>
                    {p.is_cash ? (
                      <Badge
                        variant="outline"
                        className="gap-1 border-amber-300 text-[10px] font-medium text-amber-700 dark:border-amber-600/50 dark:text-amber-300"
                      >
                        <CoinsIcon className="size-3" />
                        {t("shift.open.gap_reconcile.cash_held_badge")}
                      </Badge>
                    ) : (
                      <Badge
                        variant="secondary"
                        className="text-[10px] font-medium uppercase"
                      >
                        {p.method_label || p.method_code}
                      </Badge>
                    )}
                  </div>
                  <span className="text-[11px] tabular-nums text-muted-foreground">
                    {formatDateTime(p.created_at)}
                  </span>
                </div>
                <div className="text-right font-semibold tabular-nums">
                  {formatMoney(p.amount, cur)}
                </div>
              </label>
            );
          })}
        </div>

        {cashSelected && (
          <Alert
            variant="default"
            className={cn(
              "mt-4 border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-700/40 dark:bg-amber-950/30 dark:text-amber-100",
              ackRequiredUnmet && "ring-1 ring-amber-400/60",
            )}
          >
            <InfoIcon className="size-4 text-amber-600" />
            <AlertTitle className="text-[13px] font-semibold">
              {t("shift.open.gap_reconcile.cash_callout_title")}
            </AlertTitle>
            <AlertDescription className="text-[12px] leading-relaxed">
              {t("shift.open.gap_reconcile.cash_callout_body")}
            </AlertDescription>
            <label
              htmlFor="gap-cash-ack"
              className="col-start-2 mt-3 flex cursor-pointer items-start gap-2.5 text-[13px] font-medium"
            >
              <Checkbox
                id="gap-cash-ack"
                checked={ack}
                onCheckedChange={(v) => setAck(v === true)}
                className="mt-0.5"
              />
              <span>{t("shift.open.gap_reconcile.cash_ack_label")}</span>
            </label>
          </Alert>
        )}
      </CardContent>
    </Card>
  );
}

function formatDateTime(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}/${pad(d.getMonth() + 1)}/${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
