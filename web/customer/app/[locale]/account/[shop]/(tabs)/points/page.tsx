import { Suspense } from "react";
import AccountPointsView from "@/components/account-points-view";

export const metadata = { title: "Điểm tích luỹ · Tempo" };

export default function Page() {
  return (
    <Suspense>
      <AccountPointsView />
    </Suspense>
  );
}
