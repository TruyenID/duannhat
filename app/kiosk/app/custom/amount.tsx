// app/custom/amount.tsx — Screen 20: Enter custom payment amount
import { useState, useMemo } from "react";
import { Pressable, TextInput, View } from "react-native";
import { useRouter } from "expo-router";
import { Text } from "@godxjp/ui-native";
import { SplitScreenShell } from "../../src/components/ui/split-screen-shell";
import { useTranslation } from "../../src/providers/app-provider";
import { usePaymentFlow } from "../../src/providers/payment-flow-provider";
import { formatCurrency } from "../../src/lib/format";

export default function CustomAmountScreen() {
  const router = useRouter();
  const { t } = useTranslation();
  const {
    state,
    totalAmount,
    paidAmount,
    remainingAmount,
    setCurrentAmount,
    setPayAllRemaining,
  } = usePaymentFlow();
  const currency = state.order?.currency;

  const [inputValue, setInputValue] = useState(
    state.currentAmount > 0 ? String(state.currentAmount) : "",
  );

  const currentAmt = useMemo(() => {
    if (state.payAllRemaining) return remainingAmount;
    const parsed = parseInt(inputValue.replace(/\D/g, ""), 10);
    return isNaN(parsed) ? 0 : parsed;
  }, [state.payAllRemaining, inputValue, remainingAmount]);

  const valid = currentAmt > 0 && currentAmt <= remainingAmount;
  const exceeds =
    !state.payAllRemaining &&
    inputValue.length > 0 &&
    currentAmt > remainingAmount;

  const handleTogglePayAll = () => {
    const next = !state.payAllRemaining;
    setPayAllRemaining(next);
    if (next) {
      setCurrentAmount(remainingAmount);
    }
  };

  const handleChangeText = (text: string) => {
    const digits = text.replace(/\D/g, "");
    setInputValue(digits);
    const parsed = parseInt(digits, 10);
    setCurrentAmount(isNaN(parsed) ? 0 : parsed);
  };

  const handleContinue = () => {
    if (!valid) return;
    setCurrentAmount(currentAmt);
    router.push("/custom/method");
  };

  return (
    <SplitScreenShell
      showBack
      onBack={() => router.dismissTo("/split-options")}
    >
      <View className="flex-1 items-center justify-center px-10">
        <Text className="mb-2 text-center text-4xl font-extrabold tracking-tight text-foreground">
          {t("kiosk.custom_title")}
        </Text>
        <Text className="mb-8 text-center text-base text-muted-foreground">
          {t("kiosk.custom_sub")}
        </Text>

        <View className="w-full max-w-[560px] gap-5">
          {/* Amount input */}
          <View className="rounded-2xl border border-border bg-card p-6">
            <Text className="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground">
              {t("kiosk.custom_input_label")}
            </Text>
            <TextInput
              value={state.payAllRemaining ? String(remainingAmount) : inputValue}
              onChangeText={handleChangeText}
              editable={!state.payAllRemaining}
              keyboardType="numeric"
              placeholder="0"
              placeholderTextColor="#999"
              className={[
                "text-4xl font-extrabold text-foreground",
                state.payAllRemaining ? "opacity-50" : "",
              ].join(" ")}
            />
            {exceeds && (
              <Text className="mt-2 text-sm font-medium text-destructive">
                {t("kiosk.custom_exceeds", {
                  amount: formatCurrency(remainingAmount, currency),
                })}
              </Text>
            )}
          </View>

          {/* Pay all remaining toggle */}
          <Pressable
            onPress={handleTogglePayAll}
            className={[
              "flex-row items-center gap-3 rounded-2xl border p-5",
              state.payAllRemaining
                ? "border-primary bg-primary/10"
                : "border-border bg-card",
            ].join(" ")}
          >
            <View
              className={[
                "h-6 w-6 items-center justify-center rounded-md border-2",
                state.payAllRemaining
                  ? "border-primary bg-primary"
                  : "border-border bg-card",
              ].join(" ")}
            >
              {state.payAllRemaining && (
                <Text className="text-xs font-bold text-primary-foreground">
                  {"✓"}
                </Text>
              )}
            </View>
            <Text
              className={[
                "text-base font-semibold",
                state.payAllRemaining ? "text-primary" : "text-foreground",
              ].join(" ")}
            >
              {t("kiosk.custom_pay_all")}
            </Text>
          </Pressable>

          {/* Summary cards */}
          <View className="flex-row gap-4">
            <View className="flex-1 rounded-2xl border border-border bg-muted/30 p-5">
              <Text className="text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                {t("kiosk.custom_bill_total")}
              </Text>
              <Text className="mt-2 text-2xl font-extrabold text-foreground">
                {formatCurrency(totalAmount, currency)}
              </Text>
            </View>
            <View className="flex-1 rounded-2xl border border-border bg-muted/30 p-5">
              <Text className="text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                {t("kiosk.custom_paid_so_far")}
              </Text>
              <Text className="mt-2 text-2xl font-extrabold text-foreground">
                {formatCurrency(paidAmount, currency)}
              </Text>
            </View>
            <View className="flex-1 rounded-2xl border border-primary/20 bg-primary/10 p-5">
              <Text className="text-xs font-bold uppercase tracking-[0.18em] text-primary">
                {t("kiosk.custom_remaining")}
              </Text>
              <Text
                className={[
                  "mt-2 text-2xl font-extrabold",
                  remainingAmount > 0 ? "text-warning" : "text-primary",
                ].join(" ")}
              >
                {formatCurrency(remainingAmount, currency)}
              </Text>
            </View>
          </View>

          {/* Continue button */}
          <Pressable
            onPress={handleContinue}
            disabled={!valid}
            className={[
              "flex-row items-center justify-center gap-3 rounded-2xl py-5",
              valid ? "bg-primary" : "bg-muted",
            ].join(" ")}
          >
            <Text
              className={[
                "text-xl font-bold",
                valid ? "text-primary-foreground" : "text-muted-foreground",
              ].join(" ")}
            >
              {t("kiosk.split_continue")}
            </Text>
            <Text
              className={
                valid ? "text-xl text-primary-foreground" : "text-xl text-muted-foreground"
              }
            >
              {"→"}
            </Text>
          </Pressable>
        </View>
      </View>
    </SplitScreenShell>
  );
}
