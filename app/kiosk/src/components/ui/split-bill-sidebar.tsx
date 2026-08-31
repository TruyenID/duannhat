// src/components/ui/split-bill-sidebar.tsx
// Left 1/3 panel for the payment / split flow: the full order view (items +
// breakdown) plus a live tracker — amount paid, amount remaining, and (for the
// by-items split) items remaining. Self-contained: reads the order + totals
// from the payment-flow context and fetches the by-items preview for the
// remaining-units count, so any flow screen can drop it in with no props.
import { ScrollView, View, Image } from "react-native";
import { Text } from "@godxjp/ui-native";
import { useTranslation } from "../../providers/app-provider";
import { usePaymentFlow } from "../../providers/payment-flow-provider";
import { useSplitByItemsPreview } from "../../hooks/use-split-preview";
import { PaymentProgress } from "./payment-progress";
import { formatCurrency, formatExtraDisplay } from "../../lib/format";
import { itemDisplay } from "../../lib/item-name";

export function SplitBillSidebar() {
  const { t } = useTranslation();
  const { state, totalAmount, paidAmount, remainingAmount } = usePaymentFlow();
  const order = state.order;
  const currency = order?.currency;
  const isByItems = state.splitMode === "by_items";

  // Order-level remaining units (claims summed from committed payments).
  // Only the by-items mode shows the remaining-units chip, so skip the network
  // round-trip entirely for full / split-even / custom.
  const { preview } = useSplitByItemsPreview(state.orderId ?? "", [], isByItems);
  const totalUnits = order?.items.reduce((s, i) => s + i.quantity, 0) ?? 0;
  // Prefer the server-authoritative per-item `remaining_units` on the order
  // (refreshed after each payment); fall back to the by-items preview.
  const orderRemainingUnits = order?.items.some((i) => i.remaining_units != null)
    ? order.items.reduce((s, i) => s + (i.remaining_units ?? i.quantity), 0)
    : null;
  const itemsRemaining =
    orderRemainingUnits ??
    (preview ? preview.items.reduce((s, i) => s + i.units_remaining, 0) : null);

  if (!order) {
    return <View className="w-1/3 max-w-[440px] border-r border-border bg-muted/20" />;
  }

  const taxAmount = order.tax_amount ?? order.tax ?? 0;
  const itemsCount = order.items.reduce((a, it) => a + it.quantity, 0);

  return (
    <View className="w-1/3 max-w-[440px] border-r border-border bg-muted/20 p-6">
      {/* Header */}
      <View className="border-b border-border pb-4">
        <Text className="text-xs font-semibold uppercase tracking-[0.2em] text-muted-foreground">
          {t("kiosk.split_panel_title")}
        </Text>
        {order.table_name ? (
          <Text className="mt-1 text-xl font-extrabold text-foreground">
            {order.table_name}
          </Text>
        ) : null}
      </View>

      {/* Items */}
      <ScrollView
        className="flex-1 py-2"
        showsVerticalScrollIndicator={false}
        contentContainerStyle={{ gap: 4 }}
      >
        {order.items.map((item) => {
          const extrasTotal =
            item.extras?.reduce(
              (a, e) => a + e.price * (e.quantity ?? 1),
              0,
            ) ?? 0;
          const lineTotal = (item.unit_price + extrasTotal) * item.quantity;
          const display = itemDisplay(item);
          return (
            <View
              key={item.id}
              className="flex-row gap-3 border-b border-border/40 py-3"
            >
              {item.image_url ? (
                <Image
                  source={{ uri: item.image_url }}
                  className="h-12 w-12 rounded-lg"
                  resizeMode="cover"
                />
              ) : (
                <View className="h-12 w-12 rounded-lg bg-muted" />
              )}
              <View className="flex-1">
                <Text className="text-sm font-bold text-foreground">
                  {display.title}
                </Text>
                {display.variant && (
                  <Text className="text-[11px] text-muted-foreground">
                    {display.variant}
                  </Text>
                )}
                {item.extras?.map((e, i) => {
                  const fmt = formatExtraDisplay(e, currency);
                  return (
                    <Text
                      key={i}
                      className={
                        fmt.isRemove
                          ? "text-[11px] font-semibold text-destructive"
                          : "text-[11px] text-muted-foreground"
                      }
                    >
                      {fmt.sign} {fmt.label}
                      {fmt.price !== null ? `  ${fmt.price}` : ""}
                    </Text>
                  );
                })}
                <Text className="mt-1 text-xs text-muted-foreground">
                  {item.quantity} ×{" "}
                  {formatCurrency(item.unit_price + extrasTotal, currency)}
                </Text>
              </View>
              <Text className="text-sm font-bold text-foreground">
                {formatCurrency(lineTotal, currency)}
              </Text>
            </View>
          );
        })}
      </ScrollView>

      {/* Breakdown */}
      <View className="gap-1.5 border-t border-border py-4">
        <SumRow
          label={t("kiosk.bill_subtotal", { count: String(itemsCount) })}
          value={formatCurrency(order.subtotal, currency)}
        />
        {order.service_charge != null && order.service_charge > 0 && (
          <SumRow
            // Read rate from BE (matches /api/v1/kiosk/orders payload) so
            // the displayed % always mirrors what `shop_order_settings`
            // actually applied. Previous hardcode "5" silently drifted
            // whenever ops adjusted the rate.
            label={t("kiosk.bill_service_charge", {
              rate: order.service_charge_rate != null ? String(order.service_charge_rate) : "",
            })}
            value={formatCurrency(order.service_charge, currency)}
          />
        )}
        {taxAmount > 0 && (
          <SumRow
            // Same story for tax — kiosk used to render "8%" while
            // customer-web read the real 10% from the same BE column.
            label={t("kiosk.bill_tax", {
              rate: order.tax_rate != null ? String(order.tax_rate) : "",
            })}
            value={formatCurrency(taxAmount, currency)}
          />
        )}
        {order.discount > 0 && (
          <SumRow
            label={t("kiosk.order_discount")}
            value={`-${formatCurrency(order.discount, currency)}`}
            accent
          />
        )}
        <View className="my-1 h-px bg-border" />
        <View className="flex-row items-baseline justify-between">
          <Text className="text-base font-extrabold uppercase text-foreground">
            {t("kiosk.bill_total")}
          </Text>
          <Text className="text-2xl font-extrabold text-foreground">
            {formatCurrency(totalAmount, currency)}
          </Text>
        </View>
      </View>

      {/* Live tracker */}
      <View className="gap-3 border-t border-border pt-4">
        <PaymentProgress
          total={totalAmount}
          paid={paidAmount}
          currency={currency}
        />
        <View className="flex-row gap-3">
          <StatChip
            label={t("kiosk.custom_paid_so_far")}
            value={formatCurrency(paidAmount, currency)}
          />
          <StatChip
            label={t("kiosk.custom_remaining")}
            value={formatCurrency(remainingAmount, currency)}
            highlight
          />
        </View>
        {isByItems && (
          <StatChip
            label={t("kiosk.split_items_remaining")}
            value={
              itemsRemaining === null
                ? "—"
                : `${itemsRemaining} / ${totalUnits}`
            }
            full
          />
        )}
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
      <Text className="text-xs text-muted-foreground">{label}</Text>
      <Text
        className={[
          "text-xs font-bold",
          accent ? "text-primary" : "text-foreground",
        ].join(" ")}
      >
        {value}
      </Text>
    </View>
  );
}

function StatChip({
  label,
  value,
  highlight,
  full,
}: {
  label: string;
  value: string;
  highlight?: boolean;
  full?: boolean;
}) {
  return (
    <View
      className={[
        full ? "w-full" : "flex-1",
        "rounded-xl border px-4 py-3",
        highlight ? "border-primary/20 bg-primary/10" : "border-border bg-card",
      ].join(" ")}
    >
      <Text
        className={[
          "text-[11px] font-semibold uppercase tracking-wide",
          highlight ? "text-primary" : "text-muted-foreground",
        ].join(" ")}
      >
        {label}
      </Text>
      <Text
        className={[
          "mt-1 text-lg font-extrabold",
          highlight ? "text-primary" : "text-foreground",
        ].join(" ")}
      >
        {value}
      </Text>
    </View>
  );
}
