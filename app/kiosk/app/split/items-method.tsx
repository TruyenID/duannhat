// app/split/items-method.tsx — Payment method picker for a by-items sub-check.
import { Pressable, View } from "react-native";
import { useRouter } from "expo-router";
import { Text } from "@godxjp/ui-native";
import { TopBar } from "../../src/components/ui/top-bar";
import { StepRibbon } from "../../src/components/ui/step-ribbon";
import {
  PaymentOptionsPanel,
  usePaymentOptionsCheckoutBlocked,
} from "../../src/components/ui/payment-options-panel";
import { SplitBillSidebar } from "../../src/components/ui/split-bill-sidebar";
import { useTranslation } from "../../src/providers/app-provider";
import { usePaymentFlow } from "../../src/providers/payment-flow-provider";
import { formatCurrency } from "../../src/lib/format";

export default function SplitItemsMethodScreen() {
  const router = useRouter();
  const { t } = useTranslation();
  const {
    state,
    totalAmount,
    currentPayAmount,
    setSelectedOption,
    newAttempt,
    selectedMethod,
  } = usePaymentFlow();
  const checkoutBlocked = usePaymentOptionsCheckoutBlocked();
  const currency = state.order?.currency;

  const handlePay = () => {
    if (!state.selectedOption || !state.order || checkoutBlocked) return;
    newAttempt();
    router.push({
      pathname: `/payment/${selectedMethod}`,
      params: {
        tableId: state.tableId ?? "",
        orderId: state.orderId ?? "",
        amount: String(currentPayAmount),
        currency: currency ?? "JPY",
        splitMode: "by_items",
        splitBillIndex: String(state.payments.length),
        totalAmount: String(totalAmount),
      },
    });
  };

  return (
    <View className="flex-1 bg-card">
      <TopBar showBack />
      <StepRibbon step={1} />

      <View className="flex-1 flex-row">
        <SplitBillSidebar />

        <View className="flex-1 items-center justify-center px-10">
          <Text className="mb-2 text-center text-4xl font-extrabold tracking-tight text-foreground">
            {t("kiosk.split_method_title")}
          </Text>
          <Text className="mb-8 text-center text-base text-muted-foreground">
            {t("kiosk.custom_method_sub", {
              amount: formatCurrency(currentPayAmount, currency),
            })}
          </Text>

          <PaymentOptionsPanel
            selectedOption={state.selectedOption}
            onSelect={setSelectedOption}
          />

          <Pressable
            onPress={handlePay}
            disabled={!state.selectedOption || checkoutBlocked}
            className={[
              "mt-8 flex-row items-center gap-3 rounded-2xl px-10 py-5",
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
      </View>
    </View>
  );
}
