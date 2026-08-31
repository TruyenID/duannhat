"use client";

import { useParams } from "next/navigation";
import { StockTransactionDetailView } from "@/components/shared/stock-transaction-detail";
import { useTranslation } from "@/providers/app-provider";

export default function DisposalDetailPage() {
  const { t } = useTranslation();
  const { shopSlug, id } = useParams<{ shopSlug: string; id: string }>();
  return (
    <StockTransactionDetailView
      shopSlug={shopSlug}
      id={id}
      backHref={`/shop/${shopSlug}/stock/disposals`}
      entityLabel={t("shop.stock.disposals.detail.entity_label")}
    />
  );
}
