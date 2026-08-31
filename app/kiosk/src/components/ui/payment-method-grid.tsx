// src/components/ui/payment-method-grid.tsx
import { Pressable, View } from "react-native";
import { Text } from "@godxjp/ui-native";
import { useTranslation } from "../../providers/app-provider";
import type { PaymentOptionTile } from "../../lib/payment-option-utils";

interface PaymentMethodGridProps {
  tiles: PaymentOptionTile[];
  selectedOptionId: string | null;
  onSelect: (tile: PaymentOptionTile) => void;
}

export function PaymentMethodGrid({
  tiles,
  selectedOptionId,
  onSelect,
}: PaymentMethodGridProps) {
  const { t } = useTranslation();

  return (
    <View className="w-full max-w-[800px] flex-row flex-wrap gap-4">
      {tiles.map((tile) => {
        const isOn = selectedOptionId === tile.option.id;
        return (
          <Pressable
            key={tile.option.id}
            onPress={() => onSelect(tile)}
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
              <Text className="text-2xl">{tile.icon}</Text>
            </View>
            <View className="flex-1">
              <Text className="text-lg font-bold text-foreground">
                {tile.displayName || t(tile.labelKey)}
              </Text>
              <Text className="mt-1 text-sm text-muted-foreground">
                {t(tile.subKey)}
              </Text>
            </View>
          </Pressable>
        );
      })}
    </View>
  );
}
