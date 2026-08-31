"use client";

import {
  Badge,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from "@godxjp/ui";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { useUpdateShopPaymentOption } from "@/hooks/api/use-shop-payment-settings";
import type { EffectivePaymentOptionRow } from "@/services/shop-payment-settings-service";
import {
  PaymentPolicyPreference,
  getPaymentPolicyPreferenceLabel,
} from "@/types/models/enum/PaymentPolicyPreference";
import {
  EffectiveOptionPreview,
  effectivePreviewFromShopOption,
} from "./effective-option-preview";

export interface OptionsSectionProps {
  shopSlug: string;
  options: EffectivePaymentOptionRow[];
  setupRequired: boolean;
}

export function OptionsSection({ shopSlug, options, setupRequired }: OptionsSectionProps) {
  const { t } = useTranslation();

  if (setupRequired) {
    return (
      <Card data-slot="payments-options-section">
        <CardHeader>
          <CardTitle>{t("shop.payments.options.title")}</CardTitle>
          <CardDescription>{t("shop.payments.options.prerequisite_desc")}</CardDescription>
        </CardHeader>
      </Card>
    );
  }

  if (options.length === 0) {
    return (
      <Card data-slot="payments-options-section">
        <CardHeader>
          <CardTitle>{t("shop.payments.options.title")}</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-sm text-muted-foreground">{t("shop.payments.options.empty")}</p>
        </CardContent>
      </Card>
    );
  }

  return (
    <div data-slot="payments-options-section" className="space-y-4">
      <div>
        <h2 className="text-base font-semibold">{t("shop.payments.options.title")}</h2>
        <p className="text-sm text-muted-foreground">{t("shop.payments.options.description")}</p>
      </div>

      <ul className="space-y-4" role="list">
        {options.map((option) => (
          <li key={option.id}>
            <OptionRow shopSlug={shopSlug} option={option} />
          </li>
        ))}
      </ul>
    </div>
  );
}

/**
 * Shop preference is narrow BY DESIGN (plan-047): a shop can only `inherit`
 * or `disabled` — the backend throws PaymentPolicyCannotWiden on `enabled`
 * and `blocked`, so those never appear in the select. `blocked` shown on a
 * row means HQ denied the option upstream; the row is read-only then.
 */
const SHOP_SELECTABLE_PREFERENCES = [
  PaymentPolicyPreference.Inherit,
  PaymentPolicyPreference.Disabled,
];

function OptionRow({ shopSlug, option }: { shopSlug: string; option: EffectivePaymentOptionRow }) {
  const { t, locale } = useTranslation();
  const updateMutation = useUpdateShopPaymentOption(shopSlug);
  const blocked = option.shop_preference === PaymentPolicyPreference.Blocked;

  const handleChange = (preference: PaymentPolicyPreference) => {
    if (preference === option.shop_preference) return;
    updateMutation.mutate(
      { optionId: option.id, data: { preference } },
      {
        onSuccess: () => toast.success(t("shop.payments.options.saved")),
        onError: (err: Error) => {
          if (err instanceof ApiError && err.status === 409) {
            toast.error(t("shop.payments.options.conflict"));
            return;
          }
          toast.error(err.message || t("shop.payments.options.save_failed"));
        },
      }
    );
  };

  return (
    <Card>
      <CardHeader className="pb-3">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <CardTitle className="text-sm">{option.display_name}</CardTitle>
            <CardDescription className="flex flex-wrap gap-2 pt-1">
              {option.method_type && (
                <Badge variant="outline" className="h-5 text-[10px]">
                  {option.method_type}
                </Badge>
              )}
              <Badge variant="secondary" className="h-5 text-[10px]">
                {option.provider}
              </Badge>
            </CardDescription>
          </div>
          <Badge variant={option.effective ? "default" : "secondary"}>
            {option.effective
              ? t("shop.payments.preview.enabled")
              : t("shop.payments.preview.disabled")}
          </Badge>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <EffectiveOptionPreview {...effectivePreviewFromShopOption(option)} compact />

        {!blocked && (
          <div className="flex flex-col gap-1.5 border-t pt-4 sm:max-w-xs">
            <Label htmlFor={`pref-${option.id}`}>
              {t("shop.payments.options.preference_label")}
            </Label>
            <div className="flex items-center gap-2">
              <Select
                value={option.shop_preference}
                onValueChange={(v) => handleChange(v as PaymentPolicyPreference)}
                disabled={updateMutation.isPending}
              >
                <SelectTrigger id={`pref-${option.id}`} className="h-8">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {SHOP_SELECTABLE_PREFERENCES.map((p) => (
                    <SelectItem key={p} value={p}>
                      {getPaymentPolicyPreferenceLabel(p, locale)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {updateMutation.isPending && <Spinner className="size-3.5" />}
            </div>
            <p className="text-xs text-muted-foreground">
              {t("shop.payments.options.narrow_only_hint")}
            </p>
          </div>
        )}

        {blocked && (
          <p className="text-xs text-muted-foreground" role="note">
            {t("shop.payments.options.blocked_upstream")}
          </p>
        )}
      </CardContent>
    </Card>
  );
}
