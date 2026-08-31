import { Pressable, View } from "react-native";
import { useRouter } from "expo-router";
import { useCallback } from "react";
import { Text } from "@godxjp/ui-native";
import { SplitScreenShell } from "../src/components/ui/split-screen-shell";
import {
  PaymentOptionsPanel,
  usePaymentOptionsCheckoutBlocked,
} from "../src/components/ui/payment-options-panel";
import { useTranslation } from "../src/providers/app-provider";
import { usePaymentFlow } from "../src/providers/payment-flow-provider";
import { formatCurrency } from "../src/lib/format";

/**
 * Encapsulates the "pay now" navigation. Mints a fresh idempotency key
 * (newAttempt) before navigating so /payment/{method} screens read a non-null
 * key from payment-flow state and the kiosk client sends an `Idempotency-Key`
 * header on POST /api/v1/kiosk/payments.
 */
export function usePaymentMethodSubmit() {
  const router = useRouter();
  const { state, currentPayAmount, newAttempt, selectedMethod } = usePaymentFlow();
  const checkoutBlocked = usePaymentOptionsCheckoutBlocked();
  const currency = state.order?.currency;

  return useCallback(() => {
    if (!state.selectedOption || !state.order || checkoutBlocked) return;
    newAttempt();
    router.push({
      pathname: `/payment/${selectedMethod}`,
      params: {
        tableId: state.tableId ?? "",
        orderId: state.orderId ?? "",
        amount: String(currentPayAmount),
        currency: currency ?? "JPY",
      },
    });
  }, [
    router,
    newAttempt,
    checkoutBlocked,
    state.selectedOption,
    state.order,
    state.tableId,
    state.orderId,
    selectedMethod,
    currentPayAmount,
    currency,
  ]);
}

export default function PaymentMethodScreen() {
  const { t } = useTranslation();
  const {
    state,
    currentPayAmount,
    setSelectedOption,
  } = usePaymentFlow();
  const currency = state.order?.currency;
  const handlePay = usePaymentMethodSubmit();
  const checkoutBlocked = usePaymentOptionsCheckoutBlocked();

  return (
    <SplitScreenShell showBack>
      <View className="flex-1 items-center justify-center px-10">
        <Text className="mb-2 text-center text-4xl font-extrabold tracking-tight text-foreground">
          {t("kiosk.method_title")}
        </Text>
        <Text className="mb-8 text-center text-base text-muted-foreground">
          {t("kiosk.method_sub")}
        </Text>

        <PaymentOptionsPanel
          selectedOption={state.selectedOption}
          onSelect={setSelectedOption}
        />

        <View className="mt-8 flex-row items-center gap-4">
          <Text className="text-base text-muted-foreground">
            {t("kiosk.method_amount_label")}
          </Text>
          <Text className="text-3xl font-extrabold text-primary">
            {formatCurrency(currentPayAmount, currency)}
          </Text>
        </View>

        <Pressable
          onPress={handlePay}
          disabled={!state.selectedOption || checkoutBlocked}
          className={[
            "mt-6 flex-row items-center gap-3 rounded-2xl px-10 py-5",
            state.selectedOption && !checkoutBlocked ? "bg-primary" : "bg-muted",
          ].join(" ")}
        >
          <Text
            className={[
              "text-xl font-bold",
              state.selectedOption && !checkoutBlocked
                ? "text-primary-foreground"
                : "text-muted-foreground",
            ].join(" ")}
          >
            {t("kiosk.method_pay_now")}
          </Text>
          <Text
            className={
              state.selectedOption && !checkoutBlocked
                ? "text-primary-foreground"
                : "text-muted-foreground"
            }
          >
            {"→"}
          </Text>
        </Pressable>
      </View>
    </SplitScreenShell>
  );
}
