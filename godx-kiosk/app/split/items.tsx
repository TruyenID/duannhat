// app/split/items.tsx — By-items split: pick which items this guest pays for.
import { useEffect, useMemo, useState } from "react";
import { Pressable, ScrollView, View } from "react-native";
import { useRouter } from "expo-router";
import { Text } from "@godxjp/ui-native";
import { TopBar } from "../../src/components/ui/top-bar";
import { StepRibbon } from "../../src/components/ui/step-ribbon";
import { SplitBillSidebar } from "../../src/components/ui/split-bill-sidebar";
import { useTranslation } from "../../src/providers/app-provider";
import { usePaymentFlow } from "../../src/providers/payment-flow-provider";
import { useSplitByItemsPreview } from "../../src/hooks/use-split-preview";
import { formatCurrency } from "../../src/lib/format";
import { itemDisplay } from "../../src/lib/item-name";
import type { ItemAllocation } from "../../src/types/kiosk";

export default function SplitItemsScreen() {
  const router = useRouter();
  const { t } = useTranslation();
  const { state, setItemAllocations } = usePaymentFlow();
  const order = state.order;
  const currency = order?.currency;

  // itemId → units the guest is paying for in this sub-check.
  const [units, setUnits] = useState<Record<string, number>>({});

  const allocations = useMemo<ItemAllocation[]>(
    () =>
      Object.entries(units)
        .filter(([, n]) => n > 0)
        .map(([item_id, n]) => ({ item_id, units: n })),
    [units],
  );

  const { preview, isLoading } = useSplitByItemsPreview(
    state.orderId ?? "",
    allocations,
  );

  // units_remaining per item from the server (claims summed from non-failed
  // payments). Falls back to full quantity until the first preview lands.
  const remainingByItem = useMemo(() => {
    const map: Record<string, number> = {};
    // Preview is the live claim count during selection...
    for (const it of preview?.items ?? []) {
      map[it.item_id] = it.units_remaining;
    }
    // ...but the order's server-authoritative `remaining_units` wins when present
    // (refreshed after each payment via recordPayment → refreshOrder). This is
    // what makes "Còn x/y" actually drop after a guest pays for items.
    for (const it of order?.items ?? []) {
      if (it.remaining_units != null) map[it.id] = it.remaining_units;
    }
    return map;
  }, [order?.items, preview]);

  // Once the server reports authoritative `units_remaining`, clamp any
  // selection that exceeds it (the kiosk pay path is not server-validated, so
  // an over-selection would silently overpay). Only shrinks, never grows.
  useEffect(() => {
    if (!preview) return;
    setUnits((prev) => {
      let changed = false;
      const next: Record<string, number> = {};
      for (const [id, n] of Object.entries(prev)) {
        const cap = remainingByItem[id] ?? n;
        const clamped = Math.min(n, cap);
        next[id] = clamped;
        if (clamped !== n) changed = true;
      }
      return changed ? next : prev;
    });
  }, [preview, remainingByItem]);

  const billTotal = Number(preview?.preview_bills?.[0]?.total ?? 0);
  const selectedUnits = allocations.reduce((s, a) => s + a.units, 0);
  const canContinue = selectedUnits > 0 && billTotal > 0;

  const setItemUnits = (itemId: string, next: number, max: number) => {
    const clamped = Math.max(0, Math.min(max, next));
    setUnits((prev) => ({ ...prev, [itemId]: clamped }));
  };

  const handleContinue = () => {
    if (!canContinue) return;
    setItemAllocations(allocations, billTotal);
    router.push("/split/items-method");
  };

  if (!order) {
    return (
      <View className="flex-1 bg-card">
        <TopBar showBack />
        <StepRibbon step={1} />
      </View>
    );
  }

  return (
    <View className="flex-1 bg-card">
      <TopBar showBack onBack={() => router.dismissTo("/split-options")} />
      <StepRibbon step={1} />

      <View className="flex-1 flex-row">
        {/* 1/3 — order view + live bill */}
        <SplitBillSidebar />

        {/* 2/3 — item picker */}
        <View className="flex-1 px-10 pb-8 pt-6">
          <Text className="mb-1 text-4xl font-extrabold tracking-tight text-foreground">
            {t("kiosk.split_by_items_title")}
          </Text>
          <Text className="mb-6 text-base text-muted-foreground">
            {t("kiosk.split_by_items_sub")}
          </Text>

          <ScrollView
            className="flex-1"
            contentContainerStyle={{ gap: 12, paddingBottom: 12 }}
            showsVerticalScrollIndicator={false}
          >
            {order.items.map((item) => {
              const remaining = remainingByItem[item.id] ?? item.quantity;
              const picked = units[item.id] ?? 0;
              const soldOut = remaining <= 0;
              const display = itemDisplay(item);
              return (
                <View
                  key={item.id}
                  className={[
                    "flex-row items-center justify-between rounded-2xl border p-5",
                    soldOut
                      ? "border-border bg-muted/40 opacity-60"
                      : picked > 0
                        ? "border-primary bg-primary/5"
                        : "border-border bg-card",
                  ].join(" ")}
                >
                  <View className="flex-1 pr-4">
                    <Text className="text-lg font-bold text-foreground">
                      {display.title}
                    </Text>
                    {display.variant && (
                      <Text className="text-xs text-muted-foreground">
                        {display.variant}
                      </Text>
                    )}
                    <Text className="mt-1 text-sm text-muted-foreground">
                      {formatCurrency(item.unit_price, currency)}
                      {"  ·  "}
                      {soldOut
                        ? t("kiosk.split_by_items_all_paid")
                        : t("kiosk.split_by_items_available", {
                            remaining: String(remaining),
                            total: String(item.quantity),
                          })}
                    </Text>
                  </View>

                  {soldOut ? (
                    <View className="rounded-full bg-muted px-4 py-2">
                      <Text className="text-sm font-semibold text-muted-foreground">
                        {t("kiosk.split_by_items_paid_badge")}
                      </Text>
                    </View>
                  ) : (
                    <View className="flex-row items-center gap-3">
                      <Pressable
                        onPress={() =>
                          setItemUnits(item.id, picked - 1, remaining)
                        }
                        disabled={picked <= 0}
                        className={[
                          "h-11 w-11 items-center justify-center rounded-xl border border-border",
                          picked <= 0 ? "bg-muted opacity-50" : "bg-card",
                        ].join(" ")}
                      >
                        <Text className="text-2xl font-bold text-foreground">
                          −
                        </Text>
                      </Pressable>
                      <Text className="min-w-[40px] text-center text-2xl font-extrabold text-foreground">
                        {picked}
                      </Text>
                      <Pressable
                        onPress={() =>
                          setItemUnits(item.id, picked + 1, remaining)
                        }
                        disabled={picked >= remaining}
                        className={[
                          "h-11 w-11 items-center justify-center rounded-xl border border-border",
                          picked >= remaining ? "bg-muted opacity-50" : "bg-card",
                        ].join(" ")}
                      >
                        <Text className="text-2xl font-bold text-foreground">
                          +
                        </Text>
                      </Pressable>
                    </View>
                  )}
                </View>
              );
            })}
          </ScrollView>

          {/* Summary + continue */}
          <View className="mt-4 gap-4 border-t border-border pt-4">
            <View className="flex-row items-center justify-between rounded-2xl border border-primary/20 bg-primary/10 px-6 py-4">
              <Text className="text-base font-semibold text-primary">
                {t("kiosk.split_by_items_your_total")}
              </Text>
              <Text className="text-3xl font-extrabold text-primary">
                {formatCurrency(billTotal, currency)}
              </Text>
            </View>

            <Pressable
              onPress={handleContinue}
              disabled={!canContinue}
              className={[
                "flex-row items-center justify-center gap-3 rounded-2xl py-5",
                canContinue ? "bg-primary" : "bg-muted",
              ].join(" ")}
            >
              <Text
                className={[
                  "text-xl font-bold",
                  canContinue
                    ? "text-primary-foreground"
                    : "text-muted-foreground",
                ].join(" ")}
              >
                {selectedUnits > 0
                  ? t("kiosk.split_continue")
                  : t("kiosk.split_by_items_select_prompt")}
              </Text>
              {canContinue && (
                <Text className="text-xl text-primary-foreground">{"→"}</Text>
              )}
            </Pressable>
            {isLoading && (
              <Text className="text-center text-xs text-muted-foreground">
                {t("kiosk.split_by_items_loading")}
              </Text>
            )}
          </View>
        </View>
      </View>
    </View>
  );
}
