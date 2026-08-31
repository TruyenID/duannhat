"use client";

import { Link, usePathname } from "@/i18n/routing";
import { useAuth } from "@/context/auth-context";
import { useBrand } from "@/context/brand-context";
import { accountHref, shopSlugFromPathname } from "@/lib/shop-routes";
import { User } from "lucide-react";

/**
 * UserMenu — chỉ hiển thị avatar + tên (link sang khu tài khoản của cửa hàng
 * đang xem). Nút "Đăng xuất" đã được gỡ khỏi header (cả mobile lẫn desktop)
 * theo yêu cầu; user thực hiện đăng xuất từ trang tài khoản.
 *
 * Chỉ được render ở nơi cửa hàng xác định được (#1747) — Header gate bằng
 * `authEntryPointsAllowed`. Cửa hàng lấy từ URL TRƯỚC, `currentBranch`
 * (localStorage) chỉ là phương án dự phòng cho luồng mua có URL trần
 * (/checkout…), nơi cửa hàng đến từ giỏ. Khách mở /stores/ginza trong khi
 * localStorage còn ghim chi nhánh cũ thì chip phải trỏ ginza — đúng cửa hàng
 * họ đang đứng, không phải chi nhánh của phiên trước (#1505).
 */
export default function UserMenu() {
  const { user } = useAuth();
  const { currentBranch } = useBrand();
  const pathname = usePathname();
  const shopSlug = shopSlugFromPathname(pathname) ?? currentBranch.slug;

  return (
    <div className="flex items-center gap-2">
      <Link
        href={accountHref(shopSlug)}
        className="flex items-center gap-2 rounded-lg px-1.5 py-1 transition-colors hover:bg-muted/60"
      >
        <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
          {user?.name?.charAt(0).toUpperCase() ?? <User className="h-3.5 w-3.5" />}
        </span>
        <span className="hidden text-sm font-medium sm:inline">{user?.name}</span>
      </Link>
    </div>
  );
}
