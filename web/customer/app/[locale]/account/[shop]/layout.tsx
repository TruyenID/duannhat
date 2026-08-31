"use client";

import { useParams } from "next/navigation";
import RequireShop from "@/components/require-shop";

/**
 * Toàn bộ khu `/account` sống dưới một cửa hàng (#1505) — `/account/{shop}`,
 * `/account/{shop}/orders`, … Đặt guard ở layout chứ không ở từng trang vì
 * App Router giữ nguyên layout khi chuyển giữa các route con: slug được đối
 * chiếu một lần, đổi tab trong khu tài khoản không phải kiểm lại.
 */
export default function AccountShopLayout({ children }: { children: React.ReactNode }) {
  const { shop } = useParams<{ shop: string }>();

  return (
    <RequireShop shopSlug={shop} flow="account">
      {children}
    </RequireShop>
  );
}
