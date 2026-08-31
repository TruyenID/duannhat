// src/components/ui/numpad.tsx
import { Pressable, View } from "react-native";
import { Text } from "@godxjp/ui-native";
import { useTranslation } from "../../providers/app-provider";

interface NumpadProps {
  value: string;
  onChange: (value: string) => void;
  maxLength?: number;
  onSubmit?: () => void;
  submitDisabled?: boolean;
  submitLabel?: string;
}

const ROWS = [
  ["1", "2", "3"],
  ["4", "5", "6"],
  ["7", "8", "9"],
  ["clear", "0", "del"],
];

export function Numpad({
  value,
  onChange,
  maxLength = 6,
  onSubmit,
  submitDisabled,
  submitLabel,
}: NumpadProps) {
  const { t } = useTranslation();

  const press = (key: string) => {
    if (key === "del") {
      onChange(value.slice(0, -1));
    } else if (key === "clear") {
      onChange("");
    } else if (value.length < maxLength) {
      onChange(value + key);
    }
  };

  return (
    <View className="flex-1 gap-2.5">
      <View className="flex-1 gap-2.5">
        {ROWS.map((row, ri) => (
          <View key={ri} className="flex-1 flex-row gap-2.5">
            {row.map((key) => {
              const isAction = key === "del" || key === "clear";
              return (
                <Pressable
                  key={key}
                  onPress={() => press(key)}
                  className="flex-1 items-center justify-center rounded-2xl border border-border bg-card active:bg-muted"
                >
                  <Text
                    className={[
                      "font-bold text-foreground",
                      isAction ? "text-lg" : "text-3xl",
                    ].join(" ")}
                  >
                    {key === "del"
                      ? "⌫"
                      : key === "clear"
                        ? t("kiosk.numpad_clear")
                        : key}
                  </Text>
                </Pressable>
              );
            })}
          </View>
        ))}
      </View>
      {onSubmit && (
        <Pressable
          onPress={onSubmit}
          disabled={submitDisabled}
          className={[
            "h-14 items-center justify-center rounded-2xl",
            submitDisabled ? "bg-muted" : "bg-primary",
          ].join(" ")}
        >
          <Text
            className={[
              "text-lg font-bold",
              submitDisabled
                ? "text-muted-foreground"
                : "text-primary-foreground",
            ].join(" ")}
          >
            {submitLabel ?? t("common.confirm")}
          </Text>
        </Pressable>
      )}
    </View>
  );
}
