"use client";

import {
  Alert,
  AlertDescription,
  Badge,
  Button,
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
import { InfoIcon } from "lucide-react";
import { toast } from "sonner";
import { useTranslation } from "@/providers/app-provider";
import {
  useShopPayPaySwitch,
  useUpdateShopPayPaySwitch,
} from "@/hooks/api/use-shop-payment-settings";
import {
  type PaymentPolicyPreference,
  getPaymentPolicyPreferenceLabel,
} from "@/types/models/enum/PaymentPolicyPreference";

export interface PayPaySectionProps {
  shopSlug: string;
}

/**
 * plan-054 D9 / T5.6 — "the brand enables PayPay, a shop may opt out".
 *
 * The generic options list below this card cannot carry the customer-web QR
 * capability yet: it is assembled from `payment_gateway_connection_options`,
 * and that row is created lazily at the first PayPay checkout. So on a shop
 * that has never taken a PayPay payment there is no row to switch off —
 * which is precisely when opting out matters. This card talks to a dedicated
 * endpoint that always resolves, and writes the same
 * `shop_payment_options.preference` the generic select writes.
 */
export function PayPaySection({ shopSlug }: PayPaySectionProps) {
  const { t, locale } = useTranslation();
  const { data, isLoading, isError, refetch } = useShopPayPaySwitch(shopSlug);
  const updateMutation = useUpdateShopPayPaySwitch(shopSlug);

  const state = data?.data;

  const handleChange = (preference: PaymentPolicyPreference) => {
    if (preference === state?.preference) return;
    updateMutation.mutate(
      { preference },
      {
        onSuccess: () => toast.success(t("shop.payments.paypay.saved")),
        onError: (err: Error) => toast.error(err.message || t("shop.payments.paypay.save_failed")),
      }
    );
  };

  return (
    <Card data-slot="payments-paypay-section">
      <CardHeader className="pb-3">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <CardTitle className="text-sm">{t("shop.payments.paypay.title")}</CardTitle>
            <CardDescription className="pt-1">
              {t("shop.payments.paypay.description")}
            </CardDescription>
          </div>
          {state && (
            <Badge variant={state.effective_enabled ? "default" : "secondary"}>
              {state.effective_enabled
                ? t("shop.payments.preview.enabled")
                : t("shop.payments.preview.disabled")}
            </Badge>
          )}
        </div>
      </CardHeader>

      <CardContent className="space-y-4">
        {isLoading && (
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Spinner className="size-4" />
            {t("common.loading")}
          </div>
        )}

        {isError && (
          <div className="flex items-center justify-between gap-3 text-sm text-muted-foreground">
            <span>{t("common.error_loading")}</span>
            <Button variant="outline" size="sm" onClick={() => refetch()}>
              {t("common.retry")}
            </Button>
          </div>
        )}

        {state && (
          <>
            {/* The switch stays usable even when PayPay is unavailable for
                reasons outside the shop's control — the opt-out must already
                hold on the day the brand finishes its setup. Say so rather
                than greying the control out with no explanation. */}
            {!state.brand_enabled && state.reason && (
              <Alert>
                <InfoIcon className="size-4" />
                <AlertDescription>
                  {t(`shop.payments.paypay.reason.${state.reason}`)}
                </AlertDescription>
              </Alert>
            )}

            <div className="flex flex-col gap-1.5 sm:max-w-xs">
              <Label htmlFor="paypay-preference">
                {t("shop.payments.options.preference_label")}
              </Label>
              <div className="flex items-center gap-2">
                <Select
                  value={state.preference}
                  onValueChange={(v) => handleChange(v as PaymentPolicyPreference)}
                  disabled={updateMutation.isPending}
                >
                  <SelectTrigger id="paypay-preference" className="h-8">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {state.available_preferences.map((p) => (
                      <SelectItem key={p} value={p}>
                        {getPaymentPolicyPreferenceLabel(p, locale)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {updateMutation.isPending && <Spinner className="size-3.5" />}
              </div>
              <p className="text-xs text-muted-foreground">
                {t("shop.payments.paypay.inherit_hint")}
              </p>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}
