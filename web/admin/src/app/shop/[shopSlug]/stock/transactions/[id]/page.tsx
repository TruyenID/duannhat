"use client";

import { useParams } from "next/navigation";
import { StockTransactionDetailView } from "@/components/shared/stock-transaction-detail";
import { useTranslation } from "@/providers/app-provider";

export default function StockTransactionDetailPage() {
  const { t } = useTranslation();
  const { shopSlug, id } = useParams<{ shopSlug: string; id: string }>();
  return (
    <StockTransactionDetailView
      shopSlug={shopSlug}
      id={id}
      backHref={`/shop/${shopSlug}/stock/transactions`}
      entityLabel={t("shop.stock.transactions.detail.entity_label")}
    />
  );
}
