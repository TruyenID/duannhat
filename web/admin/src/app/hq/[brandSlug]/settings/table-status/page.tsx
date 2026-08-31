"use client";

import { useParams } from "next/navigation";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useTranslation } from "@/providers/app-provider";
import { BrandTableStatusTab } from "../components/brand-table-status-tab";
import { SettingsTabsNav } from "../components/settings-tabs-nav";

export default function HqSettingsTableStatusPage() {
  const params = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  return (
    <>
      <PageHeader
        title={t("hq.brand.settings.table_status.tab_label")}
        description={t("hq.brand.settings.table_status.section_title")}
      />

      <PageContent>
        <SettingsTabsNav brandSlug={params.brandSlug} />
        <BrandTableStatusTab brandSlug={params.brandSlug} />
      </PageContent>
    </>
  );
}
