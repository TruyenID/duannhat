// src/components/ui/payment-progress.tsx
import { View } from "react-native";
import { Text } from "@godxjp/ui-native";
import { useTranslation } from "../../providers/app-provider";
import { formatCurrency } from "../../lib/format";

interface PaymentProgressProps {
  total: number;
  paid: number;
  currency?: string;
  /** For split-even: "3/5 people" label */
  label?: string;
}

export function PaymentProgress({
  total,
  paid,
  currency,
  label,
}: PaymentProgressProps) {
  const { t } = useTranslation();
  const remaining = Math.max(0, total - paid);
  const pct = total > 0 ? Math.min(100, (paid / total) * 100) : 0;

  return (
    <View className="w-full">
      <View className="mb-2 flex-row items-baseline justify-between">
        <Text className="text-sm font-semibold text-muted-foreground">
          {t("kiosk.payment_progress")}
        </Text>
        {label ? (
          <Text className="text-sm font-bold text-primary">{label}</Text>
        ) : (
          <Text className="text-sm font-bold text-primary">
            {Math.round(pct)}%
          </Text>
        )}
      </View>
      <View className="h-3 overflow-hidden rounded-full bg-muted">
        <View
          className="h-full rounded-full bg-primary"
          style={{ width: `${pct}%` }}
        />
      </View>
      <View className="mt-2 flex-row justify-between">
        <Text className="text-xs text-muted-foreground">
          {t("kiosk.paid_label")}{" "}
          <Text className="font-bold text-foreground">
            {formatCurrency(paid, currency)}
          </Text>
        </Text>
        <Text className="text-xs text-muted-foreground">
          {t("kiosk.remaining_label")}{" "}
          <Text
            className={[
              "font-bold",
              remaining > 0 ? "text-warning" : "text-primary",
            ].join(" ")}
          >
            {formatCurrency(remaining, currency)}
          </Text>
        </Text>
      </View>
    </View>
  );
}
