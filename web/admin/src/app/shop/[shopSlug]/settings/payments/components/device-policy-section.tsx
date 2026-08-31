"use client";

import {
  Badge,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Label,
  Switch,
} from "@godxjp/ui";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { useUpdateDevicePaymentOption } from "@/hooks/api/use-shop-payment-settings";
import type {
  EffectiveOptionsEvaluation,
  EffectivePaymentOptionRow,
} from "@/services/shop-payment-settings-service";
import {
  EffectiveOptionPreview,
  effectivePreviewFromDeviceOption,
} from "./effective-option-preview";

export interface DevicePolicySectionProps {
  shopSlug: string;
  deviceId: string;
  evaluation: EffectiveOptionsEvaluation;
}

/**
 * Per-device payment-option overrides (plan-047 T5.4). A device may only
 * NARROW the shop policy: each row carries a "disable on this device" switch
 * that PATCHes { option_id, preference: "disabled" | "inherit" }. The backend
 * refuses to disable an option that is not shop-effective (nothing to
 * narrow), so the switch is only offered on effective rows.
 */
export function DevicePolicySection({ shopSlug, deviceId, evaluation }: DevicePolicySectionProps) {
  const { t } = useTranslation();
  const updateMutation = useUpdateDevicePaymentOption(shopSlug, deviceId);

  const handleToggle = (row: EffectivePaymentOptionRow, disabled: boolean) => {
    updateMutation.mutate(
      {
        option_id: row.id,
        preference: disabled ? "disabled" : "inherit",
      },
      {
        onSuccess: () => toast.success(t("shop.payments.device.saved")),
        onError: (err: Error) => {
          if (err instanceof ApiError && (err.status === 409 || err.status === 422)) {
            toast.error(t("shop.payments.device.conflict_widen"));
            return;
          }
          toast.error(err.message || t("shop.payments.device.save_failed"));
        },
      }
    );
  };

  return (
    <div data-slot="device-policy-section" className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>{t("shop.payments.device.options_title")}</CardTitle>
          <CardDescription>{t("shop.payments.device.narrow_only_desc")}</CardDescription>
        </CardHeader>
        <CardContent>
          <dl className="grid gap-4 text-xs sm:grid-cols-3">
            <div>
              <dt className="text-muted-foreground">{t("shop.payments.device.revision")}</dt>
              <dd className="mt-1 font-mono">{evaluation.revision}</dd>
            </div>
            <div className="sm:col-span-2">
              <dt className="text-muted-foreground">{t("shop.payments.device.published_at")}</dt>
              <dd className="mt-1 font-mono">{evaluation.published_at ?? "—"}</dd>
            </div>
          </dl>
        </CardContent>
      </Card>

      <ul className="space-y-4" role="list">
        {evaluation.options.map((row) => {
          const deviceDisabled = row.device_preference === "disabled";
          // The backend only accepts `disabled` on a row that is effective at
          // shop level; an already-device-disabled row must stay toggleable so
          // the operator can revert to `inherit`.
          const canToggle = row.effective || deviceDisabled;

          return (
            <li key={row.id}>
              <Card>
                <CardHeader className="pb-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                      <CardTitle className="text-sm">{row.display_name}</CardTitle>
                      <CardDescription className="flex flex-wrap gap-2 pt-1">
                        {row.method_type && (
                          <Badge variant="outline" className="h-5 text-[10px]">
                            {row.method_type}
                          </Badge>
                        )}
                        <Badge variant="secondary" className="h-5 text-[10px]">
                          {row.provider}
                        </Badge>
                      </CardDescription>
                    </div>
                    <Badge variant={row.effective ? "default" : "secondary"}>
                      {row.effective
                        ? t("shop.payments.preview.enabled")
                        : t("shop.payments.preview.disabled")}
                    </Badge>
                  </div>
                </CardHeader>
                <CardContent className="space-y-4">
                  <EffectiveOptionPreview {...effectivePreviewFromDeviceOption(row)} compact />

                  {canToggle ? (
                    <div className="flex items-center justify-between border-t pt-4">
                      <Label htmlFor={`disable-${row.id}`}>
                        {t("shop.payments.device.disable_on_device")}
                      </Label>
                      <Switch
                        id={`disable-${row.id}`}
                        checked={deviceDisabled}
                        disabled={updateMutation.isPending}
                        onCheckedChange={(checked) => handleToggle(row, checked)}
                        aria-label={t("shop.payments.device.disable_on_device")}
                      />
                    </div>
                  ) : (
                    <p className="text-xs text-muted-foreground" role="note">
                      {t("shop.payments.device.not_available_shop")}
                    </p>
                  )}
                </CardContent>
              </Card>
            </li>
          );
        })}
      </ul>

      {evaluation.options.length === 0 && (
        <p className="text-sm text-muted-foreground">{t("shop.payments.options.empty")}</p>
      )}
    </div>
  );
}
