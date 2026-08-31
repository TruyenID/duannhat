import { Pressable, View } from "react-native";
import { useRouter } from "expo-router";
import { useCallback } from "react";
import { Text } from "@godxjp/ui-native";
import { SplitScreenShell } from "../src/components/ui/split-screen-shell";
import { PaymentMethodGrid } from "../src/components/ui/payment-method-grid";
import { useTranslation } from "../src/providers/app-provider";
import { usePaymentFlow } from "../src/providers/payment-flow-provider";
import { formatCurrency } from "../src/lib/format";

/**
 * Encapsulates the "pay now" navigation. Mints a fresh idempotency key
 * (newAttempt) before navigating so /payment/{method} screens read a non-null
 * key from payment-flow state and the kiosk client sends an `Idempotency-Key`
 * header on POST /api/v1/kiosk/payments. Backend dedupes on this key
 * (OrderPaymentService.php:59-67); without it duplicate POSTs create
 * duplicate payment rows (unique constraint does not bind NULL).
 *
 * Exported for unit testing.
 */
export function usePaymentMethodSubmit() {
  const router = useRouter();
  const { state, currentPayAmount, newAttempt } = usePaymentFlow();
  const currency = state.order?.currency;

  return useCallback(() => {
    if (!state.selectedMethod || !state.order) return;
    newAttempt();
    router.push({
      pathname: `/payment/${state.selectedMethod}`,
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
    state.selectedMethod,
    state.order,
    state.tableId,
    state.orderId,
    currentPayAmount,
    currency,
  ]);
}

export default function PaymentMethodScreen() {
  const { t } = useTranslation();
  const { state, currentPayAmount, setMethod } = usePaymentFlow();
  const currency = state.order?.currency;
  const handlePay = usePaymentMethodSubmit();

  return (
    <SplitScreenShell showBack>
      <View className="flex-1 items-center justify-center px-10">
        <Text className="mb-2 text-center text-4xl font-extrabold tracking-tight text-foreground">
          {t("kiosk.method_title")}
        </Text>
        <Text className="mb-8 text-center text-base text-muted-foreground">
          {t("kiosk.method_sub")}
        </Text>

        <PaymentMethodGrid
          selected={state.selectedMethod}
          onSelect={setMethod}
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
          disabled={!state.selectedMethod}
          className={[
            "mt-6 flex-row items-center gap-3 rounded-2xl px-10 py-5",
            state.selectedMethod ? "bg-primary" : "bg-muted",
          ].join(" ")}
        >
          <Text
            className={[
              "text-xl font-bold",
              state.selectedMethod
                ? "text-primary-foreground"
                : "text-muted-foreground",
            ].join(" ")}
          >
            {t("kiosk.method_pay_now")}
          </Text>
          <Text
            className={
              state.selectedMethod
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
