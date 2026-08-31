"use client";

/**
 * plan-031: Brand-level takeaway payment timeout setting.
 *
 * Configures `brands.takeaway_payment_timeout_minutes` — the countdown
 * duration for unpaid takeaway orders with counter payment before they
 * are automatically cancelled.
 *
 * Default: 15 minutes (per migration).
 * Range: 5-120 minutes (per UI validation).
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
  Input,
  Label,
  RadioGroup,
  RadioGroupItem,
  Spinner,
} from "@godxjp/ui";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface BrandTakeawayPaymentSettingsData {
  takeaway_payment_timeout_minutes: number | null;
  // plan-037 — default confirmation step countdown (1–30 min).
  default_confirmation_timeout_minutes?: number | null;
  // #1160 — brand-default prep minutes per item; null = no brand default, so
  // shops resolve to the system default (5).
  default_prep_minutes_per_item?: number | null;
}

interface BrandTakeawayPaymentSettingsResponse {
  data: BrandTakeawayPaymentSettingsData;
}

interface BrandTakeawayPaymentSettingsPatchBody {
  takeaway_payment_timeout_minutes?: number | null;
  default_confirmation_timeout_minutes?: number;
  default_prep_minutes_per_item?: number | null;
}

// ---------------------------------------------------------------------------
// Query keys
// ---------------------------------------------------------------------------

const brandTakeawayPaymentSettingsKeys = {
  get: (brandSlug: string) => ["hq", brandSlug, "settings", "brand", "takeaway-payment"] as const,
};

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export interface BrandTakeawayPaymentTabProps {
  brandSlug: string;
}

const MIN_MINUTES = 5;
const MAX_MINUTES = 120;
const DEFAULT_MINUTES = 15;

export function BrandTakeawayPaymentTab({ brandSlug }: BrandTakeawayPaymentTabProps) {
  const { t } = useTranslation();
  const qc = useQueryClient();

  const { data, isLoading, error } = useQuery({
    queryKey: brandTakeawayPaymentSettingsKeys.get(brandSlug),
    queryFn: () =>
      apiFetch<BrandTakeawayPaymentSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`),
    staleTime: 60 * 1000,
    retry: false,
  });

  const settings = data?.data;

  // "default" (use 15min) | "custom"
  const [mode, setMode] = useState<"default" | "custom">("default");
  const [minutesRaw, setMinutesRaw] = useState<string>(String(DEFAULT_MINUTES));

  // plan-037 — confirmation countdown (1–30 minutes). Default 3.
  const [confirmRaw, setConfirmRaw] = useState<string>("3");

  // #1160 — brand-default prep minutes per item (0–120). Empty string = HQ
  // sets no default, so shops fall back to the system default (5).
  const [prepMinutesRaw, setPrepMinutesRaw] = useState<string>("");

  useEffect(() => {
    if (settings !== undefined) {
      const mins = settings.takeaway_payment_timeout_minutes;
      if (mins !== null && mins >= MIN_MINUTES) {
        setMode("custom");
        setMinutesRaw(String(mins));
      } else {
        setMode("default");
        setMinutesRaw(String(DEFAULT_MINUTES));
      }
      const confirmMins = settings.default_confirmation_timeout_minutes ?? 3;
      setConfirmRaw(String(confirmMins));
      // null = HQ never set one → leave the field empty rather than showing 5
      // as if it were a saved brand default.
      setPrepMinutesRaw(
        settings.default_prep_minutes_per_item === null ||
          settings.default_prep_minutes_per_item === undefined
          ? ""
          : String(settings.default_prep_minutes_per_item)
      );
    }
  }, [settings]);

  const saveMutation = useMutation({
    mutationFn: (body: BrandTakeawayPaymentSettingsPatchBody) =>
      apiFetch<BrandTakeawayPaymentSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`, {
        method: "PATCH",
        body: JSON.stringify(body),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: brandTakeawayPaymentSettingsKeys.get(brandSlug) });
      toast.success(t("hq.brand.settings.takeaway_payment.toast_saved"));
    },
    onError: (err) => {
      toast.error(
        err instanceof ApiError && err.body
          ? String((err.body as { message?: string }).message ?? err.message)
          : err instanceof Error
            ? err.message
            : t("hq.brand.settings.takeaway_payment.toast_error")
      );
    },
  });

  if (error) {
    throw error;
  }

  const minutesNum = parseInt(minutesRaw, 10);
  const minutesError =
    Number.isNaN(minutesNum) || minutesNum < MIN_MINUTES || minutesNum > MAX_MINUTES
      ? t("hq.brand.settings.takeaway_payment.range_error", {
          min: String(MIN_MINUTES),
          max: String(MAX_MINUTES),
        })
      : "";

  const confirmNum = parseInt(confirmRaw, 10);
  const confirmError =
    Number.isNaN(confirmNum) || confirmNum < 1 || confirmNum > 30
      ? t("settings.order.confirmation_timeout_range_error", { min: "1", max: "30" })
      : "";

  // #1160 — empty means "no brand default" (a legitimate save, sent as null);
  // anything else must land in 0–120. 0 is legitimate too, so the guard is a
  // range check rather than a truthiness one.
  const prepMinutesTrimmed = prepMinutesRaw.trim();
  const prepMinutesNum = prepMinutesTrimmed === "" ? null : parseInt(prepMinutesTrimmed, 10);
  const prepMinutesError =
    prepMinutesNum !== null &&
    (Number.isNaN(prepMinutesNum) || prepMinutesNum < 0 || prepMinutesNum > 120)
      ? t("settings.order.prep_minutes_range_error", { min: "0", max: "120" })
      : "";

  const handleSave = () => {
    if (minutesError || confirmError || prepMinutesError) return;
    const payload: BrandTakeawayPaymentSettingsPatchBody = {
      takeaway_payment_timeout_minutes: mode === "default" ? DEFAULT_MINUTES : minutesNum,
      default_confirmation_timeout_minutes: confirmNum,
      default_prep_minutes_per_item: prepMinutesNum,
    };
    saveMutation.mutate(payload);
  };

  const canSave =
    !saveMutation.isPending &&
    !confirmError &&
    !prepMinutesError &&
    (mode === "default" || (mode === "custom" && !minutesError && minutesNum > 0));

  return (
    <div data-slot="brand-takeaway-payment-tab" className="max-w-xl space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("hq.brand.settings.takeaway_payment.section_title")}
          </CardTitle>
          <CardDescription>{t("hq.brand.settings.takeaway_payment.description")}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-4">
          {isLoading ? (
            <div className="flex items-center gap-2 py-8 text-sm text-muted-foreground">
              <Spinner className="size-3.5" />
              {t("common.loading")}
            </div>
          ) : (
            <>
              <RadioGroup value={mode} onValueChange={(v) => setMode(v as "default" | "custom")}>
                <div className="flex items-start gap-3">
                  <RadioGroupItem
                    value="default"
                    id="brand-takeaway-payment-default"
                    className="mt-0.5 shrink-0"
                  />
                  <Label
                    htmlFor="brand-takeaway-payment-default"
                    className="cursor-pointer leading-snug"
                  >
                    {t("hq.brand.settings.takeaway_payment.default_radio", {
                      minutes: String(DEFAULT_MINUTES),
                    })}
                  </Label>
                </div>

                <div className="flex items-start gap-3">
                  <RadioGroupItem
                    value="custom"
                    id="brand-takeaway-payment-custom"
                    className="mt-0.5 shrink-0"
                  />
                  <Label
                    htmlFor="brand-takeaway-payment-custom"
                    className="cursor-pointer leading-snug"
                  >
                    {t("hq.brand.settings.takeaway_payment.custom_radio")}
                  </Label>
                </div>
              </RadioGroup>

              {mode === "custom" && (
                <div className="ml-6 space-y-1">
                  <Input
                    id="brand-takeaway-payment-minutes"
                    type="number"
                    min={MIN_MINUTES}
                    max={MAX_MINUTES}
                    step={5}
                    value={minutesRaw}
                    onChange={(e) => setMinutesRaw(e.target.value)}
                    error={minutesError}
                    className="w-24"
                  />
                  {!minutesError && (
                    <p className="text-xs text-muted-foreground">
                      {t("hq.brand.settings.takeaway_payment.minutes_hint")}
                    </p>
                  )}
                </div>
              )}

              {/* plan-037 — confirmation step timeout (brand default) */}
              <div className="space-y-2 border-t pt-4">
                <Label htmlFor="brand-confirmation-timeout">
                  {t("settings.order.confirmation_timeout_label")}
                </Label>
                <p className="text-xs text-muted-foreground">
                  {t("settings.order.confirmation_timeout_hint")}
                </p>
                <Input
                  id="brand-confirmation-timeout"
                  type="number"
                  min={1}
                  max={30}
                  step={1}
                  value={confirmRaw}
                  onChange={(e) => setConfirmRaw(e.target.value)}
                  error={confirmError}
                  className="w-24"
                />
                {!confirmError && (
                  <p className="text-xs text-muted-foreground">
                    {t("settings.order.confirmation_timeout_minutes_hint")}
                  </p>
                )}
              </div>

              {/* #1160 — brand-default prep minutes per item. Empty = no brand
                  default, every shop falls back to the system default (5). */}
              <div className="space-y-2 border-t pt-4">
                <Label htmlFor="brand-prep-minutes-per-item">
                  {t("settings.order.hq_prep_minutes_label")}
                </Label>
                <p className="text-xs text-muted-foreground">
                  {t("settings.order.hq_prep_minutes_hint")}
                </p>
                <Input
                  id="brand-prep-minutes-per-item"
                  type="number"
                  min={0}
                  max={120}
                  step={1}
                  value={prepMinutesRaw}
                  onChange={(e) => setPrepMinutesRaw(e.target.value)}
                  error={prepMinutesError}
                  placeholder={t("settings.order.hq_prep_minutes_placeholder")}
                  className="w-32"
                />
                {!prepMinutesError && (
                  <p className="text-xs text-muted-foreground">
                    {t("settings.order.prep_minutes_example", {
                      minutes: String(prepMinutesNum ?? 5),
                      total: String((prepMinutesNum ?? 5) * 2),
                    })}
                  </p>
                )}
              </div>

              <div className="flex items-center gap-2 border-t pt-4">
                <Button onClick={handleSave} disabled={!canSave} className="gap-2">
                  {saveMutation.isPending && <Spinner className="size-3.5" />}
                  {t("common.save")}
                </Button>
              </div>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
