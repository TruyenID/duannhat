// app/split-options.tsx — Screen 3: Full / Split Even / Custom Amount
import { Pressable, View } from "react-native";
import { useRouter } from "expo-router";
import { Text } from "@godxjp/ui-native";
import { TopBar } from "../src/components/ui/top-bar";
import { StepRibbon } from "../src/components/ui/step-ribbon";
import { SplitOptionCard } from "../src/components/ui/split-option-card";
import { SplitBillSidebar } from "../src/components/ui/split-bill-sidebar";
import { useTranslation } from "../src/providers/app-provider";
import { usePaymentFlow } from "../src/providers/payment-flow-provider";
import type { SplitMode } from "../src/types/kiosk";

export default function SplitOptionsScreen() {
  const router = useRouter();
  const { t } = useTranslation();
  const { state, setSplitMode } = usePaymentFlow();

  // By-items reconciles per-item claims; once a real payment is CONFIRMED via
  // another method this session, the paid amount no longer maps to specific
  // items, so the split would be inconsistent → block it then.
  // Only a confirmed payment counts: the prior-paid placeholder seeded from
  // `order.paid_amount` (empty ids) and mere navigation into another method
  // (click + back, no confirm) must NOT disable it.
  const hasConfirmedPayment = state.payments.some(
    (p) => p.payment_id !== "" || p.reference_no !== "",
  );
  const byItemsDisabled = hasConfirmedPayment && state.splitMode !== "by_items";

  const handleContinue = () => {
    if (state.splitMode === "split_even") {
      router.push("/split/people");
    } else if (state.splitMode === "by_items") {
      router.push("/split/items");
    } else if (state.splitMode === "custom") {
      router.push("/custom/amount");
    } else {
      router.push("/payment-method");
    }
  };

  const options: {
    id: SplitMode;
    icon: string;
    label: string;
    disabled?: boolean;
    hint?: string;
  }[] = [
    { id: "full", icon: "💳", label: t("kiosk.split_full") },
    { id: "split_even", icon: "👥", label: t("kiosk.split_even") },
    {
      id: "by_items",
      icon: "🧾",
      label: t("kiosk.split_by_items"),
      disabled: byItemsDisabled,
      hint: t("kiosk.split_by_items_disabled"),
    },
    { id: "custom", icon: "✏️", label: t("kiosk.split_custom") },
  ];

  return (
    <View className="flex-1 bg-card">
      <TopBar showBack />
      <StepRibbon step={1} />

      <View className="flex-1 flex-row">
        {/* 1/3 — order view + live bill */}
        <SplitBillSidebar />

        {/* 2/3 — split mode picker */}
        <View className="flex-1 items-center justify-center px-10">
          <Text className="mb-10 text-center text-4xl font-extrabold tracking-tight text-foreground">
            {t("kiosk.split_title")}
          </Text>

          <View className="w-full max-w-[760px] flex-row flex-wrap justify-center gap-5">
            {options.map((o) => (
              <SplitOptionCard
                key={o.id}
                icon={o.icon}
                title={o.label}
                selected={state.splitMode === o.id}
                disabled={o.disabled}
                hint={o.hint}
                onPress={() => setSplitMode(o.id)}
              />
            ))}
          </View>

          <Pressable
            onPress={handleContinue}
            className="mt-10 flex-row items-center gap-3 rounded-2xl bg-primary px-10 py-5"
          >
            <Text className="text-xl font-bold text-primary-foreground">
              {t("kiosk.split_continue")}
            </Text>
            <Text className="text-xl text-primary-foreground">{"→"}</Text>
          </Pressable>
        </View>
      </View>
    </View>
  );
}
