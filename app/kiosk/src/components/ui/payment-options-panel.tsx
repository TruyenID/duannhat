// Shared effective-options gate for payment method picker screens (Plan 047 F7/F8).
import { ActivityIndicator, View } from "react-native";
import { Text } from "@godxjp/ui-native";
import { useTranslation } from "../../providers/app-provider";
import { useEffectivePaymentOptions } from "../../hooks/use-effective-payment-options";
import { PaymentMethodGrid } from "./payment-method-grid";
import type { PaymentOptionTile } from "../../lib/payment-option-utils";
import type { SelectedPaymentOption } from "../../types/effective-payment-options";
import { toSelectedPaymentOption } from "../../lib/payment-option-utils";

interface PaymentOptionsPanelProps {
  selectedOption: SelectedPaymentOption | null;
  onSelect: (selection: SelectedPaymentOption) => void;
}

export function PaymentOptionsPanel({
  selectedOption,
  onSelect,
}: PaymentOptionsPanelProps) {
  const { t } = useTranslation();
  const { tiles, revision, isLoading, isError, isEmpty } =
    useEffectivePaymentOptions();

  if (isLoading) {
    return (
      <View className="items-center justify-center py-12">
        <ActivityIndicator size="large" />
        <Text className="mt-4 text-base text-muted-foreground">
          {t("kiosk.payment_options_loading")}
        </Text>
      </View>
    );
  }

  if (isError) {
    return (
      <View className="max-w-[640px] rounded-2xl border border-destructive/30 bg-destructive/5 px-6 py-8">
        <Text className="text-center text-lg font-bold text-destructive">
          {t("kiosk.payment_options_error_title")}
        </Text>
        <Text className="mt-2 text-center text-base text-muted-foreground">
          {t("kiosk.payment_options_error_sub")}
        </Text>
      </View>
    );
  }

  if (isEmpty) {
    return (
      <View className="max-w-[640px] rounded-2xl border border-warning/40 bg-warning/5 px-6 py-8">
        <Text className="text-center text-xl font-bold text-foreground">
          {t("kiosk.payment_options_empty_title")}
        </Text>
        <Text className="mt-3 text-center text-base text-muted-foreground">
          {t("kiosk.payment_options_empty_sub")}
        </Text>
      </View>
    );
  }

  const handleSelect = (tile: PaymentOptionTile) => {
    const selection = toSelectedPaymentOption(tile.option, revision);
    if (selection) onSelect(selection);
  };

  return (
    <PaymentMethodGrid
      tiles={tiles}
      selectedOptionId={selectedOption?.optionId ?? null}
      onSelect={handleSelect}
    />
  );
}

export function usePaymentOptionsCheckoutBlocked(): boolean {
  const { isLoading, isEmpty, isError, tiles } = useEffectivePaymentOptions();
  return isLoading || isError || isEmpty || tiles.length === 0;
}
