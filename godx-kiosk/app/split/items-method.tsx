// app/split/items-method.tsx — Payment method picker for a by-items sub-check.
import { Pressable, View } from "react-native";
import { useRouter } from "expo-router";
import { Text } from "@godxjp/ui-native";
import { TopBar } from "../../src/components/ui/top-bar";
import { StepRibbon } from "../../src/components/ui/step-ribbon";
import { PaymentMethodGrid } from "../../src/components/ui/payment-method-grid";
import { SplitBillSidebar } from "../../src/components/ui/split-bill-sidebar";
import { useTranslation } from "../../src/providers/app-provider";
import { usePaymentFlow } from "../../src/providers/payment-flow-provider";
import { formatCurrency } from "../../src/lib/format";

export default function SplitItemsMethodScreen() {
  const router = useRouter();
  const { t } = useTranslation();
  const { state, totalAmount, currentPayAmount, setMethod, newAttempt } =
    usePaymentFlow();
  const currency = state.order?.currency;

  const handlePay = () => {
    if (!state.selectedMethod || !state.order) return;
    newAttempt();
    router.push({
      pathname: `/payment/${state.selectedMethod}`,
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
        {/* 1/3 — order view + live bill */}
        <SplitBillSidebar />

        {/* 2/3 — method picker */}
        <View className="flex-1 items-center justify-center px-10">
          <Text className="mb-2 text-center text-4xl font-extrabold tracking-tight text-foreground">
            {t("kiosk.split_method_title")}
          </Text>
          <Text className="mb-8 text-center text-base text-muted-foreground">
            {t("kiosk.custom_method_sub", {
              amount: formatCurrency(currentPayAmount, currency),
            })}
          </Text>

          <PaymentMethodGrid
            selected={state.selectedMethod}
            onSelect={setMethod}
          />

          <Pressable
            onPress={handlePay}
            disabled={!state.selectedMethod}
            className={[
              "mt-8 flex-row items-center gap-3 rounded-2xl px-10 py-5",
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
      </View>
    </View>
  );
}
