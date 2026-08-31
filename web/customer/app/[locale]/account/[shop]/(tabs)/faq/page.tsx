import { Suspense } from "react";
import AccountFaqView from "@/components/account-faq-view";

export const metadata = { title: "Câu hỏi thường gặp · Tempo" };

export default function Page() {
  return (
    <Suspense>
      <AccountFaqView />
    </Suspense>
  );
}
