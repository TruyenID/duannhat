"use client";

import { useParams } from "next/navigation";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useTranslation } from "@/providers/app-provider";
import { BrandPrintLocaleTab } from "../components/brand-print-locale-tab";
import { SettingsTabsNav } from "../components/settings-tabs-nav";

export default function HqSettingsPrintLocalePage() {
  const params = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  return (
    <>
      <PageHeader
        title={t("hq.brand.settings.print_locale.tab_label")}
        description={t("hq.brand.settings.print_locale.section_title")}
      />

      <PageContent>
        <SettingsTabsNav brandSlug={params.brandSlug} />
        <BrandPrintLocaleTab brandSlug={params.brandSlug} />
      </PageContent>
    </>
  );
}
