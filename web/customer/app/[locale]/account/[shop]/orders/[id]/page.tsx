import { Suspense } from "react";
import AccountOrderDetailView from "@/components/account-order-detail-view";

export const metadata = { title: "Chi tiết đơn hàng · Tempo" };

export default function AccountOrderDetailPage() {
  return (
    <Suspense>
      <AccountOrderDetailView />
    </Suspense>
  );
}
