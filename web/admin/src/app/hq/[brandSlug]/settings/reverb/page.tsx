"use client";

import { useParams } from "next/navigation";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useTranslation } from "@/providers/app-provider";
import { ReverbConfigPanel } from "../components/reverb-config-panel";
import { ReverbRotationTab } from "../components/reverb-rotation-tab";
import { SettingsTabsNav } from "../components/settings-tabs-nav";

export default function HqSettingsReverbPage() {
  const params = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  return (
    <>
      <PageHeader title={t("hq.reverb.title")} description={t("hq.reverb.description")} />

      <PageContent>
        <SettingsTabsNav brandSlug={params.brandSlug} />
        <div className="space-y-4">
          <ReverbConfigPanel brandSlug={params.brandSlug} />
          <ReverbRotationTab brandSlug={params.brandSlug} />
        </div>
      </PageContent>
    </>
  );
}
