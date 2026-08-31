"use client";

import { useParams } from "next/navigation";
import RegisterForm from "@/components/register-form";
import RequireShop from "@/components/require-shop";

export default function RegisterShopPage() {
  const { shop } = useParams<{ shop: string }>();

  return (
    <RequireShop shopSlug={shop} flow="register">
      <RegisterForm shopSlug={shop} />
    </RequireShop>
  );
}
