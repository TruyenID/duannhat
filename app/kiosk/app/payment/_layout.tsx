// app/payment/_layout.tsx — Full-screen layout (redesigned)
import { useEffect } from "react";
import { View } from "react-native";
import { Slot, useRouter, useSegments } from "expo-router";
import { TopBar } from "../../src/components/ui/top-bar";
import { StepRibbon } from "../../src/components/ui/step-ribbon";
import { SplitBillSidebar } from "../../src/components/ui/split-bill-sidebar";
import { usePaymentFlow } from "../../src/providers/payment-flow-provider";
import { useEffectivePaymentOptions } from "../../src/hooks/use-effective-payment-options";
import { isRouteAllowed } from "../../src/lib/payment-option-utils";
import type { PaymentMethod } from "../../src/types/kiosk";

const ROUTE_SLUGS: PaymentMethod[] = ["cash", "card", "qr", "emoney"];

function isPaymentRouteSlug(value: string): value is PaymentMethod {
  return ROUTE_SLUGS.includes(value as PaymentMethod);
}

export default function PaymentLayout() {
  const { state } = usePaymentFlow();
  const router = useRouter();
  const segments = useSegments();
  const { tiles, isLoading, isEmpty } = useEffectivePaymentOptions();
  const showBill = state.order != null;

  const routeSlug = segments[segments.length - 1] ?? "";

  useEffect(() => {
    if (!isPaymentRouteSlug(routeSlug) || isLoading) return;

    const selectedRoute = state.selectedOption?.route;
    const allowed =
      selectedRoute === routeSlug && isRouteAllowed(routeSlug, tiles);

    if (!allowed || isEmpty) {
      router.replace("/payment-method");
    }
  }, [
    routeSlug,
    isLoading,
    isEmpty,
    tiles,
    state.selectedOption,
    router,
  ]);

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
