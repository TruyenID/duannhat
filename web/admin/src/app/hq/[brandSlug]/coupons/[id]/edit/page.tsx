"use client";

import { notFound, useParams } from "next/navigation";
import { Skeleton } from "@godxjp/ui";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useCoupon } from "@/hooks/api/use-coupons";
import { ApiError } from "@/lib/api";
import { CouponForm } from "../../components/coupon-form";
import { useTranslation } from "@/providers/app-provider";

export default function EditCouponPage() {
  const { t } = useTranslation();
  const { brandSlug, id } = useParams<{ brandSlug: string; id: string }>();
  const { data, isLoading, error } = useCoupon(brandSlug, id);
  const coupon = data?.data;

  if (error instanceof ApiError && (error.status === 404 || error.status === 403)) {
    notFound();
  }

  if (error) {
    return (
      <>
        <PageHeader
          title={t("hq.coupons.edit_title")}
          backHref={`/hq/${brandSlug}/coupons/${id}`}
        />
        <PageContent>
          <div className="py-12 text-center text-sm text-red-500">{t("common.load_error")}</div>
        </PageContent>
      </>
    );
  }

  if (isLoading || !coupon) {
    return (
      <>
        <PageHeader
          title={t("hq.coupons.edit_title")}
          backHref={`/hq/${brandSlug}/coupons/${id}`}
        />
        <PageContent>
          <Skeleton className="h-96 w-full" />
        </PageContent>
      </>
    );
  }

  return <CouponForm brandSlug={brandSlug} coupon={coupon} />;
}
