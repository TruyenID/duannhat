"use client";

import { useEffect, useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from "@godxjp/ui";
import { CoinsIcon, PencilIcon, PlusIcon, Trash2Icon } from "lucide-react";
import {
  useCreateDenomination,
  useDeleteDenomination,
  useDenominations,
  useUpdateDenomination,
} from "@/hooks/api/use-denominations";
import type { Denomination } from "@/services/denomination-service";

// Must match the shop currency picker, which is served by the backend
// (ShopOrderSettingsController::availableCurrencies). A code offered there but
// missing here cannot have its denominations configured at all.
type CurrencyCode = "JPY" | "VND" | "USD" | "EUR" | "KRW" | "CNY" | "THB" | "IDR";
const ALL_CURRENCIES: CurrencyCode[] = [
  "JPY",
  "VND",
  "USD",
  "EUR",
  "KRW",
  "CNY",
  "THB",
  "IDR",
];

export interface DenominationsTabProps {
  shopSlug: string;
}

/**
 * Per-shop denominations admin. Reads global (read-only) + org-scoped rows
 * for the shop's organization. The active currency tab defaults to the
 * shop's configured currency (Order tab → Currency); admin can flip to
 * other currencies to seed alternates.
 */
export function DenominationsTab({ shopSlug }: DenominationsTabProps) {
  const { t } = useTranslation();

  // Initial currency = shop's configured one. Re-read every render but
  // don't auto-flip the tab after the user picks a different one.
  const settingsQuery = useQuery({
    queryKey: ["shop-order-settings", shopSlug],
    queryFn: () =>
      apiFetch<{ data: { currency_code: string } }>(`/api/v1/shops/${shopSlug}/settings/order`),
    enabled: !!shopSlug,
  });
  const shopCurrency = (settingsQuery.data?.data.currency_code as CurrencyCode) ?? "JPY";

  const [currency, setCurrency] = useState<CurrencyCode | null>(null);
  const activeCurrency = currency ?? shopCurrency;

  const list = useDenominations(shopSlug, activeCurrency);
  const rows: Denomination[] = list.data?.data ?? [];

  const notes = useMemo(() => rows.filter((r) => r.kind === "note"), [rows]);
  const coins = useMemo(() => rows.filter((r) => r.kind === "coin"), [rows]);

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<Denomination | null>(null);

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t("settings.denomination.title")}</CardTitle>
          <CardDescription>{t("settings.denomination.description")}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-4">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="denom-currency" className="text-sm">
                {t("settings.denomination.currency_label")}
              </Label>
              <Select value={activeCurrency} onValueChange={(v) => setCurrency(v as CurrencyCode)}>
                <SelectTrigger id="denom-currency" className="w-44">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {ALL_CURRENCIES.map((c) => (
                    <SelectItem key={c} value={c}>
                      {c}
                      {c === shopCurrency && (
                        <span className="ml-2 text-xs text-muted-foreground">
                          {t("settings.denomination.shop_default_suffix")}
                        </span>
                      )}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <Button
              type="button"
              onClick={() => {
                setEditing(null);
                setDialogOpen(true);
              }}
            >
              <PlusIcon className="mr-1.5 size-4" />
              {t("settings.denomination.add")}
            </Button>
          </div>

          {list.isLoading ? (
            <div className="flex items-center justify-center py-10">
              <Spinner className="size-5" />
            </div>
          ) : rows.length === 0 ? (
            <div className="rounded-md border border-dashed py-10 text-center text-sm text-muted-foreground">
              {t("settings.denomination.empty")}
            </div>
          ) : (
            <>
              {notes.length > 0 && (
                <DenomGroup
                  title={t("settings.denomination.notes")}
                  rows={notes}
                  currency={activeCurrency}
                  shopSlug={shopSlug}
                  onEdit={(d) => {
                    setEditing(d);
                    setDialogOpen(true);
                  }}
                />
              )}
              {coins.length > 0 && (
                <DenomGroup
                  title={t("settings.denomination.coins")}
                  rows={coins}
                  currency={activeCurrency}
                  shopSlug={shopSlug}
                  onEdit={(d) => {
                    setEditing(d);
                    setDialogOpen(true);
                  }}
                />
              )}
            </>
          )}
        </CardContent>
      </Card>

      <DenominationDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        shopSlug={shopSlug}
        currency={activeCurrency}
        editing={editing}
      />
    </div>
  );
}

function DenomGroup({
  title,
  rows,
  currency,
  shopSlug,
  onEdit,
}: {
  title: string;
  rows: Denomination[];
  currency: CurrencyCode;
  shopSlug: string;
  onEdit: (d: Denomination) => void;
}) {
  const { t } = useTranslation();
  const remove = useDeleteDenomination(shopSlug);

  return (
    <div className="overflow-hidden rounded-md border">
      <div className="border-b bg-muted/40 px-3 py-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
        {title}
      </div>
      <table className="w-full text-sm">
        <thead className="bg-muted/20 text-[11px] tracking-wide text-muted-foreground uppercase">
          <tr>
            <th className="px-3 py-2 text-left font-medium">
              {t("settings.denomination.col_value")}
            </th>
            <th className="px-3 py-2 text-left font-medium">
              {t("settings.denomination.col_label")}
            </th>
            <th className="px-3 py-2 text-left font-medium">
              {t("settings.denomination.col_source")}
            </th>
            <th className="px-3 py-2 text-right font-medium">
              {t("settings.denomination.col_actions")}
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => {
            const isGlobal = r.organization_id === null;
            return (
              <tr key={r.id} className="border-t">
                <td className="px-3 py-2.5 font-medium tabular-nums">
                  <span className="inline-flex items-center gap-1">
                    <CoinsIcon className="size-3.5 text-muted-foreground" />
                    {Number(r.value).toLocaleString()} {currency}
                  </span>
                </td>
                <td className="px-3 py-2.5 text-muted-foreground">{r.label ?? "—"}</td>
                <td className="px-3 py-2.5">
                  {isGlobal ? (
                    <Badge variant="secondary">{t("settings.denomination.source_global")}</Badge>
                  ) : (
                    <Badge>{t("settings.denomination.source_custom")}</Badge>
                  )}
                </td>
                <td className="px-3 py-2.5 text-right">
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="size-8 px-0"
                    disabled={isGlobal}
                    title={
                      isGlobal
                        ? t("settings.denomination.global_readonly_tip")
                        : t("settings.denomination.edit")
                    }
                    onClick={() => onEdit(r)}
                  >
                    <PencilIcon className="size-4" />
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="size-8 px-0 text-destructive hover:text-destructive"
                    disabled={isGlobal || remove.isPending}
                    title={
                      isGlobal
                        ? t("settings.denomination.global_readonly_tip")
                        : t("settings.denomination.delete")
                    }
                    onClick={() => {
                      if (window.confirm(t("settings.denomination.delete_confirm"))) {
                        remove.mutate(r.id);
                      }
                    }}
                  >
                    <Trash2Icon className="size-4" />
                  </Button>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function DenominationDialog({
  open,
  onOpenChange,
  shopSlug,
  currency,
  editing,
}: {
  open: boolean;
  onOpenChange: (v: boolean) => void;
  shopSlug: string;
  currency: CurrencyCode;
  editing: Denomination | null;
}) {
  const { t } = useTranslation();
  const create = useCreateDenomination(shopSlug);
  const update = useUpdateDenomination(shopSlug);

  const [value, setValue] = useState<string>(editing ? String(editing.value) : "");
  const [kind, setKind] = useState<"note" | "coin">(editing?.kind ?? "note");
  const [label, setLabel] = useState<string>(editing?.label ?? "");

  // Reset form when dialog opens or edit-target changes.
  useEffect(() => {
    setValue(editing ? String(editing.value) : "");
    setKind(editing?.kind ?? "note");
    setLabel(editing?.label ?? "");
  }, [editing, open]);

  const valid = Number(value) > 0;
  const isEdit = !!editing;
  const submitting = create.isPending || update.isPending;

  async function onSubmit() {
    const num = Number(value);
    if (!Number.isFinite(num) || num <= 0) return;
    try {
      if (isEdit && editing) {
        await update.mutateAsync({
          id: editing.id,
          data: {
            value: num,
            kind,
            label: label.trim() || null,
          },
        });
      } else {
        await create.mutateAsync({
          currency_code: currency,
          value: num,
          kind,
          label: label.trim() || null,
        });
      }
      onOpenChange(false);
    } catch {
      /* toast handled by hook */
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>
            {isEdit
              ? t("settings.denomination.dialog.title_edit")
              : t("settings.denomination.dialog.title_create")}
          </DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div className="space-y-1.5">
            <Label className="text-sm">{t("settings.denomination.currency_label")}</Label>
            <Input value={currency} disabled />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="denom-value" className="text-sm">
              {t("settings.denomination.value_label")}
            </Label>
            <Input
              id="denom-value"
              type="number"
              inputMode="decimal"
              min="0.0001"
              step="any"
              value={value}
              onChange={(e) => setValue(e.target.value)}
              placeholder="10000"
              autoFocus
            />
            <p className="text-xs text-muted-foreground">{t("settings.denomination.value_hint")}</p>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="denom-kind" className="text-sm">
              {t("settings.denomination.kind_label")}
            </Label>
            <Select value={kind} onValueChange={(v) => setKind(v as "note" | "coin")}>
              <SelectTrigger id="denom-kind">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="note">{t("settings.denomination.kind_note")}</SelectItem>
                <SelectItem value="coin">{t("settings.denomination.kind_coin")}</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="denom-label" className="text-sm">
              {t("settings.denomination.label_label")}
            </Label>
            <Input
              id="denom-label"
              value={label}
              onChange={(e) => setLabel(e.target.value)}
              placeholder={t("settings.denomination.label_placeholder")}
            />
          </div>
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={submitting}
          >
            {t("common.cancel")}
          </Button>
          <Button type="button" onClick={onSubmit} disabled={!valid || submitting}>
            {submitting && <Spinner className="mr-2 size-3.5" />}
            {isEdit ? t("common.save") : t("common.add")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
