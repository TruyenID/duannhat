"use client";

import { useEffect, useMemo } from "react";
import { useRouter } from "@/i18n/routing";
import { useBrand } from "@/context/brand-context";
import { PageSpinner } from "@/components/ui/page-spinner";
import { selectBranchHref, type ShopScopedFlow } from "@/lib/shop-routes";

type RequireShopProps = {
  /** Slug lấy từ segment `[shop]` của URL. */
  shopSlug: string;
  /** Khu đang vào — quyết định `?next=` khi phải đá về /select-branch. */
  flow: ShopScopedFlow;
  children: React.ReactNode;
};

/**
 * Nửa còn lại của guard "phải có cửa hàng trong URL" (#1505).
 *
 * Middleware chỉ trả lời được câu hỏi *có* segment cửa hàng hay không — nó chạy
 * ở edge runtime nên không gọi API để biết slug có thật. Ở đây thì biết: đối
 * chiếu slug với danh sách chi nhánh, slug lạ (URL cũ kiểu `/account/orders`,
 * chi nhánh đã gỡ, gõ sai) thì đá về /select-branch thay vì render một khu tài
 * khoản treo lơ lửng không thuộc cửa hàng nào.
 *
 * Đồng thời lấy URL làm nguồn chân lý cho "đang ở cửa hàng nào": mở
 * `/account/ginza` là chuyển hẳn sang ginza, không để header nói một đằng URL
 * một nẻo. `brand-context` chỉ đọc slug trong route ở lần nạp đầu tiên, nên
 * điều hướng phía client giữa hai cửa hàng cần cú `switchBranch` này.
 */
export default function RequireShop({ shopSlug, flow, children }: RequireShopProps) {
  const router = useRouter();
  const { branches, isLoadingBranches, currentBranch, switchBranch } = useBrand();

  const branch = useMemo(
    () => branches.find((b) => b.slug === shopSlug),
    [branches, shopSlug],
  );

  // Chỉ kết luận "slug lạ" SAU khi danh sách chi nhánh đã về. Trước đó
  // `branches` rỗng với mọi slug, kết luận sớm là đá nhầm cả URL hợp lệ.
  const isUnknownShop = !isLoadingBranches && !branch;

  useEffect(() => {
    if (isUnknownShop) {
      router.replace(selectBranchHref(flow));
    }
  }, [isUnknownShop, router, flow]);

  useEffect(() => {
    if (branch && currentBranch.slug !== branch.slug) {
      switchBranch(branch.slug);
    }
  }, [branch, currentBranch.slug, switchBranch]);

  if (isLoadingBranches || isUnknownShop) {
    return <PageSpinner variant="card" />;
  }

  return <>{children}</>;
}
