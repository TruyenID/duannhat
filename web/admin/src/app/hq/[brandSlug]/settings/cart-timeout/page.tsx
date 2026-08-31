"use client";

import { useParams } from "next/navigation";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useTranslation } from "@/providers/app-provider";
import { BrandCartTimeoutTab } from "../components/brand-cart-timeout-tab";
import { SettingsTabsNav } from "../components/settings-tabs-nav";

export default function HqSettingsCartTimeoutPage() {
  const params = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  return (
    <>
      <PageHeader
        title={t("hq.brand.settings.timeout.tab_label")}
        description={t("hq.brand.settings.timeout.section_title")}
      />

      <PageContent>
        <SettingsTabsNav brandSlug={params.brandSlug} />
        <BrandCartTimeoutTab brandSlug={params.brandSlug} />
      </PageContent>
    </>
  );
}
