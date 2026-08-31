"use client";

import { useParams } from "next/navigation";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useTranslation } from "@/providers/app-provider";
import { BrandTakeawayPaymentTab } from "../components/brand-takeaway-payment-tab";
import { SettingsTabsNav } from "../components/settings-tabs-nav";

export default function HqSettingsTakeawayPaymentPage() {
  const params = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  return (
    <>
      <PageHeader
        title={t("hq.brand.settings.takeaway_payment.tab_label")}
        description={t("hq.brand.settings.takeaway_payment.section_title")}
      />

      <PageContent>
        <SettingsTabsNav brandSlug={params.brandSlug} />
        <BrandTakeawayPaymentTab brandSlug={params.brandSlug} />
      </PageContent>
    </>
  );
}
