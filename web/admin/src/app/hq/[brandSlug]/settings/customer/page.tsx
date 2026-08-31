"use client";

import { useParams } from "next/navigation";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { useTranslation } from "@/providers/app-provider";
import { BrandCustomerSettingsTab } from "../components/brand-customer-settings-tab";
import { SettingsTabsNav } from "../components/settings-tabs-nav";

export default function HqSettingsCustomerPage() {
  const params = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  return (
    <>
      <PageHeader
        title={t("hq.brand.settings.customer.tab_label")}
        description={t("hq.brand.settings.customer.section_title")}
      />

      <PageContent>
        <SettingsTabsNav brandSlug={params.brandSlug} />
        <BrandCustomerSettingsTab brandSlug={params.brandSlug} />
      </PageContent>
    </>
  );
}
