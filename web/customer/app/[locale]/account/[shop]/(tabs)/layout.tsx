"use client";

import { usePathname } from "next/navigation";

import AccountShell, { type AccountNavKey } from "@/components/account-shell";

/**
 * Vỏ khu tài khoản, đặt ở LAYOUT chứ không trong từng trang (#1938).
 *
 * ## Vì sao nhóm route `(tabs)` chứ không phải `[shop]/layout.tsx`
 *
 * `[shop]/layout.tsx` bọc **cả chín** route dưới `/account/{shop}`, nhưng chỉ
 * năm cái là TAB. Bốn cái còn lại — `coupons` · `membership` · `password` ·
 * `orders/[id]` — dựng chrome riêng (`<Header showBack hideSwitcher showLogo />`),
 * tức trang đầy đủ có nút quay lại.
 *
 * Đặt vỏ ở layout dùng chung sẽ cho chúng **cả sidebar lẫn Header của chính
 * chúng**: hai bộ chrome chồng nhau, và nút quay lại nằm cạnh một sidebar điều
 * hướng tới đúng chỗ nó vừa rời.
 *
 * Nhóm route không thêm segment vào URL, nên `/account/{shop}/edit` vẫn là
 * `/account/{shop}/edit`. Đây là công cụ App Router có sẵn cho đúng tình huống
 * "một tập con của các route con chia một vỏ".
 *
 * ## Vì sao đặt ở layout mới đúng
 *
 * App Router **giữ nguyên layout** khi chuyển giữa các route con, nhưng
 * unmount trang cũ. Vỏ nằm trong từng trang ⇒ mỗi lần bấm sidebar là dựng lại
 * toàn bộ header + sidebar, mất vị trí cuộn và nhấp nháy. Cùng lý do
 * `[shop]/layout.tsx` đặt `RequireShop` ở layout — docblock của nó nói y hệt.
 */
export default function AccountTabsLayout({ children }: { children: React.ReactNode }) {
  return <AccountShell active={useActiveNavKey()}>{children}</AccountShell>;
}

/**
 * Suy tab đang sáng từ đường dẫn.
 *
 * Trước #1938 mỗi trang tự truyền `active`, nên nó luôn đúng. Nâng vỏ lên layout
 * đổi nó thành một phép suy — và **suy sai là sáng nhầm tab**, một lỗi không có
 * gì đỏ, chỉ có người dùng thấy mình đang ở chỗ khác.
 *
 * Bản đồ dưới đây chép từ đúng giá trị mà từng view TỪNG truyền, không phải từ
 * tên route: gốc `/account/{shop}` truyền `membership`, còn `/edit` truyền
 * `profile` — hai chỗ mà đoán theo tên đường dẫn sẽ ra sai.
 */
function useActiveNavKey(): AccountNavKey {
  const pathname = usePathname();

  // So theo ĐUÔI đường dẫn, không theo vị trí segment: prefix locale và slug
  // cửa hàng đều có độ dài thay đổi (`/vi/account/quan-a/edit`), nên đếm segment
  // là cách hỏng ngay khi ai đó thêm một tiền tố.
  if (pathname.endsWith("/edit")) return "profile";
  if (pathname.endsWith("/faq")) return "faq";
  if (pathname.endsWith("/points")) return "points";
  if (pathname.includes("/orders")) return "orders";

  // Gốc khu tài khoản. `membership` chứ không phải một khoá "home" — đó là giá
  // trị `account-view.tsx` vẫn truyền.
  return "membership";
}
