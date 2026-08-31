import { Suspense } from "react";
import AccountOrdersView from "@/components/account-orders-view";

export const metadata = { title: "Lịch sử đơn hàng · Tempo" };

export default function AccountOrdersPage() {
  return (
    <Suspense>
      <AccountOrdersView />
    </Suspense>
  );
}
