"use client";

import { useParams } from "next/navigation";
import { PromotionForm } from "../components/promotion-form";

export default function NewPromotionPage() {
  const params = useParams<{ shopSlug: string }>();
  return <PromotionForm shopSlug={params.shopSlug} promotion={null} />;
}
