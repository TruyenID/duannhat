"use client";

import { useParams } from "next/navigation";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useTranslation } from "@/providers/app-provider";
import { BrandReadinessTab } from "../components/brand-readiness-tab";
import { SettingsTabsNav } from "../components/settings-tabs-nav";

export default function HqSettingsReadinessPage() {
  const params = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  return (
    <>
      <PageHeader
        title={t("hq.brand.settings.readiness.tab_label")}
        description={t("hq.brand.settings.readiness.section_description")}
      />

      <PageContent>
        <SettingsTabsNav brandSlug={params.brandSlug} />
        <BrandReadinessTab brandSlug={params.brandSlug} />
      </PageContent>
    </>
  );
}
