"use client";

import { useState } from "react";
import { notFound, useParams } from "next/navigation";
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Spinner,
  StatusBadge,
} from "@godxjp/ui";
import { RefreshCw } from "lucide-react";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import type { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import {
  useShopFloatingSection,
  useSyncShopFloatingSection,
} from "@/hooks/api/use-shop-floating-sections";
import { ShopFloatingSectionSchedulesSection } from "./components/schedules-section";
import { ShopFloatingSectionProductsSection } from "./components/products-section";

export default function ShopFloatingSectionDetailPage() {
  const params = useParams<{ shopSlug: string; sectionId: string }>();
  const { shopSlug, sectionId } = params;
  const { t } = useTranslation();

  const sectionQuery = useShopFloatingSection(shopSlug, sectionId);
  const section = sectionQuery.data?.data;
  const syncMutation = useSyncShopFloatingSection(shopSlug, sectionId);
  const [confirmSyncOpen, setConfirmSyncOpen] = useState(false);

  if (sectionQuery.isLoading || !section) {
    return (
      <>
        <PageHeader
          title={t("hq.floating_sections.detail_title")}
          description={t("shop.floating_sections.detail_subtitle")}
          backHref={`/shop/${shopSlug}/floating-sections`}
        />
        <PageContent>
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Spinner className="size-4" />
            {t("common.loading")}
          </div>
        </PageContent>
      </>
    );
  }

  if (sectionQuery.isError) {
    const status = (sectionQuery.error as ApiError)?.status;
    if (status === 404 || status === 403) notFound();

    return (
      <>
        <PageHeader
          title={t("hq.floating_sections.detail_title")}
          description={t("shop.floating_sections.detail_subtitle")}
          backHref={`/shop/${shopSlug}/floating-sections`}
        />
        <PageContent>
          <div className="flex flex-col items-center gap-3 py-8 text-sm text-muted-foreground">
            <p>{t("common.error_loading")}</p>
            <Button variant="outline" size="sm" onClick={() => sectionQuery.refetch()}>
              {t("common.retry")}
            </Button>
          </div>
        </PageContent>
      </>
    );
  }

  return (
    <>
      <PageHeader
        title={section.name}
        description={t("shop.floating_sections.detail_subtitle")}
        backHref={`/shop/${shopSlug}/floating-sections`}
      >
        {section.master_section_id && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 gap-1.5"
            onClick={() => setConfirmSyncOpen(true)}
            disabled={syncMutation.isPending}
          >
            {syncMutation.isPending ? (
              <Spinner className="size-3.5" />
            ) : (
              <RefreshCw className="size-3.5" />
            )}
            {t("shop.floating_sections.sync")}
          </Button>
        )}
        <StatusBadge status={section.is_active ? "active" : "inactive"} />
      </PageHeader>

      <div className="flex min-h-0 flex-1 flex-col overflow-y-auto">
        <div className="shrink-0 border-b bg-muted/30">
          <ShopFloatingSectionSchedulesSection shopSlug={shopSlug} sectionId={sectionId} />
        </div>
        <div className="shrink-0">
          <ShopFloatingSectionProductsSection shopSlug={shopSlug} sectionId={sectionId} />
        </div>
      </div>

      <Dialog open={confirmSyncOpen} onOpenChange={setConfirmSyncOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t("shop.floating_sections.sync_confirm_title")}</DialogTitle>
            <DialogDescription>
              {t("shop.floating_sections.sync_confirm_description")}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setConfirmSyncOpen(false)}
              disabled={syncMutation.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button
              size="sm"
              onClick={() =>
                syncMutation.mutate(undefined, {
                  onSettled: () => setConfirmSyncOpen(false),
                })
              }
              disabled={syncMutation.isPending}
            >
              {syncMutation.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("common.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
