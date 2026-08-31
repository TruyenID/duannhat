import { Suspense } from "react";
import AccountCouponsView from "@/components/account-coupons-view";

export const metadata = { title: "Coupon của tôi · Tempo" };

export default function Page() {
  return (
    <Suspense>
      <AccountCouponsView />
    </Suspense>
  );
}
