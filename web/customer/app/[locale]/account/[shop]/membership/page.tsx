import { Suspense } from "react";
import AccountMembershipView from "@/components/account-membership-view";

export const metadata = { title: "Đặc quyền thành viên · Tempo" };

export default function Page() {
  return (
    <Suspense>
      <AccountMembershipView />
    </Suspense>
  );
}
