// app/success.tsx — Success screen with auto-print receipt
import { useEffect, useState, useCallback, useMemo } from "react";
import { Pressable, View } from "react-native";
import { useLocalSearchParams, useRouter } from "expo-router";
import { Text } from "@godxjp/ui-native";
import { SplitScreenShell } from "../src/components/ui/split-screen-shell";
import { SuccessRing } from "../src/components/ui/success-ring";
import { useReceiptPrint } from "../src/hooks/use-receipt-print";
import { useAutoPrintStatus } from "../src/hooks/use-auto-print-status";
import { useTranslation } from "../src/providers/app-provider";
import { usePaymentFlow } from "../src/providers/payment-flow-provider";
import { formatCurrency } from "../src/lib/format";
import type { PaymentMethod } from "../src/types/kiosk";

const AUTO_REDIRECT_SECONDS = 30;

const PAYMENT_LABEL_KEYS: Record<PaymentMethod, string> = {
  cash: "kiosk.payment_method.cash",
  qr: "kiosk.payment_method.qr",
  card: "kiosk.payment_method.card",
  emoney: "kiosk.payment_method.emoney",
};

function isPaymentMethod(value: string): value is PaymentMethod {
  return ["cash", "qr", "card", "emoney"].includes(value);
}

export default function SuccessScreen() {
  const { amountPaid, paymentMethod, referenceNo, currency } =
    useLocalSearchParams<{
      tableId: string;
      amountPaid: string;
      cashTendered: string;
      paymentMethod: string;
      referenceNo: string;
      currency: string;
    }>();
  const router = useRouter();
  const { t } = useTranslation();
  const { state, reset } = usePaymentFlow();
  const printer = useReceiptPrint();
  const orderId = state.order?.id ?? "";
  const autoPrint = useAutoPrintStatus(orderId);
  const [secondsLeft, setSecondsLeft] = useState(AUTO_REDIRECT_SECONDS);

  // Show the print-error banner when either the manual reprint failed, OR the
  // workstation reported its confirm-time auto-print failed (and the user hasn't
  // started a manual reprint that would override it).
  const showPrintError =
    printer.status === "error" ||
    (autoPrint.failed && printer.status === "idle");
  const printErrorDetail = printer.error ?? autoPrint.reason;

  const paymentMethodLabel = useMemo(() => {
    const key = isPaymentMethod(paymentMethod ?? "")
      ? PAYMENT_LABEL_KEYS[paymentMethod as PaymentMethod]
      : "";
    return key ? t(key) : (paymentMethod ?? "—");
  }, [paymentMethod, t]);

  const goHome = useCallback(() => {
    reset();
    router.replace("/advertise");
  }, [reset, router]);

  // Counter workflow: pay the NEXT customer's bill right away instead of waiting
  // out the idle countdown. Reset the payment-flow state, then jump straight to
  // the scan / order-code entry screen.
  const payAnother = useCallback(() => {
    reset();
    router.replace("/select-table");
  }, [reset, router]);

  const handlePrintReceipt = useCallback(() => {
    if (!orderId) return;
    printer.print(orderId);
  }, [orderId, printer]);

  // The workstation is the single print authority — it auto-prints the slip on
  // payment confirm. The kiosk must NOT also auto-print here, or every receipt
  // comes out twice. Re-printing is on-demand only via the button below.

  useEffect(() => {
    if (secondsLeft <= 0) {
      goHome();
      return;
    }
    const interval = setInterval(() => setSecondsLeft((s) => s - 1), 1000);
    return () => clearInterval(interval);
  }, [secondsLeft, goHome]);

  const now = new Date().toLocaleString("ja-JP", {
    hour: "2-digit",
    minute: "2-digit",
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  });

  const printButtonLabel =
    printer.status === "printing"
      ? t("kiosk.success_printing")
      : printer.status === "success"
        ? t("kiosk.success_reprint")
        : t("kiosk.receipt_print");

  return (
    <SplitScreenShell step={2}>
      <View className="flex-1 items-center justify-center gap-6 px-10">
        <SuccessRing />

        <View className="items-center">
          <Text className="text-xs font-semibold uppercase tracking-[0.22em] text-primary">
            {t("kiosk.success_thank_you")}
          </Text>
          <Text className="mt-3 text-center text-5xl font-extrabold leading-tight tracking-tight text-foreground">
            {t("kiosk.success_complete_title")}
          </Text>
          <Text className="mt-3 max-w-[600px] text-center text-base leading-relaxed text-muted-foreground">
            {t("kiosk.success_complete_sub")}
          </Text>
        </View>

        {/* Receipt card */}
        <View className="w-full max-w-[700px] rounded-3xl border border-border bg-card p-8">
          <View className="mb-5 flex-row items-baseline justify-between">
            <Text className="text-base font-bold text-foreground">
              {t("kiosk.receipt_title")}
            </Text>
            <Text className="text-xs text-muted-foreground">{now}</Text>
          </View>
          <View className="flex-row flex-wrap gap-5">
            <ReceiptStat
              label={t("kiosk.receipt_method")}
              value={paymentMethodLabel}
            />
            <ReceiptStat
              label={t("kiosk.receipt_tx_id")}
              value={referenceNo ?? "—"}
              mono
            />
            <ReceiptStat
              label={t("kiosk.receipt_total_paid")}
              value={formatCurrency(Number(amountPaid ?? 0), currency)}
              accent
            />
          </View>
          <View className="my-5 h-px bg-border" />
          <Text className="text-center text-xs text-muted-foreground">
            {t("kiosk.receipt_verified")}
          </Text>
        </View>

        {/* Print status banner */}
        {printer.status === "success" && (
          <View className="rounded-xl bg-success/10 px-4 py-2">
            <Text className="text-sm font-medium text-success">
              {t("kiosk.success_print_success")}
            </Text>
          </View>
        )}
        {showPrintError && (
          <View className="w-full max-w-[700px] rounded-2xl border-2 border-destructive bg-destructive/10 px-5 py-4">
            <Text className="text-base font-bold text-destructive">
              ⚠️ {t("kiosk.success_print_error")}
            </Text>
            {printErrorDetail ? (
              <Text className="mt-1 text-xs text-destructive">{printErrorDetail}</Text>
            ) : null}
            <Text className="mt-1 text-sm text-destructive">
              {t("kiosk.success_print_error_hint")}
            </Text>
          </View>
        )}

        {/* Buttons */}
        <View className="w-full max-w-[700px] gap-4">
          {/* Primary CTA — pay the next customer's bill immediately. */}
          <Pressable
            onPress={payAnother}
            className="flex-row items-center justify-center gap-2 rounded-2xl bg-primary py-5"
          >
            <Text className="text-lg font-bold text-primary-foreground">
              {t("kiosk.success_pay_another")}
            </Text>
            <Text className="text-lg text-primary-foreground">{"→"}</Text>
          </Pressable>
          <View className="flex-row gap-4">
            <Pressable
              onPress={handlePrintReceipt}
              disabled={printer.status === "printing"}
              className="flex-1 flex-row items-center justify-center gap-2 rounded-2xl border-[1.5px] border-foreground bg-card py-5 disabled:opacity-50"
            >
              <Text className="text-lg font-bold text-foreground">
                {printButtonLabel}
              </Text>
            </Pressable>
            <Pressable
              onPress={goHome}
              className="flex-1 flex-row items-center justify-center gap-2 rounded-2xl border-[1.5px] border-foreground bg-card py-5"
            >
              <Text className="text-lg font-bold text-foreground">
                {t("kiosk.success_finish")}
              </Text>
            </Pressable>
          </View>
        </View>

        <Text className="text-sm text-muted-foreground">
          {t("kiosk.success_auto_home", { seconds: String(secondsLeft) })}
        </Text>
      </View>
    </SplitScreenShell>
  );
}

function ReceiptStat({
  label,
  value,
  mono,
  accent,
}: {
  label: string;
  value: string;
  mono?: boolean;
  accent?: boolean;
}) {
  return (
    <View className="min-w-[140px] flex-1">
      <Text className="mb-1 text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground">
        {label}
      </Text>
      <Text
        className={[
          accent ? "text-2xl font-extrabold text-primary" : "text-lg font-bold text-foreground",
          mono ? "font-mono" : "",
        ].join(" ")}
      >
        {value}
      </Text>
    </View>
  );
}
