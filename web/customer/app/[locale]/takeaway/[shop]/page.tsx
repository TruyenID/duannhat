"use client";

import { useEffect, useRef } from "react";
import { useRouter } from "@/i18n/routing";
import { useParams } from "next/navigation";
import { useTranslations } from 'next-intl';
import { Loader2 } from "lucide-react";
import { useBrand } from "@/context/brand-context";
import { useCart } from "@/context/cart-context";
import Header from "@/components/Header";
import MenuPage from "@/components/menu-page";

export default function TakeawayShopPage() {
  const router = useRouter();
  const { shop } = useParams<{ shop: string }>();
  const { currentBranch, branches, isLoadingBranches, switchBranch } = useBrand();
  const { setOrderType } = useCart();
  const t = useTranslations('takeaway');

  const syncedShopRef = useRef<string | null>(null);

  useEffect(() => {
    setOrderType("takeaway");
  }, [setOrderType]);

  useEffect(() => {
    if (isLoadingBranches || !shop) return;
    switchBranch(shop);
  }, [shop, isLoadingBranches, switchBranch]);

  useEffect(() => {
    if (shop && currentBranch.slug === shop) {
      syncedShopRef.current = shop;
    }
  }, [currentBranch.slug, shop]);

  useEffect(() => {
    if (syncedShopRef.current !== shop) return;
    if (!currentBranch.slug) return;
    if (currentBranch.slug !== shop) {
      router.replace(`/takeaway/${currentBranch.slug}`);
    }
  }, [currentBranch.slug, shop, router]);

  if (isLoadingBranches) {
    return (
      <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
        <Header showBack backHref="/select-branch" />
        <div className="flex flex-1 items-center justify-center gap-2 text-muted-foreground">
          <Loader2 className="h-5 w-5 animate-spin" />
          <span className="text-sm">{t('loading')}</span>
        </div>
      </div>
    );
  }

  const branchExists = branches.some((b) => b.slug === shop);
  if (!branchExists) {
    return (
      <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
        <Header showBack backHref="/select-branch" />
        <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 text-center">
          <p className="font-semibold">{t('notFound')}</p>
          <p className="text-sm text-muted-foreground">
            {t('notFoundDesc', { slug: shop })}
          </p>
        </div>
      </div>
    );
  }

  if (currentBranch.slug !== shop) {
    return (
      <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
        <Header showBack backHref="/select-branch" />
        <div className="flex flex-1 items-center justify-center text-muted-foreground">
          <Loader2 className="h-5 w-5 animate-spin" />
        </div>
      </div>
    );
  }

  return (
    // Page bg theo yêu cầu — #FAFAFA phủ toàn bộ route (header, sub-header,
    // menu list). Header + sub-header tự override bằng bg-white để giữ tách
    // lớp; phần dưới (menu) sẽ ăn bg này thay vì bg-background mặc định.
    <div className="min-h-screen bg-[#FAFAFA]">
      {/* hideShadow: ẩn shadow-sm bên dưới global header ở mobile để
          liền mạch với sub-header "← shop name" bên dưới. Desktop vẫn
          giữ shadow (prop chỉ apply `md:shadow-sm`). */}
      <Header menuBranch={currentBranch} hideShadow />
      {/* Sub-header: back + shop name (theo Figma takeaway). Hiển thị giống
          nhau ở cả mobile và desktop theo yêu cầu. Không sticky — sẽ cuộn đi
          để nhường chỗ cho thanh category nav của menu-page (sticky top-[48px]).
          Outer div: border-b + bg-[#FAFAFA] (đồng nhất với page bg theo
          design system flow Takeaway). Inner div: max-w-7xl + mx-auto khớp
          với container của Header để nội dung không bị giãn full-width
          trên desktop. */}
      <div className="border-b bg-[#FAFAFA]">
        <div className="mx-auto flex max-w-7xl items-center gap-2 px-4 py-3 md:px-6 md:py-4">
          {/* TODO(tạm thời): ẩn nút back trong sub-header trang menu takeaway
              theo yêu cầu. Bỏ comment để khôi phục. */}
          {/* <Link
            href="/select-branch"
            aria-label={tCommon('back')}
            className="-ml-1 flex size-7 items-center justify-center rounded-lg text-neutral-700 transition-colors hover:bg-muted"
          >
            <ArrowLeft className="size-5" />
          </Link> */}
          <span className="truncate text-base font-bold text-neutral-900">
            {currentBranch.name}
          </span>
        </div>
      </div>
      <MenuPage hasMenuBanner />
    </div>
  );
}
