"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/providers/app-provider";

interface SettingsTabsNavProps {
  brandSlug: string;
}

export function SettingsTabsNav({ brandSlug }: SettingsTabsNavProps) {
  const { t } = useTranslation();
  const pathname = usePathname();
  const base = `/hq/${brandSlug}/settings`;

  const tabs = [
    { href: `${base}/payments`, label: t("hq.payments.title") },
    { href: `${base}/reverb`, label: t("hq.reverb.tab") },
    { href: `${base}/cart-timeout`, label: t("hq.brand.settings.timeout.tab_label") },
    { href: `${base}/takeaway-payment`, label: t("hq.brand.settings.takeaway_payment.tab_label") },
    { href: `${base}/table-status`, label: t("hq.brand.settings.table_status.tab_label") },
    // #2937 — ngưỡng lệch tiền mặt. Đứng cạnh các tab chính sách quán chứ
    // không trong `payments`: `payments` là cấu hình CỔNG, cái này là tiền mặt
    // trong két.
    { href: `${base}/cash-variance`, label: t("hq.brand.settings.cash_variance.tab_label") },
    { href: `${base}/print-locale`, label: t("hq.brand.settings.print_locale.tab_label") },
    { href: `${base}/customer`, label: t("hq.brand.settings.customer.tab_label") },
    { href: `${base}/point-earn`, label: t("hq.brand.settings.point_earn.tab_label") },
    { href: `${base}/tier-card`, label: t("hq.brand.settings.tier_card.tab_label") },
    { href: `${base}/vn-einvoice`, label: t("hq.brand.settings.vn_einvoice.tab_label") },
    { href: `${base}/readiness`, label: t("hq.brand.settings.readiness.tab_label") },
  ];

  return (
    <div
      data-slot="settings-tabs-nav"
      className="inline-flex h-9 w-fit items-center justify-center rounded-lg bg-muted p-1 text-muted-foreground"
    >
      {tabs.map((tab) => {
        const isActive = pathname === tab.href || pathname.startsWith(`${tab.href}/`);
        return (
          <Link
            key={tab.href}
            href={tab.href}
            data-active={isActive ? "" : undefined}
            className={cn(
              "inline-flex h-7 items-center justify-center rounded-md px-3 text-xs font-medium transition-all",
              "focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none",
              isActive ? "bg-background text-foreground shadow-sm" : "hover:text-foreground/80"
            )}
          >
            {tab.label}
          </Link>
        );
      })}
    </div>
  );
}
