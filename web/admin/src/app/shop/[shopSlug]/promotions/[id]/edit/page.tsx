"use client";

import { useParams } from "next/navigation";
import { Skeleton } from "@godxjp/ui";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useShopPromotion } from "@/hooks/api/use-menu-promotions";
import { PromotionForm } from "../../components/promotion-form";
import { useTranslation } from "@/providers/app-provider";

export default function EditShopPromotionPage() {
  const { t } = useTranslation();
  const { shopSlug, id } = useParams<{ shopSlug: string; id: string }>();
  const { data, isLoading, error } = useShopPromotion(shopSlug, id);
  const promotion = data?.data;

  if (error) {
    return (
      <>
        <PageHeader
          title={t("shop.promotions.edit_title")}
          backHref={`/shop/${shopSlug}/promotions/${id}`}
        />
        <PageContent>
          <div className="py-12 text-center text-sm text-red-500">{t("common.load_error")}</div>
        </PageContent>
      </>
    );
  }

  if (isLoading || !promotion) {
    return (
      <>
        <PageHeader
          title={t("shop.promotions.edit_title")}
          backHref={`/shop/${shopSlug}/promotions/${id}`}
        />
        <PageContent>
          <Skeleton className="h-96 w-full" />
        </PageContent>
      </>
    );
  }

  return <PromotionForm shopSlug={shopSlug} promotion={promotion} />;
}
