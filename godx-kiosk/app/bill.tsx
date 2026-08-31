// app/bill.tsx — Screen 2: Bill review
import { useEffect, useState } from "react";
import { Pressable, ScrollView, View, Image, TextInput } from "react-native";
import { useLocalSearchParams, useRouter } from "expo-router";
import { Skeleton, Text } from "@godxjp/ui-native";
import { TopBar } from "../src/components/ui/top-bar";
import { StepRibbon } from "../src/components/ui/step-ribbon";
import { useOrder, useOrderByCode } from "../src/hooks/use-order";
import { useReceiptPrint } from "../src/hooks/use-receipt-print";
import { useLocale } from "../src/providers/app-provider";
import { usePaymentFlow } from "../src/providers/payment-flow-provider";
import { formatCurrency, formatExtraDisplay } from "../src/lib/format";
import { itemDisplay } from "../src/lib/item-name";
import { fetchSplitByItemsPreview } from "../src/lib/api";

export default function BillScreen() {
  const { tableId, orderCode, partialAmount, items: itemsParam } = useLocalSearchParams<{
    tableId?: string;
    orderCode?: string;
    /** When set, kiosk skips the bill-review + share-bill chooser and
     * routes straight to /custom/amount with this value pre-filled.
     * Source: customer-web "Tùy chọn" QR encodes the chosen amount so
     * the customer's number reaches the kiosk in one round-trip. */
    partialAmount?: string;
    /** JSON-encoded `[{id, qty}]` carrying the customer-web by_items
     * selection. When present we mark the hand-off as by_items so the
     * pay-flow forwards split_mode=by_items + item_allocations to BE
     * (formatOrder's claimedByItem aggregates this → customer-web's
     * by_items panel disables the just-paid item in real time). */
    items?: string;
  }>();
  const router = useRouter();
  const { t } = useLocale();
  const tableResult = useOrder(tableId ?? "");
  const codeResult = useOrderByCode(orderCode ?? "");
  const { order, isLoading, error } = orderCode ? codeResult : tableResult;
  const {
    setOrder,
    setSplitMode,
    setNumberOfPeople,
    setCurrentAmount,
    setPayAllRemaining,
    setItemAllocations,
  } = usePaymentFlow();
  const [coupon, setCoupon] = useState("");
  const printer = useReceiptPrint();

  // Already fully paid → block re-entry into ANY payment flow. `setOrder()` is
  // only ever called here, so bill.tsx is the single funnel into custom / split
  // / payment — guarding here (BEFORE the partial-pay redirect below) covers
  // every entry path, including the customer-web "Tùy chọn" QR that otherwise
  // jumps straight to /custom/method and skipped the paid check entirely.
  const isAlreadyPaid =
    !!order &&
    order.total > 0 &&
    (order.remaining_amount ??
      Math.max(0, order.total - (order.paid_amount ?? 0))) <= 0;

  // Customer-web "Tùy chọn" / "Chia đều" / "Chia theo món" → counter-pay
  // hand-off. Skip the bill review + share-bill chooser (customer already
  // chose on customer-web), jump straight to /custom/method so staff picks
  // the payment method, then /payment/<method> submits. Bill.tsx itself
  // never submits — single source of truth per method.
  //
  // by_items branch: when QR carries items[], we additionally seed
  // state.itemAllocations + flip splitMode to 'by_items' so the
  // /payment/<method> screen builds metadata with item_allocations. That
  // is what BE persists → formatOrder aggregates → customer-web's by_items
  // panel marks the item as "đã thanh toán" via realtime refetch.
  const partialAmountN = partialAmount != null ? Number(partialAmount) : NaN;
  const isPartialPayFlow =
    Number.isFinite(partialAmountN) && partialAmountN > 0;
  const partialAmountRounded = isPartialPayFlow ? Math.round(partialAmountN) : 0;
  // Parse items JSON ONCE per param change so the effect deps remain stable.
  const parsedItems = (() => {
    if (!itemsParam) return null;
    try {
      const raw = JSON.parse(itemsParam);
      if (!Array.isArray(raw)) return null;
      const cleaned = raw
        .map((row: unknown) => {
          if (typeof row !== "object" || row === null) return null;
          const r = row as { id?: unknown; qty?: unknown };
          const id = typeof r.id === "string" ? r.id : null;
          const qty = Number(r.qty);
          return id && Number.isFinite(qty) && qty > 0
            ? { item_id: id, units: Math.floor(qty) }
            : null;
        })
        .filter((x): x is { item_id: string; units: number } => x !== null);
      return cleaned.length > 0 ? cleaned : null;
    } catch {
      return null;
    }
  })();
  const itemsKey = parsedItems ? JSON.stringify(parsedItems) : "";
  useEffect(() => {
    if (!order || !isPartialPayFlow || isAlreadyPaid) return;
    let cancelled = false;
    (async () => {
      setOrder(order);
      setPayAllRemaining(false);
      if (parsedItems) {
        setSplitMode("by_items");
        // The QR carries the items' RAW subtotal (e.g. ¥1,997.50). But BE
        // spreads the order's tax / service-charge across each line, so the
        // item's true share is higher (e.g. ¥2,298). OrderPaymentService
        // rejects a by_items payment whose amount disagrees with the
        // server-recomputed total (`split_by_items_total_mismatch` → 422 →
        // counter-pay "Thanh toán thất bại"). Fetch the authoritative preview
        // total for these allocations and pay THAT, not the QR number.
        let total = partialAmountRounded;
        try {
          const preview = await fetchSplitByItemsPreview(order.id, parsedItems);
          const t = Number(preview?.preview_bills?.[0]?.total);
          if (Number.isFinite(t) && t > 0) total = Math.round(t);
        } catch {
          // Preview unreachable → fall back to the QR amount. BE may still
          // 422, but that's strictly no worse than before this fix.
        }
        if (cancelled) return;
        setItemAllocations(parsedItems, total);
      } else {
        setSplitMode("custom");
        setCurrentAmount(partialAmountRounded);
      }
      if (cancelled) return;
      router.replace("/custom/method");
    })();
    return () => {
      cancelled = true;
    };
  }, [
    order,
    isPartialPayFlow,
    isAlreadyPaid,
    partialAmountRounded,
    itemsKey,
    setOrder,
    setSplitMode,
    setPayAllRemaining,
    setCurrentAmount,
    setItemAllocations,
    router,
    // parsedItems intentionally omitted — represented via stable itemsKey
  ]); // eslint-disable-line react-hooks/exhaustive-deps

  if (isLoading) {
    return (
      <View className="flex-1 items-center justify-center bg-card">
        <Skeleton className="h-6 w-48 rounded-md" />
        <Skeleton className="mt-3 h-4 w-32 rounded-md" />
      </View>
    );
  }

  // Tùy chọn flow: redirect to /custom/method is in-flight in the effect
  // above. Render a thin spinner to avoid flashing the bill-review UI
  // before navigation lands.
  if (order && isPartialPayFlow && !isAlreadyPaid) {
    return (
      <View className="flex-1 items-center justify-center bg-card">
        <Skeleton className="h-6 w-48 rounded-md" />
      </View>
    );
  }

  if (error || !order) {
    return (
      <View className="flex-1 items-center justify-center gap-4 bg-card px-8">
        <Text className="text-center text-lg font-semibold text-destructive">
          {t("kiosk.checkout_not_found")}
        </Text>
        <Text className="text-center text-muted-foreground">
          {error ?? t("kiosk.checkout_not_found_sub")}
        </Text>
        <Pressable
          onPress={() => router.back()}
          className="rounded-xl border border-border px-5 py-3"
        >
          <Text className="font-semibold text-foreground">
            {t("common.back")}
          </Text>
        </Pressable>
      </View>
    );
  }

  // Already fully paid → don't let the customer enter the payment flow again.
  // `isAlreadyPaid` (computed above, before the partial-pay redirect) is the
  // single source of truth so the "Tùy chọn" QR can't skip this check.
  if (isAlreadyPaid) {
    return (
      <View className="flex-1 items-center justify-center gap-4 bg-card px-8">
        <Text className="text-center text-3xl font-extrabold text-primary">
          {t("kiosk.bill_already_paid")}
        </Text>
        <Text className="text-center text-muted-foreground">
          {t("kiosk.bill_already_paid_sub")}
        </Text>

        {/* Lost-bill recovery: the order is paid but the receipt may never have
            printed (terminal LP0 / workstation printer offline). Let staff
            reprint it instead of dead-ending — no way back in otherwise. */}
        {printer.status === "error" && (
          <View className="w-full max-w-[480px] rounded-xl border border-destructive bg-destructive/10 px-4 py-3">
            <Text className="text-center text-sm font-medium text-destructive">
              {t("kiosk.success_print_error")}
              {printer.error ? ` (${printer.error})` : ""}
            </Text>
          </View>
        )}
        {printer.status === "success" && (
          <Text className="text-center text-sm font-medium text-success">
            {t("kiosk.success_print_success")}
          </Text>
        )}

        <View className="mt-2 flex-row gap-3">
          <Pressable
            onPress={() => order.id && printer.print(order.id)}
            disabled={printer.status === "printing" || !order.id}
            className="rounded-xl border-[1.5px] border-foreground px-6 py-3 disabled:opacity-50"
          >
            <Text className="font-semibold text-foreground">
              {printer.status === "printing"
                ? t("kiosk.success_printing")
                : t("kiosk.bill_reprint")}
            </Text>
          </Pressable>
          <Pressable
            onPress={() => router.replace("/advertise")}
            className="rounded-xl bg-primary px-6 py-3"
          >
            <Text className="font-semibold text-primary-foreground">
              {t("kiosk.bill_back_home")}
            </Text>
          </Pressable>
        </View>
      </View>
    );
  }

  const handleContinue = () => {
    setOrder(order);
    // #377 — if customer-web already recorded a split mode, skip the
    // kiosk chooser and route straight into the matching allocation.
    // Maps customer-web vocabulary (by_people/by_items/custom) onto the
    // kiosk's SplitMode union (split_even/by_items/custom).
    if (order.split_mode === "by_people") {
      setSplitMode("split_even");
      // plan-039 follow-up — when customer-web counter-pay UI already
      // pre-declared the headcount, skip /split/people entirely and
      // jump straight to /split/method with that count seeded into
      // payment-flow state. Cashier sees ¥X/người immediately instead
      // of re-entering the count. NULL count → fall back to the
      // chooser as before.
      const preset = order.split_people_count;
      if (typeof preset === "number" && preset >= 2) {
        setNumberOfPeople(preset);
        router.push("/split/method");
        return;
      }
      router.push("/split/people");
      return;
    }
    if (order.split_mode === "by_items") {
      setSplitMode("by_items");
      router.push("/split/items");
      return;
    }
    if (order.split_mode === "custom") {
      setSplitMode("custom");
      router.push("/custom/amount");
      return;
    }
    router.push("/split-options");
  };

  // Defensive: the by-code (takeaway) endpoint may return an order whose items
  // relation isn't populated. Guard so a missing `items` degrades to an empty
  // list instead of crashing the whole app via the ErrorBoundary.
  const items = order.items ?? [];
  const itemsCount = items.reduce((a, it) => a + it.quantity, 0);

  return (
    <View className="flex-1 bg-card">
      <TopBar showBack />
      <StepRibbon step={1} />

      <View className="flex-1 items-center px-10">
        <View className="w-full max-w-[700px] flex-1">
          {/* Header */}
          <View className="flex-row items-baseline justify-between border-b border-border py-5">
            <Text className="text-3xl font-extrabold tracking-tight text-foreground">
              {orderCode
                ? t("kiosk.bill_title_code", { code: orderCode })
                : t("kiosk.bill_title", { table: order.table_name })}
            </Text>
          </View>

          {/* Items list */}
          <ScrollView className="flex-1 py-2" showsVerticalScrollIndicator={false}>
            {items.map((item) => {
              const extrasTotal =
                item.extras?.reduce(
                  (a, e) => a + e.price * (e.quantity ?? 1),
                  0,
                ) ?? 0;
              const display = itemDisplay(item);
              return (
                <View
                  key={item.id}
                  className="flex-row gap-4 border-b border-border/50 py-4"
                >
                  {item.image_url ? (
                    <Image
                      source={{ uri: item.image_url }}
                      className="h-20 w-20 rounded-xl"
                      resizeMode="cover"
                    />
                  ) : (
                    <View className="h-20 w-20 rounded-xl bg-muted" />
                  )}
                  <View className="flex-1">
                    <Text className="text-base font-bold text-foreground">
                      {display.title}
                    </Text>
                    {display.variant && (
                      <Text className="text-xs text-muted-foreground">
                        {display.variant}
                      </Text>
                    )}
                    {item.extras && item.extras.length > 0 && (
                      <View className="mt-1.5 gap-1">
                        {item.extras.map((e, i) => {
                          const fmt = formatExtraDisplay(e, order.currency);
                          return (
                            <View
                              key={i}
                              className="flex-row justify-between"
                            >
                              <Text className="text-xs text-muted-foreground">
                                {fmt.sign} {fmt.label}
                              </Text>
                              {fmt.price !== null && (
                                <Text
                                  className={
                                    fmt.isRemove
                                      ? "text-xs font-semibold text-destructive"
                                      : "text-xs text-muted-foreground"
                                  }
                                >
                                  {fmt.price}
                                </Text>
                              )}
                            </View>
                          );
                        })}
                      </View>
                    )}
                    {item.note ? (
                      <Text className="mt-1.5 text-xs italic text-warning">
                        Note: {item.note}
                      </Text>
                    ) : null}
                    <Text className="mt-2 text-sm font-bold text-foreground">
                      {item.quantity} x{" "}
                      {formatCurrency(
                        item.unit_price + extrasTotal,
                        order.currency,
                      )}
                    </Text>
                  </View>
                </View>
              );
            })}
          </ScrollView>

          {/* Totals + CTA */}
          <View className="gap-2 border-t border-border py-5">
            <SumRow
              label={t("kiosk.bill_subtotal", { count: String(itemsCount) })}
              value={formatCurrency(order.subtotal, order.currency)}
            />
            {order.service_charge != null && order.service_charge > 0 && (
              <SumRow
                // Rate now read from BE (`shop_order_settings.service_charge_rate`)
                // instead of hardcoded "5". Falls back to "" if BE omits it
                // so the label still renders sensibly on legacy responses.
                label={t("kiosk.bill_service_charge", {
                  rate: order.service_charge_rate != null ? String(order.service_charge_rate) : "",
                })}
                value={formatCurrency(order.service_charge, order.currency)}
              />
            )}
            {/* plan-043 audit B-I.1/B-I.2 — the tax mode suffix (税込/税抜)
                tells the customer whether the shown total already contains the
                tax; when the order spans multiple rates the breakdown renders
                one line per rate, else a single tax line. */}
            {(() => {
              const modeSuffix =
                order.is_tax_included == null
                  ? ""
                  : ` · ${order.is_tax_included ? t("kiosk.tax_included") : t("kiosk.tax_excluded")}`;
              const breakdown = (order.tax_breakdown ?? []).filter(
                (b) => (b.tax ?? 0) > 0 || (b.taxable ?? 0) > 0,
              );

              if (breakdown.length > 0) {
                return breakdown.map((b, i) => (
                  <SumRow
                    key={`tax-${b.rate}-${i}`}
                    label={t("kiosk.bill_tax", { rate: String(b.rate) }) + modeSuffix}
                    value={formatCurrency(b.tax, order.currency)}
                  />
                ));
              }

              const taxTotal = order.tax_amount ?? order.tax ?? 0;
              if (taxTotal > 0) {
                return (
                  <SumRow
                    // Rate read from BE (per-line snapshot / branch default) so
                    // it mirrors the customer-web display.
                    label={
                      t("kiosk.bill_tax", {
                        rate: order.tax_rate != null ? String(order.tax_rate) : "",
                      }) + modeSuffix
                    }
                    value={formatCurrency(taxTotal, order.currency)}
                  />
                );
              }
              return null;
            })()}

            {/* Coupon */}
            <View className="mt-1 flex-row gap-3">
              <TextInput
                value={coupon}
                onChangeText={setCoupon}
                placeholder={t("kiosk.bill_coupon_placeholder")}
                className="flex-1 rounded-xl border border-border bg-card px-4 py-3 text-base text-foreground"
                placeholderTextColor="#9CA3AF"
              />
              <Pressable className="items-center justify-center rounded-xl bg-primary px-6">
                <Text className="font-bold text-primary-foreground">
                  {t("kiosk.bill_coupon_apply")}
                </Text>
              </Pressable>
            </View>

            {order.discount > 0 && (
              <SumRow
                label={t("kiosk.order_discount")}
                value={`-${formatCurrency(order.discount, order.currency)}`}
                accent
              />
            )}

            <View className="my-2 h-px bg-border" />
            <View className="flex-row items-baseline justify-between">
              <Text className="text-xl font-extrabold uppercase text-foreground">
                {t("kiosk.bill_total")}
              </Text>
              <Text className="text-3xl font-extrabold text-foreground">
                {formatCurrency(order.total, order.currency)}
              </Text>
            </View>

            <Pressable
              onPress={handleContinue}
              className="mt-2 flex-row items-center justify-center gap-3 rounded-2xl bg-primary py-5"
            >
              <Text className="text-xl font-bold text-primary-foreground">
                {t("kiosk.bill_continue")}
              </Text>
              <Text className="text-xl text-primary-foreground">{"→"}</Text>
            </Pressable>
          </View>
        </View>
      </View>
    </View>
  );
}

function SumRow({
  label,
  value,
  accent,
}: {
  label: string;
  value: string;
  accent?: boolean;
}) {
  return (
    <View className="flex-row items-baseline justify-between">
      <Text className="text-sm text-muted-foreground">{label}</Text>
      <Text
        className={[
          "text-sm font-bold",
          accent ? "text-primary" : "text-foreground",
        ].join(" ")}
      >
        {value}
      </Text>
    </View>
  );
}
