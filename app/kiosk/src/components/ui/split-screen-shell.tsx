// src/components/ui/split-screen-shell.tsx
// Shared 3-column shell for the payment / split flow: TopBar + StepRibbon on
// top, the order-view bill on the left 1/3, and the screen's own content on the
// right 2/3. Drop it around any flow screen so the order view stays pinned
// across full / split-even / custom / by-items.
import type { ReactNode } from "react";
import { View } from "react-native";
import { TopBar } from "./top-bar";
import { StepRibbon } from "./step-ribbon";
import { SplitBillSidebar } from "./split-bill-sidebar";

interface SplitScreenShellProps {
  children: ReactNode;
  showBack?: boolean;
  onBack?: () => void;
  step?: number;
}

export function SplitScreenShell({
  children,
  showBack,
  onBack,
  step = 1,
}: SplitScreenShellProps) {
  return (
    <View className="flex-1 bg-card">
      <TopBar showBack={showBack} onBack={onBack} />
      <StepRibbon step={step} />
      <View className="flex-1 flex-row">
        <SplitBillSidebar />
        <View className="flex-1">{children}</View>
      </View>
    </View>
  );
}
