// app/split/success.tsx — Screen 13: Split payment success per person
import { useState, useEffect, useCallback } from "react";
import { Pressable, View } from "react-native";
import { useRouter } from "expo-router";
import { Text } from "@godxjp/ui-native";
import { SuccessRing } from "../../src/components/ui/success-ring";
import { SplitScreenShell } from "../../src/components/ui/split-screen-shell";
import { useTranslation } from "../../src/providers/app-provider";
import { usePaymentFlow } from "../../src/providers/payment-flow-provider";
import { useReceiptPrint } from "../../src/hooks/use-receipt-print";
import { usePrintErrorDetail } from "../../src/hooks/use-print-error";
import { useAutoPrintStatus } from "../../src/hooks/use-auto-print-status";
import { formatCurrency } from "../../src/lib/format";

const AUTO_SECONDS = 8;

export default function SplitSuccessScreen() {
  const router = useRouter();
  const { t } = useTranslation();
  const {
    state,
    remainingAmount,
    perPersonAmount,
    isComplete,
    nextPerson,
    reset,
  } = usePaymentFlow();
  const printer = useReceiptPrint();
  const currency = state.order?.currency;
  const orderId = state.order?.id ?? "";
  const autoPrint = useAutoPrintStatus(orderId);
  const showPrintError =
    printer.status === "error" ||
    (autoPrint.failed && printer.status === "idle");
  const printErrorDetail = usePrintErrorDetail(printer, autoPrint.reason);
  // Split-even people paid this session = currentPersonIndex + 1 (the person who
  // just paid, before nextPerson advances). NOT payments.length, which includes
  // the prior-paid placeholder / other-method payments.
  const paidCount = state.currentPersonIndex + 1;
  const [countdown, setCountdown] = useState(AUTO_SECONDS);

  const lastPayment = state.payments[state.payments.length - 1];

  useEffect(() => {
    setCountdown(AUTO_SECONDS);
  }, [paidCount]);

  // The workstation is the single print authority: it prints each sub-bill's
  // receipt when the paid order syncs down from Cloud (workstation issue #456).
  // The kiosk must NOT drive its own print — that duplicated the slip (immediate
  // kiosk-driven print + delayed workstation sync-down print). Reprint on-demand.

  useEffect(() => {
    if (countdown <= 0) {
      if (isComplete) {
        reset();
        router.replace("/advertise");
      } else {
        nextPerson();
        router.replace("/split/people");
      }
      return;
    }
    const timer = setTimeout(() => setCountdown((c) => c - 1), 1000);
    return () => clearTimeout(timer);
  }, [countdown, isComplete, nextPerson, reset, router]);

  const handleNextPerson = useCallback(() => {
    setCountdown(999);
    nextPerson();
    router.replace("/split/people");
  }, [nextPerson, router]);

  const handleFinish = useCallback(() => {
    reset();
    router.replace("/advertise");
  }, [reset, router]);

  const handlePrint = useCallback(() => {
    if (!orderId) return;
    printer.print(orderId);
  }, [orderId, printer]);

  const printButtonLabel =
    printer.status === "printing"
      ? t("kiosk.success_printing")
      : printer.status === "success"
        ? t("kiosk.success_reprint")
        : t("kiosk.receipt_print");

  return (
    <SplitScreenShell step={2}>
      <View className="flex-1 items-center justify-center gap-5 px-10">
        <SuccessRing />

        <View className="items-center">
          <Text className="text-xs font-semibold uppercase tracking-[0.22em] text-primary">
            {t("kiosk.split_success_title")}
          </Text>
          <Text className="mt-2 text-5xl font-extrabold tracking-tight text-foreground">
            {t("kiosk.split_success_person", {
              paid: String(paidCount),
              total: String(state.numberOfPeople),
            })}
          </Text>
          <Text className="mt-2 text-lg text-muted-foreground">
            {t("kiosk.split_success_paid", {
              amount: formatCurrency(perPersonAmount, currency),
            })}
          </Text>
        </View>

        {/* Receipt mini */}
        {lastPayment && (
          <View className="w-full max-w-[560px] flex-row gap-4 rounded-2xl border border-border bg-muted/30 p-5">
            <View className="flex-1">
              <Text className="text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                {t("kiosk.receipt_tx_id")}
              </Text>
              <Text className="mt-1 text-sm font-bold text-foreground">
                {lastPayment.reference_no}
              </Text>
            </View>
            <View className="flex-1">
              <Text className="text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                {t("kiosk.receipt_remaining")}
              </Text>
              <Text
                className={[
                  "mt-1 text-base font-extrabold",
                  remainingAmount > 0 ? "text-warning" : "text-primary",
                ].join(" ")}
              >
                {formatCurrency(remainingAmount, currency)}
              </Text>
            </View>
          </View>
        )}

        {/* Print status banner */}
        {printer.status === "success" && (
          <View className="rounded-xl bg-success/10 px-4 py-2">
            <Text className="text-sm font-medium text-success">
              {t("kiosk.success_print_success")}
            </Text>
          </View>
        )}
        {showPrintError && (
          <View className="w-full max-w-[560px] rounded-2xl border-2 border-destructive bg-destructive/10 px-5 py-4">
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
        <View className="w-full max-w-[560px] flex-row gap-4">
          <Pressable
            onPress={handlePrint}
            disabled={printer.status === "printing"}
            className="flex-1 flex-row items-center justify-center gap-2 rounded-2xl border-[1.5px] border-foreground bg-card py-5 disabled:opacity-50"
          >
            <Text className="text-lg font-bold text-foreground">
              {printButtonLabel}
            </Text>
          </Pressable>
          {isComplete ? (
            <Pressable
              onPress={handleFinish}
              className="flex-1 flex-row items-center justify-center gap-2 rounded-2xl bg-primary py-5"
            >
              <Text className="text-lg font-bold text-primary-foreground">
                {t("kiosk.split_success_finish")}
              </Text>
              <Text className="text-lg text-primary-foreground">{"→"}</Text>
            </Pressable>
          ) : (
            <Pressable
              onPress={handleNextPerson}
              className="flex-[1.5] flex-row items-center justify-center gap-2 rounded-2xl bg-primary py-5"
            >
              <Text className="text-lg font-bold text-primary-foreground">
                {t("kiosk.split_success_next")}
              </Text>
              <Text className="text-lg text-primary-foreground">{"→"}</Text>
            </Pressable>
          )}
        </View>

        <Text className="text-sm text-muted-foreground">
          {isComplete
            ? t("kiosk.split_success_auto_home", {
                seconds: String(countdown),
              })
            : t("kiosk.split_success_auto_next", {
                seconds: String(countdown),
              })}
        </Text>
      </View>

    </SplitScreenShell>
  );
}
