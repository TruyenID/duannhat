"use client";

import { useParams } from "next/navigation";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useTranslation } from "@/providers/app-provider";
import { BrandCashVarianceTab } from "../components/brand-cash-variance-tab";
import { SettingsTabsNav } from "../components/settings-tabs-nav";

export default function HqSettingsCashVariancePage() {
  const params = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  return (
    <>
      <PageHeader
        title={t("hq.brand.settings.cash_variance.tab_label")}
        description={t("hq.brand.settings.cash_variance.section_title")}
      />

      <PageContent>
        <SettingsTabsNav brandSlug={params.brandSlug} />
        <BrandCashVarianceTab brandSlug={params.brandSlug} />
      </PageContent>
    </>
  );
}
