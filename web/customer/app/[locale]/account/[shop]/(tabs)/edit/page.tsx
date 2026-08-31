import { Suspense } from "react";
import AccountEditView from "@/components/account-edit-view";

export const metadata = { title: "Chỉnh sửa hồ sơ · Tempo" };

export default function AccountEditPage() {
  return (
    <Suspense>
      <AccountEditView />
    </Suspense>
  );
}
