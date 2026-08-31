// src/components/ui/split-option-card.tsx
import { Pressable, View } from "react-native";
import { Text } from "@godxjp/ui-native";

interface SplitOptionCardProps {
  icon: string;
  title: string;
  selected: boolean;
  onPress: () => void;
  disabled?: boolean;
  /** Shown under the title when disabled — explains why the option is greyed. */
  hint?: string;
}

export function SplitOptionCard({
  icon,
  title,
  selected,
  onPress,
  disabled,
  hint,
}: SplitOptionCardProps) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      className={[
        "flex-1 max-w-[320px] items-center gap-4 rounded-3xl border-2 p-8",
        disabled
          ? "border-border bg-muted/30 opacity-40"
          : selected
            ? "border-primary bg-primary/10"
            : "border-border bg-card",
      ].join(" ")}
    >
      <View
        className={[
          "h-20 w-20 items-center justify-center rounded-2xl",
          selected && !disabled ? "bg-primary" : "bg-muted",
        ].join(" ")}
      >
        <Text className="text-3xl">{icon}</Text>
      </View>
      <Text className="text-center text-lg font-bold text-foreground">
        {title}
      </Text>
      {disabled && hint ? (
        <Text className="text-center text-xs text-muted-foreground">
          {hint}
        </Text>
      ) : null}
    </Pressable>
  );
}
