// app/split/people.tsx — Screen 10: Enter number of people
import { Pressable, View } from "react-native";
import { useRouter } from "expo-router";
import { Text } from "@godxjp/ui-native";
import { SplitScreenShell } from "../../src/components/ui/split-screen-shell";
import { useTranslation } from "../../src/providers/app-provider";
import { usePaymentFlow } from "../../src/providers/payment-flow-provider";
import { formatCurrency } from "../../src/lib/format";

export default function SplitPeopleScreen() {
  const router = useRouter();
  const { t } = useTranslation();
  const { state, totalAmount, perPersonAmount, setNumberOfPeople } =
    usePaymentFlow();
  const currency = state.order?.currency;
  // Count split-even people by `currentPersonIndex` (reset on mode switch), NOT
  // `payments.length` — the latter includes the prior-paid placeholder and any
  // by-items payments, which would wrongly inflate the person number and lock
  // the headcount when switching into split-even from another method.
  const currentPerson = state.currentPersonIndex + 1;
  const locked = state.currentPersonIndex > 0;

  return (
    <SplitScreenShell
      showBack
      onBack={() => router.dismissTo("/split-options")}
    >
      <View className="flex-1 items-center justify-center px-10">
        <Text className="mb-2 text-center text-4xl font-extrabold tracking-tight text-foreground">
          {t("kiosk.split_even_title")}
        </Text>
        <Text className="mb-8 text-center text-base text-muted-foreground">
          {locked
            ? t("kiosk.split_even_paying_for", {
                current: String(currentPerson),
                total: String(state.numberOfPeople),
              })
            : t("kiosk.split_even_choose_people")}
        </Text>

        <View className="w-full max-w-[560px] gap-5">
          {/* People counter */}
          <View
            className={[
              "flex-row items-center justify-between rounded-2xl border border-border p-6",
              locked ? "bg-muted/50 opacity-70" : "bg-card",
            ].join(" ")}
          >
            <View>
              <Text className="text-xs font-semibold uppercase tracking-[0.2em] text-muted-foreground">
                {t("kiosk.split_even_people_label")}
              </Text>
              <Text className="mt-1 text-sm text-muted-foreground">
                {t("kiosk.split_even_per_person_sub")}
              </Text>
            </View>
            <View className="flex-row items-center gap-4">
              <Pressable
                disabled={locked}
                onPress={() =>
                  setNumberOfPeople(state.numberOfPeople - 1)
                }
                className={[
                  "h-12 w-12 items-center justify-center rounded-xl border border-border",
                  locked ? "bg-muted" : "bg-card",
                ].join(" ")}
              >
                <Text className="text-2xl font-bold text-foreground">−</Text>
              </Pressable>
              <Text className="min-w-[60px] text-center text-5xl font-extrabold text-foreground">
                {state.numberOfPeople}
              </Text>
              <Pressable
                disabled={locked}
                onPress={() =>
                  setNumberOfPeople(state.numberOfPeople + 1)
                }
                className={[
                  "h-12 w-12 items-center justify-center rounded-xl border border-border",
                  locked ? "bg-muted" : "bg-card",
                ].join(" ")}
              >
                <Text className="text-2xl font-bold text-foreground">+</Text>
              </Pressable>
            </View>
          </View>

          {/* Amount cards */}
          <View className="flex-row gap-4">
            <View className="flex-1 rounded-2xl border border-border bg-muted/30 p-5">
              <Text className="text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                {t("kiosk.split_even_bill_total")}
              </Text>
              <Text className="mt-2 text-2xl font-extrabold text-foreground">
                {formatCurrency(totalAmount, currency)}
              </Text>
            </View>
            <View className="flex-1 rounded-2xl border border-primary/20 bg-primary/10 p-5">
              <Text className="text-xs font-bold uppercase tracking-[0.18em] text-primary">
                {t("kiosk.split_even_per_person")}
              </Text>
              <Text className="mt-2 text-2xl font-extrabold text-primary">
                {formatCurrency(perPersonAmount, currency)}
              </Text>
            </View>
          </View>

          {/* Currently paying indicator */}
          {locked && (
            <View className="rounded-2xl border border-warning/50 bg-warning/10 px-5 py-3 text-center">
              <Text className="text-center text-base font-semibold text-warning">
                {t("kiosk.split_even_now_paying", {
                  current: String(currentPerson),
                  total: String(state.numberOfPeople),
                })}
              </Text>
            </View>
          )}

          <Pressable
            onPress={() => router.push("/split/method")}
            className="flex-row items-center justify-center gap-3 rounded-2xl bg-primary py-5"
          >
            <Text className="text-xl font-bold text-primary-foreground">
              {t("kiosk.split_continue")}
            </Text>
            <Text className="text-xl text-primary-foreground">{"→"}</Text>
          </Pressable>
        </View>
      </View>
    </SplitScreenShell>
  );
}
