"use client";

import { Tabs, TabsContent, TabsList, TabsTrigger } from "@godxjp/ui";
import { useParams } from "next/navigation";
import { useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { useTranslation } from "@/providers/app-provider";

import { ProvidersTab } from "./components/providers-tab";
import { RoutingMatrixTab } from "./components/routing-matrix-tab";

type TabValue = "matrix" | "providers";

export default function RoutingMatrixPage() {
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;
  const { t } = useTranslation();
  const [tab, setTab] = useState<TabValue>("matrix");

  return (
    <>
      <PageHeader
        title={t("notifications.admin.routing.title")}
        description={t("notifications.admin.routing.subtitle")}
      />

      <PageContent>
        <Tabs value={tab} onValueChange={(v) => setTab(v as TabValue)}>
          <TabsList className="mb-4">
            <TabsTrigger value="matrix">{t("notifications.admin.routing.tab_matrix")}</TabsTrigger>
            <TabsTrigger value="providers">
              {t("notifications.admin.routing.tab_providers")}
            </TabsTrigger>
          </TabsList>
          <TabsContent value="matrix">
            <RoutingMatrixTab brandSlug={brandSlug} />
          </TabsContent>
          <TabsContent value="providers">
            <ProvidersTab brandSlug={brandSlug} />
          </TabsContent>
        </Tabs>
      </PageContent>
    </>
  );
}
