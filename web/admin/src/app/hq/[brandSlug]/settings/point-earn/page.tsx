"use client";

import { useParams } from "next/navigation";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useTranslation } from "@/providers/app-provider";
import { BrandPointEarnTab } from "../components/brand-point-earn-tab";
import { SettingsTabsNav } from "../components/settings-tabs-nav";

export default function HqSettingsPointEarnPage() {
  const params = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  return (
    <>
      <PageHeader
        title={t("hq.brand.settings.point_earn.tab_label")}
        description={t("hq.brand.settings.point_earn.section_title")}
      />

      <PageContent>
        <SettingsTabsNav brandSlug={params.brandSlug} />
        <BrandPointEarnTab brandSlug={params.brandSlug} />
      </PageContent>
    </>
  );
}
