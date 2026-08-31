"use client";

import { useParams } from "next/navigation";
import { CouponForm } from "../components/coupon-form";

export default function NewCouponPage() {
  const params = useParams<{ brandSlug: string }>();
  return <CouponForm brandSlug={params.brandSlug} coupon={null} />;
}
