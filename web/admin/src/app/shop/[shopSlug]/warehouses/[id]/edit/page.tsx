"use client";

import { notFound, useParams, useRouter } from "next/navigation";
import { Button, Spinner } from "@godxjp/ui";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { type ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { useWarehouse } from "@/hooks/api/use-warehouses";
import { WarehouseForm } from "../../components/warehouse-form";

export default function EditWarehousePage() {
  const { shopSlug, id } = useParams<{ shopSlug: string; id: string }>();
  const { t } = useTranslation();
  const router = useRouter();

  const listHref = `/shop/${shopSlug}/warehouses`;
  const { data, isLoading, error, refetch } = useWarehouse(shopSlug, id);

  if (isLoading) {
    return (
      <>
        <PageHeader title={t("shop.warehouses.form.edit_title")} backHref={listHref} />
        <PageContent>
          <div className="flex items-center justify-center py-12">
            <Spinner className="size-5" />
            <span className="ml-2 text-sm text-muted-foreground">{t("common.loading")}</span>
          </div>
        </PageContent>
      </>
    );
  }

  if (error) {
    const status = (error as ApiError)?.status;
    if (status === 404 || status === 403) notFound();
    return (
      <>
        <PageHeader title={t("shop.warehouses.form.edit_title")} backHref={listHref} />
        <PageContent>
          <div className="flex h-full flex-col items-center justify-center gap-3 text-sm text-muted-foreground">
            <p>{t("common.error_loading")}</p>
            <Button variant="outline" size="sm" onClick={() => refetch()}>
              {t("common.retry")}
            </Button>
          </div>
        </PageContent>
      </>
    );
  }

  const warehouse = data?.data;
  if (!warehouse) notFound();

  return (
    <>
      <PageHeader title={t("shop.warehouses.form.edit_title")} backHref={listHref} />
      <PageContent>
        <WarehouseForm
          shopSlug={shopSlug}
          warehouse={warehouse}
          onSuccess={() => router.push(listHref)}
          onCancel={() => router.push(listHref)}
        />
      </PageContent>
    </>
  );
}
