// app/payment/_layout.tsx — Full-screen layout (redesigned)
import { View } from "react-native";
import { Slot } from "expo-router";
import { TopBar } from "../../src/components/ui/top-bar";
import { StepRibbon } from "../../src/components/ui/step-ribbon";
import { SplitBillSidebar } from "../../src/components/ui/split-bill-sidebar";
import { usePaymentFlow } from "../../src/providers/payment-flow-provider";

export default function PaymentLayout() {
  const { state } = usePaymentFlow();
  // Keep the order view pinned through the actual payment screen for every
  // split mode, so the whole journey stays a consistent 3-column layout.
  const showBill = state.order != null;

  return (
    <View className="flex-1 bg-card">
      <TopBar showBack />
      <StepRibbon step={1} />
      <View className="flex-1 flex-row">
        {showBill && <SplitBillSidebar />}
        <View className="flex-1">
          <Slot />
        </View>
      </View>
    </View>
  );
}
