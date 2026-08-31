"use client";

import { useParams } from "next/navigation";
import LoginForm from "@/components/login-form";
import RequireShop from "@/components/require-shop";

export default function LoginShopPage() {
  const { shop } = useParams<{ shop: string }>();

  return (
    <RequireShop shopSlug={shop} flow="login">
      <LoginForm shopSlug={shop} />
    </RequireShop>
  );
}
