import { Suspense } from "react";
import AccountView from "@/components/account-view";

export const metadata = { title: "Tài khoản · Tempo" };

export default function AccountPage() {
  return (
    <Suspense>
      <AccountView />
    </Suspense>
  );
}
