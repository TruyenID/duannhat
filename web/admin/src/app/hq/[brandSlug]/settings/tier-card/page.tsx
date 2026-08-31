"use client";

import { useParams } from "next/navigation";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useTranslation } from "@/providers/app-provider";
import { BrandTierCardTab } from "../components/brand-tier-card-tab";
import { SettingsTabsNav } from "../components/settings-tabs-nav";

export default function HqSettingsTierCardPage() {
  const params = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  return (
    <>
      <PageHeader
        title={t("hq.brand.settings.tier_card.tab_label")}
        description={t("hq.brand.settings.tier_card.section_title")}
      />

      <PageContent>
        <SettingsTabsNav brandSlug={params.brandSlug} />
        <BrandTierCardTab brandSlug={params.brandSlug} />
      </PageContent>
    </>
  );
}
