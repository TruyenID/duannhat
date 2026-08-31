// src/components/ui/payment-method-grid.tsx
import { Pressable, View } from "react-native";
import { Text } from "@godxjp/ui-native";
import { useTranslation } from "../../providers/app-provider";
import type { PaymentMethod } from "../../types/kiosk";

interface PaymentMethodOption {
  method: PaymentMethod;
  icon: string;
  labelKey: string;
  subKey: string;
}

const OPTIONS: PaymentMethodOption[] = [
  {
    method: "cash",
    icon: "💴",
    labelKey: "kiosk.payment_method.cash",
    subKey: "kiosk.payment_method.cash_sub",
  },
  {
    method: "card",
    icon: "💳",
    labelKey: "kiosk.payment_method.card",
    subKey: "kiosk.payment_method.card_sub",
  },
  {
    method: "qr",
    icon: "📱",
    labelKey: "kiosk.payment_method.qr",
    subKey: "kiosk.payment_method.qr_sub",
  },
  {
    method: "emoney",
    icon: "📲",
    labelKey: "kiosk.payment_method.emoney",
    subKey: "kiosk.payment_method.emoney_sub",
  },
];

interface PaymentMethodGridProps {
  selected: PaymentMethod | null;
  onSelect: (method: PaymentMethod) => void;
}

export function PaymentMethodGrid({
  selected,
  onSelect,
}: PaymentMethodGridProps) {
  const { t } = useTranslation();

  return (
    <View className="w-full max-w-[800px] flex-row flex-wrap gap-4">
      {OPTIONS.map(({ method, icon, labelKey, subKey }) => {
        const isOn = selected === method;
        return (
          <Pressable
            key={method}
            onPress={() => onSelect(method)}
            className={[
              "w-[48%] flex-row items-center gap-4 rounded-2xl border-2 p-5",
              isOn
                ? "border-primary bg-primary/10"
                : "border-border bg-card",
            ].join(" ")}
          >
            <View
              className={[
                "h-16 w-16 items-center justify-center rounded-xl",
                isOn ? "bg-primary" : "bg-muted",
              ].join(" ")}
            >
              <Text className={isOn ? "text-2xl" : "text-2xl"}>{icon}</Text>
            </View>
            <View className="flex-1">
              <Text className="text-lg font-bold text-foreground">
                {t(labelKey)}
              </Text>
              <Text className="mt-1 text-sm text-muted-foreground">
                {t(subKey)}
              </Text>
            </View>
          </Pressable>
        );
      })}
    </View>
  );
}
