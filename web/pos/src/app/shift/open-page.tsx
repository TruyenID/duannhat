/**
 * Shift Open page — /shop/:shopSlug/shift/open.
 *
 * Plan 030 — visual + structural port of pos-web/shift-open.html prototype,
 * rebuilt with @godxjp/ui primitives + design tokens so it stays in sync
 * with the rest of the POS surface (payment-dialog, void-order-dialog, etc.).
 *
 * All UI strings flow through useTranslation() / i18n keys under shift.open.*.
 */

import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Input,
  Label,
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
  Textarea,
} from "@godxjp/ui";
import {
  AlertCircleIcon,
  MinusIcon,
  PlusIcon,
  PrinterIcon,
  SettingsIcon,
} from "lucide-react";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { setCurrentShopSlug } from "@/lib/shop-context";
import { useTranslation } from "@/providers/app-provider";
import { useNetworkRequired } from "@/hooks/use-network-required";
import { useAuth } from "@/providers/use-auth";
import {
  useDenominations,
  useOpenShift,
  useTillCurrent,
} from "@/hooks/api/use-till";
import { useShopStaff } from "@/hooks/api/use-staff";
import { useShopOrderSettings } from "@/hooks/api/use-shop-order-settings";
import type { Denomination, OpenShiftPayload } from "@/services/till-service";
import { workstationPrintService } from "@/services/workstation-print-service";
import { ApiError } from "@/lib/api";
import { PosHeader } from "@/app/pos/components/pos-header";
import { GapReconcilePanel } from "./gap-reconcile-panel";
import { UnresolvedOrdersPanel } from "./unresolved-orders-panel";
import { EMPTY_GAP_CLAIM, type GapClaimState } from "./gap-claim";
import {
  CURRENCIES,
  type CurrencyCode,
  type CurrencyConfig,
  denomLabel,
  formatMoney,
} from "./currency";

