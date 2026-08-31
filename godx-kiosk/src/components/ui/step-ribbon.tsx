// src/components/ui/step-ribbon.tsx
import { View } from "react-native";
import { Text } from "@godxjp/ui-native";
import { useTranslation } from "../../providers/app-provider";

interface StepRibbonProps {
  step: number; // 0-based: 0=identify table, 1=payment, 2=complete
}

export function StepRibbon({ step }: StepRibbonProps) {
  const { t } = useTranslation();

  const steps = [
    t("kiosk.step_identify"),
    t("kiosk.step_payment"),
    t("kiosk.step_complete"),
  ];

  return (
    <View className="h-14 flex-row items-center justify-center gap-3">
      {steps.map((label, i) => {
        const active = i === step;
        const done = i < step;

        return (
          <View key={i} className="flex-row items-center gap-3">
            <View className="flex-row items-center gap-2">
              <View
                className={[
                  "h-7 w-7 items-center justify-center rounded-full border-[1.5px]",
                  done
                    ? "border-primary bg-primary"
                    : active
                      ? "border-primary bg-card"
                      : "border-muted-foreground bg-transparent",
                ].join(" ")}
              >
                <Text
                  className={[
                    "text-xs font-bold",
                    done
                      ? "text-primary-foreground"
                      : active
                        ? "text-primary"
                        : "text-muted-foreground",
                  ].join(" ")}
                >
                  {done ? "✓" : String(i + 1)}
                </Text>
              </View>
              <Text
                className={[
                  "text-sm",
                  active
                    ? "font-bold text-foreground"
                    : "font-medium text-muted-foreground",
                  done || active ? "opacity-100" : "opacity-50",
                ].join(" ")}
              >
                {label}
              </Text>
            </View>
            {i < steps.length - 1 && (
              <View
                className={[
                  "h-[2px] w-12",
                  i < step ? "bg-primary" : "bg-border",
                ].join(" ")}
              />
            )}
          </View>
        );
      })}
    </View>
  );
}
