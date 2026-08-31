"use client";

import { Clock } from "lucide-react";
import { useTranslations } from "next-intl";

import { useCurrentBranchOpenState } from "@/hooks/use-branch-open-state";
import { cn } from "@/lib/utils";

/**
 * #1167 — the DINE-IN heads-up: "we're past closing time, ask a staff member".
 *
 * Advisory on purpose. The customer is sitting in the restaurant with the QR
 * code in front of them, so a party still at the table after closing keeps its
 * menu and keeps ordering — last orders are the staff's call, not the
 * schedule's. Take-away is the opposite: there the menu is replaced outright
 * by ShopClosedScreen.
 *
 * Renders nothing while the shop is open, or when it publishes no
 * `weekly_hours` at all (fail-open — see useBranchOpenState). Re-evaluates on
 * the hook's tick, so a tab left open across closing time grows this on its own.
 */
export default function ShopClosedNotice({ className }: { className?: string }) {
  const t = useTranslations("shop");
  const { isOpen } = useCurrentBranchOpenState();

  if (isOpen) return null;

  return (
    <div className={cn("mx-auto w-full max-w-7xl px-4 pt-3 md:px-6", className)}>
      <div
        role="status"
        className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-3.5 text-amber-800"
      >
        <Clock className="mt-0.5 h-5 w-5 shrink-0" />
        <div className="flex-1 space-y-0.5">
          <p className="text-sm font-semibold">{t("closedNoticeTitle")}</p>
          <p className="text-xs leading-relaxed">{t("closedNoticeDineIn")}</p>
        </div>
      </div>
    </div>
  );
}
