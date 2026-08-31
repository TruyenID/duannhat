"use client";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { useTranslation } from "@/providers/app-provider";
import { PaymentsSettingsNav } from "./payments-settings-nav";

export interface PaymentsPageShellProps {
  shopSlug: string;
  title?: string;
  description?: string;
  onRefresh?: () => void;
  isRefreshing?: boolean;
  children: React.ReactNode;
}

export function PaymentsPageShell({
  shopSlug,
  title,
  description,
  onRefresh,
  isRefreshing,
  children,
}: PaymentsPageShellProps) {
  const { t } = useTranslation();

  return (
    <>
      <PageHeader
        title={title ?? t("shop.payments.title")}
        description={description ?? t("shop.payments.subtitle")}
        onRefresh={onRefresh}
        isRefreshing={isRefreshing}
      />
      <PageContent>
        <div
          data-slot="payments-page-shell"
          className="flex flex-col gap-6 md:flex-row md:items-start"
        >
          <PaymentsSettingsNav shopSlug={shopSlug} />
          <div className="min-w-0 flex-1">{children}</div>
        </div>
      </PageContent>
    </>
  );
}
