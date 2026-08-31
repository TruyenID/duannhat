import { Suspense } from "react";
import AccountPasswordView from "@/components/account-password-view";

export const metadata = { title: "Đổi mật khẩu · Tempo" };

export default function AccountPasswordPage() {
  return (
    <Suspense>
      <AccountPasswordView />
    </Suspense>
  );
}
