"use client";

import { useParams, useRouter } from "next/navigation";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useTranslation } from "@/providers/app-provider";
import { WarehouseForm } from "../components/warehouse-form";

export default function NewWarehousePage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t } = useTranslation();
  const router = useRouter();

  const listHref = `/shop/${shopSlug}/warehouses`;

  return (
    <>
      <PageHeader title={t("shop.warehouses.form.new_title")} backHref={listHref} />
      <PageContent>
        <WarehouseForm
          shopSlug={shopSlug}
          onSuccess={() => router.push(listHref)}
          onCancel={() => router.push(listHref)}
        />
      </PageContent>
    </>
  );
}
