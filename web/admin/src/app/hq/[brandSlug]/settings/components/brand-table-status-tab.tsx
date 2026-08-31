"use client";

/**
 * #491 — Brand-level default "table status after payment".
 *
 * Configures `brand_order_policies.default_table_status_after_payment` — the
 * status a table auto-returns to after a paid order closes, for every shop in
 * the brand that hasn't set its own override.
 *
 * - `free`     — table is immediately ready for the next guests.
 * - `cleaning` — table needs staff to clean it first; staff press "Dọn xong"
 *                to flip it back to `free`.
 *
 * Default (system-wide, when nothing is set): `free`.
 */

import { useEffect, useState } from "react";
import { useQueryClient, useQuery, useMutation } from "@tanstack/react-query";
import { toast } from "sonner";

import { apiFetch, ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";

import {
  Button,
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
  CardContent,
  Label,
  RadioGroup,
  RadioGroupItem,
  Spinner,
  Switch,
} from "@godxjp/ui";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

type TableStatusAfterPayment = "free" | "cleaning";

interface BrandSettingsData {
  default_table_status_after_payment: TableStatusAfterPayment;
  /** #890 — HQ switch: may shops edit tables received from the HQ default layout? */
  allow_shop_edit_hq_tables: boolean;
}

interface BrandSettingsResponse {
  data: BrandSettingsData;
}

// ---------------------------------------------------------------------------
// Query keys
// ---------------------------------------------------------------------------

const brandTableStatusKeys = {
  get: (brandSlug: string) => ["hq", brandSlug, "settings", "brand"] as const,
};

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export interface BrandTableStatusTabProps {
  brandSlug: string;
}

export function BrandTableStatusTab({ brandSlug }: BrandTableStatusTabProps) {
  const { t } = useTranslation();
  const qc = useQueryClient();

  const { data, isLoading, error } = useQuery({
    queryKey: brandTableStatusKeys.get(brandSlug),
    queryFn: () => apiFetch<BrandSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`),
    staleTime: 60 * 1000,
    retry: false,
  });

  const settings = data?.data;

  const [status, setStatus] = useState<TableStatusAfterPayment>("free");

  useEffect(() => {
    if (settings !== undefined) {
      setStatus(settings.default_table_status_after_payment ?? "free");
    }
  }, [settings]);

  const saveMutation = useMutation({
    mutationFn: (body: { default_table_status_after_payment: TableStatusAfterPayment }) =>
      apiFetch<BrandSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`, {
        method: "PATCH",
        body: JSON.stringify(body),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: brandTableStatusKeys.get(brandSlug) });
      toast.success(t("hq.brand.settings.table_status.toast_saved"));
    },
    onError: (err) => {
      toast.error(
        err instanceof ApiError && err.body
          ? String((err.body as { message?: string }).message ?? err.message)
          : err instanceof Error
            ? err.message
            : t("hq.brand.settings.table_status.toast_error")
      );
    },
  });

  if (error) {
    throw error;
  }

  const isDirty =
    settings !== undefined && status !== (settings.default_table_status_after_payment ?? "free");

  // #890 — HQ switch "shop may edit HQ-origin tables". Saves immediately on
  // toggle (no separate Save button — it is a single boolean).
  const allowEditMutation = useMutation({
    mutationFn: (allow: boolean) =>
      apiFetch<BrandSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`, {
        method: "PATCH",
        body: JSON.stringify({ allow_shop_edit_hq_tables: allow }),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: brandTableStatusKeys.get(brandSlug) });
      toast.success(t("hq.brand.settings.hq_tables.toast_saved"));
    },
    onError: (err) => {
      toast.error(err instanceof Error ? err.message : t("hq.brand.settings.hq_tables.toast_error"));
    },
  });

  return (
    <div data-slot="brand-table-status-tab" className="max-w-xl space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("hq.brand.settings.table_status.section_title")}
          </CardTitle>
          <CardDescription>{t("hq.brand.settings.table_status.description")}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-4">
          {isLoading ? (
            <div className="flex items-center gap-2 py-8 text-sm text-muted-foreground">
              <Spinner className="size-3.5" />
              {t("common.loading")}
            </div>
          ) : (
            <RadioGroup
              value={status}
              onValueChange={(v) => setStatus(v as TableStatusAfterPayment)}
              className="space-y-2"
            >
              <div className="flex items-start gap-3">
                <RadioGroupItem
                  value="free"
                  id="brand-table-status-free"
                  className="mt-0.5 shrink-0"
                />
                <Label
                  htmlFor="brand-table-status-free"
                  className="flex-1 cursor-pointer leading-snug"
                >
                  {t("hq.brand.settings.table_status.free_radio")}
                </Label>
              </div>
              <div className="flex items-start gap-3">
                <RadioGroupItem
                  value="cleaning"
                  id="brand-table-status-cleaning"
                  className="mt-0.5 shrink-0"
                />
                <Label
                  htmlFor="brand-table-status-cleaning"
                  className="flex-1 cursor-pointer leading-snug"
                >
                  {t("hq.brand.settings.table_status.cleaning_radio")}
                </Label>
              </div>
            </RadioGroup>
          )}

          <div className="flex items-center gap-2 border-t pt-4">
            <Button
              onClick={() => saveMutation.mutate({ default_table_status_after_payment: status })}
              disabled={saveMutation.isPending || isLoading || !isDirty}
              className="gap-2"
            >
              {saveMutation.isPending && <Spinner className="size-3.5" />}
              {t("common.save")}
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* #890 — shop edit rights over HQ-origin tables */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("hq.brand.settings.hq_tables.section_title")}
          </CardTitle>
          <CardDescription>{t("hq.brand.settings.hq_tables.description")}</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex items-center justify-between gap-4">
            <Label htmlFor="allow-shop-edit-hq-tables" className="flex-1 cursor-pointer leading-snug">
              {t("hq.brand.settings.hq_tables.allow_edit_label")}
            </Label>
            <div className="flex items-center gap-2">
              {allowEditMutation.isPending && <Spinner className="size-3.5" />}
              <Switch
                id="allow-shop-edit-hq-tables"
                checked={settings?.allow_shop_edit_hq_tables ?? false}
                disabled={isLoading || allowEditMutation.isPending}
                onCheckedChange={(checked) => allowEditMutation.mutate(checked)}
              />
            </div>
          </div>
          <p className="mt-2 text-xs text-muted-foreground">
            {t("hq.brand.settings.hq_tables.allow_edit_hint")}
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
