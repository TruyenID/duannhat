"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/providers/app-provider";

export interface PaymentsSettingsNavProps {
  brandSlug: string;
}

export function PaymentsSettingsNav({ brandSlug }: PaymentsSettingsNavProps) {
  const { t } = useTranslation();
  const pathname = usePathname();
  const base = `/hq/${brandSlug}/settings/payments`;

  const sections = [
    { href: base, label: t("hq.payments.nav.overview"), match: (p: string) => p === base },
    {
      href: `${base}/gateways`,
      label: t("hq.payments.nav.gateways"),
      match: (p: string) => p.startsWith(`${base}/gateways`),
    },
    {
      href: `${base}/methods`,
      label: t("hq.payments.nav.methods"),
      match: (p: string) => p.startsWith(`${base}/methods`),
    },
    {
      href: `${base}/shops`,
      label: t("hq.payments.nav.shops"),
      match: (p: string) => p.startsWith(`${base}/shops`),
    },
    {
      href: `${base}/settlements`,
      label: t("hq.payments.nav.settlements"),
      match: (p: string) => p.startsWith(`${base}/settlements`),
    },
    {
      href: `${base}/tenders`,
      label: t("hq.payments.nav.tenders"),
      match: (p: string) => p.startsWith(`${base}/tenders`),
    },
  ];

  return (
    <nav
      data-slot="payments-settings-nav"
      aria-label={t("hq.payments.nav.aria_label")}
      className="flex flex-col gap-1 md:w-48 md:shrink-0"
    >
      <div className="flex gap-1 overflow-x-auto pb-1 md:hidden">
        {sections.map((section) => {
          const active = section.match(pathname);
          return (
            <Link
              key={section.href}
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

      <div className="hidden flex-col gap-0.5 md:flex">
        {sections.map((section) => {
          const active = section.match(pathname);
          return (
            <Link
              key={section.href}
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

export function PaymentsSettingsShell({
  brandSlug,
  children,
}: {
  brandSlug: string;
  children: React.ReactNode;
}) {
  return (
    <div data-slot="payments-settings-shell" className="flex flex-col gap-4 md:flex-row md:items-start">
      <PaymentsSettingsNav brandSlug={brandSlug} />
      <div className="min-w-0 flex-1">{children}</div>
    </div>
  );
}
