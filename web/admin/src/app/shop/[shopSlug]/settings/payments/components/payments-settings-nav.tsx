"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/providers/app-provider";

export type PaymentsSettingsSection = "ownership" | "connection" | "options" | "devices";

export interface PaymentsSettingsNavProps {
  shopSlug: string;
}

export function PaymentsSettingsNav({ shopSlug }: PaymentsSettingsNavProps) {
  const { t } = useTranslation();
  const pathname = usePathname();
  const base = `/shop/${shopSlug}/settings/payments`;

  const sections: { id: PaymentsSettingsSection; href: string; label: string }[] = [
    { id: "ownership", href: `${base}/ownership`, label: t("shop.payments.nav.ownership") },
    { id: "connection", href: `${base}/connection`, label: t("shop.payments.nav.connection") },
    { id: "options", href: `${base}/options`, label: t("shop.payments.nav.options") },
    { id: "devices", href: `${base}/devices`, label: t("shop.payments.nav.devices") },
  ];

  return (
    <nav
      data-slot="payments-settings-nav"
      aria-label={t("shop.payments.nav.aria_label")}
      className="flex flex-col gap-1 md:w-48 md:shrink-0"
    >
      {/* Mobile: horizontal scroll tabs */}
      <div className="flex gap-1 overflow-x-auto pb-1 md:hidden">
        {sections.map((section) => {
          const active =
            pathname === section.href || pathname.startsWith(`${section.href}/`);
          return (
            <Link
              key={section.id}
              href={section.href}
              aria-current={active ? "page" : undefined}
              className={cn(
                "inline-flex h-8 shrink-0 items-center rounded-md px-3 text-xs font-medium transition-colors",
                "focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none",
                active
                  ? "bg-primary text-primary-foreground"
                  : "bg-muted text-muted-foreground hover:text-foreground"
              )}
            >
              {section.label}
            </Link>
          );
        })}
      </div>

      {/* Desktop: vertical local nav */}
      <div className="hidden flex-col gap-0.5 md:flex">
        {sections.map((section) => {
          const active =
            pathname === section.href || pathname.startsWith(`${section.href}/`);
          return (
            <Link
              key={section.id}
              href={section.href}
              aria-current={active ? "page" : undefined}
              className={cn(
                "rounded-md px-3 py-2 text-sm font-medium transition-colors",
                "focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none",
                active
                  ? "bg-accent text-accent-foreground"
                  : "text-muted-foreground hover:bg-muted/60 hover:text-foreground"
              )}
            >
              {section.label}
            </Link>
          );
        })}
      </div>
    </nav>
  );
}
