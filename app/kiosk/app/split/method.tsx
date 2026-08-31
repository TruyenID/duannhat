// app/split/method.tsx — Screen 11: Payment method picker for split person
import { Pressable, View } from "react-native";
import { useRouter } from "expo-router";
import { Text } from "@godxjp/ui-native";
import { SplitScreenShell } from "../../src/components/ui/split-screen-shell";
import {
  PaymentOptionsPanel,
  usePaymentOptionsCheckoutBlocked,
} from "../../src/components/ui/payment-options-panel";
import { useTranslation } from "../../src/providers/app-provider";
import { usePaymentFlow } from "../../src/providers/payment-flow-provider";
import { formatCurrency } from "../../src/lib/format";

export default function SplitMethodScreen() {
  const router = useRouter();
  const { t } = useTranslation();
  const {
    state,
    perPersonAmount,
    remainingAmount,
    setSelectedOption,
    newAttempt,
    selectedMethod,
  } = usePaymentFlow();
  const checkoutBlocked = usePaymentOptionsCheckoutBlocked();
  const currency = state.order?.currency;
  const currentPerson = state.currentPersonIndex + 1;

  const handlePay = () => {
    if (!state.selectedOption || !state.order || checkoutBlocked) return;
    newAttempt();
    router.push({
      pathname: `/payment/${selectedMethod}`,
      params: {
        tableId: state.tableId ?? "",
        orderId: state.orderId ?? "",
        amount: String(perPersonAmount),
        currency: currency ?? "JPY",
        splitMode: "even",
        splitLabel: `${currentPerson}/${state.numberOfPeople}`,
        splitBillIndex: String(state.payments.length),
        splitTotalBills: String(state.numberOfPeople),
        totalAmount: String(remainingAmount),
      },
    });
  };

  return (
    <SplitScreenShell showBack>
      <View className="flex-1 items-center justify-center px-10">
        <Text className="mb-2 text-center text-4xl font-extrabold tracking-tight text-foreground">
          {t("kiosk.split_method_title")}
        </Text>
        <Text className="mb-8 text-center text-base text-muted-foreground">
          {t("kiosk.split_method_sub", {
            current: String(currentPerson),
            total: String(state.numberOfPeople),
            amount: formatCurrency(perPersonAmount, currency),
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
    </SplitScreenShell>
  );
}
