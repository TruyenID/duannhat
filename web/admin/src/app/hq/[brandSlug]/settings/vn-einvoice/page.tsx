"use client";

import { useParams } from "next/navigation";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useTranslation } from "@/providers/app-provider";
import { BrandVnEinvoiceTab } from "../components/brand-vn-einvoice-tab";
import { SettingsTabsNav } from "../components/settings-tabs-nav";

export default function HqSettingsVnEinvoicePage() {
  const params = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  return (
    <>
      <PageHeader
        title={t("hq.brand.settings.vn_einvoice.tab_label")}
        description={t("hq.brand.settings.vn_einvoice.section_title")}
      />

      <PageContent>
        <SettingsTabsNav brandSlug={params.brandSlug} />
        <BrandVnEinvoiceTab brandSlug={params.brandSlug} />
      </PageContent>
    </>
  );
}