function nowFormatted(locale: string): string {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, "0");
  // Locale-neutral ISO-ish for the timestamp display.
  void locale;
  return `${d.getFullYear()}/${pad(d.getMonth() + 1)}/${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

export function ShiftOpenPage() {
  const { shopSlug = "" } = useParams<{ shopSlug: string }>();
  const navigate = useNavigate();
  const { device } = useAuth();
  const { t, locale } = useTranslation();
  const network = useNetworkRequired();
  useEffect(() => {
    setCurrentShopSlug(shopSlug);
  }, [shopSlug]);

  const current = useTillCurrent(shopSlug);
  const staff = useShopStaff(shopSlug);
  const shopSettings = useShopOrderSettings(shopSlug);

  // Single source of truth — shop_order_settings.currency_code (flipped in
  // Settings → Order page). Fall through till.default_currency_code (legacy
  // branches that pre-date the settings row) → JPY. No local dropdown here:
  // letting cashiers pick at open time is exactly what causes per-shift
  // drift vs the Settings/POS/Revenue surfaces.
  const settingsCurrency = shopSettings.data?.data.currency_code;
  const tillCurrency = current.data?.till.default_currency_code;
  const resolvedCurrency = (settingsCurrency ?? tillCurrency ?? "JPY") as string;
  const currency: CurrencyCode =
    resolvedCurrency in CURRENCIES ? (resolvedCurrency as CurrencyCode) : "JPY";
  const denominations = useDenominations(shopSlug, currency);

  useEffect(() => {
    // Only act on a FRESH result — never redirect to POS on a stale cached
    // open_session mid-refetch. After a handover/close the till's session is
    // cleared server-side, but useTillCurrent (staleTime 15s) briefly serves the
    // last-cached session while it refetches; acting on that stale value would
    // bounce the cashier BACK into POS instead of leaving them on the open screen.
    // Mirrors ShiftGate's `if (!current.isFetched) return` guard (+ isFetching so
    // we wait out the always-on-mount refetch).
    if (current.isFetching || !current.isFetched) return;
    if (current.data?.open_session) {
      toast.info(t("shift.open.error.already_open"));
      navigate(`/shop/${shopSlug}`, { replace: true });
    }
  }, [
    current.data,
    current.isFetching,
    current.isFetched,
    navigate,
    shopSlug,
    t,
  ]);

  const [counts, setCounts] = useState<Record<string, number>>({});
  const [openerSelection, setOpenerSelection] = useState<string>("__me");
  const [openerCustomName, setOpenerCustomName] = useState("");
  const [openingNote, setOpeningNote] = useState("");
  const [openedAt] = useState(() => nowFormatted(locale));
  // plan-044 R2 — gap-claim selection lifted from GapReconcilePanel so submit can
  // gate on the held-separately ack + fold the claimed ids into the open payload.
  const [gapClaim, setGapClaim] = useState<GapClaimState>(EMPTY_GAP_CLAIM);
  const gapAckOk = !gapClaim.cashSelected || gapClaim.ack;

  useEffect(() => {
    setCounts({});
  }, [currency]);

  const cur = CURRENCIES[currency];
  const list = useMemo(() => denominations.data ?? [], [denominations.data]);
  const paperDenoms = useMemo(
    () => list.filter((d) => d.kind === "note"),
    [list],
  );
  const coinDenoms = useMemo(
    () => list.filter((d) => d.kind === "coin"),
    [list],
  );

  const totals = useMemo(() => {
    let total = 0;
    let totalCount = 0;
    let paperCount = 0;
    let coinCount = 0;
    for (const d of list) {
      const q = counts[d.id] ?? 0;
      total += d.value * q;
      totalCount += q;
      if (d.kind === "note") paperCount += q;
      else coinCount += q;
    }
    if (cur.decimals > 0) {
      const f = Math.pow(10, cur.decimals);
      total = Math.round(total * f) / f;
    }
    return { total, totalCount, paperCount, coinCount };
  }, [list, counts, cur.decimals]);

  const openMutation = useOpenShift(shopSlug);

  const canSubmit =
    !openMutation.isPending &&
    Object.values(counts).some((q) => q > 0) &&
    (openerSelection !== "__other" || openerCustomName.trim().length > 0) &&
    gapAckOk;

  function setQty(id: string, n: number) {
    const q = Number.isFinite(n) ? Math.max(0, Math.floor(n)) : 0;
    setCounts((prev) => ({ ...prev, [id]: q }));
  }

  async function handleSubmit() {
    let openerPayload: { opened_by_id?: string; opener_name?: string } = {};
    if (openerSelection === "__other") {
      openerPayload = { opener_name: openerCustomName.trim() };
    } else if (openerSelection !== "__me") {
      openerPayload = { opened_by_id: openerSelection };
    }

    const payload: OpenShiftPayload = {
      currency_code: currency,
      opening_counts: Object.entries(counts)
        .filter(([, q]) => q > 0)
        .map(([denomination_id, quantity]) => ({ denomination_id, quantity })),
      opening_note: openingNote.trim() || null,
      ...openerPayload,
      // plan-044 R2 — only send the gap-claim keys when the cashier confirmed at
      // least one gap payment; the ack rides along so the backend/workstation can
      // audit that any claimed cash was held separately.
      ...(gapClaim.claimedIds.length > 0
        ? {
            claimed_gap_payment_ids: gapClaim.claimedIds,
            gap_cash_held_separately_ack: gapClaim.ack,
          }
        : {}),
    };

    try {
      const res = await openMutation.mutateAsync(payload);
      toast.success(t("shift.open.success"));
      // Plan-046 — this open continued a handover chain: surface the chain
      // position (blind re-count is already enforced by required opening_counts).
      if (res.data.continues_chain) {
        toast.info(
          t("shift.open.chain.banner", {
            seq: String(res.data.chain_sequence ?? 1),
          }),
        );
      }
      try {
        await workstationPrintService.printShiftOpenReport({
          shopSlug,
          sessionId: res.data.id,
          deviceName: device?.name,
        });
      } catch {
        /* best-effort print */
      }
      navigate(`/shop/${shopSlug}`, { replace: true });
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        toast.error(
          err.body?.code === "SHIFT_ALREADY_OPEN"
            ? t("shift.open.error.already_open")
            : (err.message ?? t("shift.open.error.failed")),
        );
        navigate(`/shop/${shopSlug}`, { replace: true });
        return;
      }
      toast.error(
        err instanceof Error ? err.message : t("shift.open.error.failed"),
      );
    }
  }

  const shopName = device?.branch_name ?? "—";
  const deviceName = device?.name ?? "POS";

  return (
    <div className="min-h-screen bg-muted/30 text-foreground">
      <PosHeader
        shopName={shopName}
        breadcrumb={{
          parent: t("shift.breadcrumb.parent"),
          current: t("shift.open.title"),
        }}
        helpTopic="shift-open"
      />
      <main className="mx-auto max-w-3xl px-4 py-6 sm:px-6 sm:py-8">
        {/* Page header */}
        <header className="mb-5">
          <h1 className="text-[22px] font-semibold leading-tight">
            {t("shift.open.title")}
          </h1>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t("shift.open.subtitle")}
          </p>
        </header>

        {/* Alert */}
        {!current.data?.open_session && current.isFetched && (
          <Alert variant="default" className="mb-4 border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-700/40 dark:bg-amber-950/30 dark:text-amber-100">
            <AlertCircleIcon className="size-4 text-amber-600" />
            <AlertDescription>
              {t("shift.open.alert_no_shift")
                .split("{device}")
                .flatMap((part, i) =>
                  i === 0
                    ? [part]
                    : [
                        <strong key={`device-${i}`}>{deviceName}</strong>,
                        part,
                      ],
                )}
            </AlertDescription>
          </Alert>
        )}

        {/* Card: Thông tin ca */}
        <Card className="mb-4 gap-0 p-0">
          <CardHeader className="flex flex-row items-center justify-between border-b px-5 py-4">
            <CardTitle className="text-[15px] font-semibold">
              {t("shift.open.info.section")}
            </CardTitle>
            <Badge variant="secondary" className="text-[11px] font-medium">
              {t("shift.open.info.badge_auto")}
            </Badge>
          </CardHeader>
          <CardContent className="px-5 py-5">
            <InfoRow
              label={t("shift.open.info.shop")}
              value={shopName}
            />
            <InfoRow
              label={t("shift.open.info.device")}
              value={deviceName}
            />
            <InfoRowEditable label={t("shift.open.info.opener")}>
              <Select
                value={openerSelection}
                onValueChange={setOpenerSelection}
                disabled={staff.isLoading}
              >
                <SelectTrigger className="h-10 w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="__me">
                    {t("shift.open.opener.me")}
                  </SelectItem>
                  {staff.data && staff.data.length > 0 && (
                    <SelectGroup>
                      <SelectLabel>
                        {t("shift.open.opener.staff_group")}
                      </SelectLabel>
                      {staff.data.map((s) => (
                        <SelectItem key={s.id} value={s.id}>
                          {s.name}
                          {s.email ? ` ・ ${s.email}` : ""}
                        </SelectItem>
                      ))}
                    </SelectGroup>
                  )}
                  <SelectItem value="__other">
                    {t("shift.open.opener.other")}
                  </SelectItem>
                </SelectContent>
              </Select>
              {staff.isError && (
                <p className="text-[11px] text-amber-700 dark:text-amber-400">
                  {t("shift.open.opener.staff_load_error")}
                </p>
              )}
              {openerSelection === "__other" && (
                <Input
                  placeholder={t("shift.open.opener.other_placeholder")}
                  value={openerCustomName}
                  onChange={(e) => setOpenerCustomName(e.target.value)}
                  maxLength={255}
                  className="h-10"
                />
              )}
            </InfoRowEditable>
            <InfoRow
              label={t("shift.open.info.opened_at")}
              value={openedAt}
              last
            />
          </CardContent>
        </Card>

        {/* Gap reconciliation — payments taken during the previous close→open
            window that the cashier confirms belong to this shift (plan-044 R2). */}
        <GapReconcilePanel
          shopSlug={shopSlug}
          fallbackCurrency={currency}
          onChange={setGapClaim}
          t={t}
        />

        {/* #2696 — orders still paying/checkout that lived past the previous
            shift close. Separate from gap: gap is money already taken;
            this is a bill that may still be unpaid. */}
        <UnresolvedOrdersPanel
          shopSlug={shopSlug}
          fallbackCurrency={currency}
          t={t}
        />

        {/* Card: Đếm tiền mặt */}
        <Card className="gap-0 p-0">
          <CardHeader className="flex flex-row items-center justify-between gap-3 border-b px-5 py-4">
            <CardTitle className="text-[15px] font-semibold">
              {t("shift.open.count.section")}
            </CardTitle>
            <div className="flex items-center gap-2.5">
              <Label className="text-[12px] font-medium text-muted-foreground">
                {t("shift.open.count.currency_label")}
              </Label>
              <div
                className="inline-flex h-9 items-center gap-2 rounded-md border bg-muted/40 px-3 text-[13px] font-medium text-foreground"
                title={t("shift.open.count.currency_locked_hint")}
              >
                <SettingsIcon className="size-3.5 text-muted-foreground" />
                <span className="tabular-nums">{cur.code}</span>
                <span className="text-muted-foreground">・</span>
                <span className="truncate">{cur.display}</span>
              </div>
            </div>
          </CardHeader>
          <CardContent className="px-5 py-5">
            <div className="mb-3 rounded-md bg-muted/60 px-3 py-2.5 text-[12px] text-muted-foreground">
              {t("shift.open.count.hint", { currency: cur.code })}
            </div>

            <div className="overflow-hidden rounded-md border">
              <div className="grid grid-cols-[1.4fr_1.6fr_1fr] gap-3 border-b bg-muted/40 px-3.5 py-2.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                <div>
                  {t("shift.open.count.col_denom")}
                  <span className="ml-1 normal-case tracking-normal text-muted-foreground/70">
                    ({cur.code})
                  </span>
                </div>
                <div className="text-center">
                  {t("shift.open.count.col_qty")}
                </div>
                <div className="text-right">
                  {t("shift.open.count.col_subtotal")}
                </div>
              </div>

              {paperDenoms.length > 0 && (
                <DenomGroup
                  label={t("shift.open.count.group_notes")}
                  rows={paperDenoms}
                  cur={cur}
                  counts={counts}
                  setQty={setQty}
                  t={t}
                />
              )}
              {coinDenoms.length > 0 && (
                <DenomGroup
                  label={t("shift.open.count.group_coins")}
                  rows={coinDenoms}
                  cur={cur}
                  counts={counts}
                  setQty={setQty}
                  t={t}
                />
              )}
              {paperDenoms.length === 0 && coinDenoms.length === 0 && (
                <div className="px-3.5 py-6 text-center text-[13px] text-muted-foreground">
                  {denominations.isLoading
                    ? t("shift.open.count.empty_loading")
                    : t("shift.open.count.empty_none")}
                </div>
              )}
            </div>

            {/* Total bar */}
            <div className="mt-4 flex items-center justify-between gap-4 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3.5">
              <div className="min-w-0 flex flex-col gap-0.5">
                <div className="text-[13px] font-semibold text-primary">
                  {t("shift.open.total.label")}
                </div>
                <div className="text-[12px] tabular-nums text-muted-foreground">
                  <span>
                    {totals.totalCount.toLocaleString(cur.locale)}{" "}
                    {t("shift.open.total.unit_total")}
                  </span>{" "}
                  ・{" "}
                  <span>
                    {totals.paperCount.toLocaleString(cur.locale)}{" "}
                    {t("shift.open.total.unit_paper")}
                  </span>{" "}
                  ・{" "}
                  <span>
                    {totals.coinCount.toLocaleString(cur.locale)}{" "}
                    {t("shift.open.total.unit_coin")}
                  </span>
                </div>
              </div>
              <div className="text-[26px] font-bold tabular-nums tracking-tight text-primary">
                {formatMoney(totals.total, cur)}
              </div>
            </div>

            {/* Note */}
            <div className="mt-5">
              <Label
                htmlFor="opening-note"
                className="mb-1.5 block text-[13px] font-medium"
              >
                {t("shift.open.note.label")}{" "}
                <span className="font-normal text-muted-foreground">
                  {t("shift.open.note.optional")}
                </span>
              </Label>
              <Textarea
                id="opening-note"
                value={openingNote}
                onChange={(e) => setOpeningNote(e.target.value)}
                placeholder={t("shift.open.note.placeholder")}
                rows={2}
                maxLength={2000}
                className="min-h-[64px] text-[14px]"
              />
            </div>
          </CardContent>
        </Card>

        {/* Footer */}
        <div className="mt-6 flex justify-end gap-2">
          <Button
            variant="outline"
            onClick={() => navigate(`/shop/${shopSlug}`)}
            className="h-11"
          >
            {t("shift.open.action.cancel")}
          </Button>
          <Button
            disabled={!canSubmit}
            /* #1501 — mở ca ghi một TillSession trên Cloud; không có mạng thì
               không có ca, và một ca "mở offline" sẽ là ca ma. */
            {...network.blockedProps}
            onClick={handleSubmit}
            className="h-11 gap-1.5"
          >
            <PrinterIcon className="size-4" />
            {openMutation.isPending
              ? t("shift.open.action.submitting")
              : t("shift.open.action.submit")}
          </Button>
        </div>
      </main>
    </div>
  );
}

function InfoRow({
  label,
  value,
  last,
}: {
  label: string;
  value: string;
  last?: boolean;
}) {
  return (
    <div
      className={cn(
        "flex items-center justify-between py-2.5",
        !last && "border-b border-dashed border-border",
      )}
    >
      <span className="text-[13px] text-muted-foreground">{label}</span>
      <span className="text-[14px] font-medium text-foreground">{value}</span>
    </div>
  );
}

function InfoRowEditable({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex items-start justify-between gap-3 border-b border-dashed border-border py-2.5">
      <span className="mt-2 text-[13px] text-muted-foreground">{label}</span>
      <div className="flex w-full max-w-[60%] flex-col gap-1.5 min-w-[260px]">
        {children}
      </div>
    </div>
  );
}

function DenomGroup({
  label,
  rows,
  cur,
  counts,
  setQty,
  t,
}: {
  label: React.ReactNode;
  rows: Denomination[];
  cur: CurrencyConfig;
  counts: Record<string, number>;
  setQty: (id: string, n: number) => void;
  t: (k: string, p?: Record<string, string>) => string;
}) {
  return (
    <>
      <div className="border-b bg-muted/40 px-3.5 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        {label}
      </div>
      {rows.map((d) => {
        const q = counts[d.id] ?? 0;
        const sub = d.value * q;
        const zero = q === 0;
        const dl = denomLabel(d, cur);
        return (
          <div
            key={d.id}
            className="grid grid-cols-[1.4fr_1.6fr_1fr] items-center gap-3 border-b px-3.5 py-2.5 last:border-b-0"
          >
            <div className="flex items-center gap-2">
              <span className="text-[15px] font-semibold tabular-nums">
                {dl}
              </span>
            </div>
            <div className="flex items-center justify-center gap-1.5">
              <Button
                type="button"
                variant="outline"
                size="icon"
                onClick={() => setQty(d.id, q - 1)}
                disabled={q <= 0}
                aria-label={t("shift.open.count.dec")}
                className="h-8 w-8"
              >
                <MinusIcon className="size-4" />
              </Button>
              <Input
                type="text"
                inputMode="numeric"
                value={String(q)}
                onChange={(e) => {
                  const raw = e.target.value.replace(/[^\d]/g, "");
                  setQty(d.id, raw === "" ? 0 : parseInt(raw, 10));
                }}
                aria-label={t("shift.open.count.qty_label", { denom: dl })}
                className="h-8 w-16 text-center text-[14px] font-semibold tabular-nums"
              />
              <Button
                type="button"
                variant="outline"
                size="icon"
                onClick={() => setQty(d.id, q + 1)}
                aria-label={t("shift.open.count.inc")}
                className="h-8 w-8"
              >
                <PlusIcon className="size-4" />
              </Button>
            </div>
            <div
              className={cn(
                "text-right tabular-nums",
                zero
                  ? "font-normal text-muted-foreground"
                  : "font-medium text-foreground",
              )}
            >
              {formatMoney(sub, cur)}
            </div>
          </div>
        );
      })}
    </>
  );
}
