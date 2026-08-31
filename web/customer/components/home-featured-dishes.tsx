"use client";

import { useEffect, useMemo, useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { Link } from "@/i18n/routing";
import type { MenuCategory, MenuItem } from "@/data/menu";
import type { Branch } from "@/data/brands";
import { apiFetch } from "@/lib/api";
import { useBrand } from "@/context/brand-context";

/**
 * HomeFeaturedDishes — section "Menu" của trang chủ (godx-tempo#2050).
 *
 * Dữ liệu lấy từ chính menu đang chạy
 * (`GET /api/v1/customer/branches/{slug}/menu`) — cùng nguồn với trang đặt món,
 * không phải danh sách cứng: món hết hàng / đổi tên / đổi ảnh ở admin là trang
 * chủ đổi theo, không cần ai nhớ sửa hai chỗ.
 *
 * ⚠️ Mockup 2026-08 BỎ giá và ba chip (loại món / mang về / thuế) khỏi mỗi thẻ:
 * khối này giờ chỉ còn ảnh + tên + mô tả. Bản trước cố ý hiện giá thật (kể cả
 * khi Happy Hour đang hạ giá — #1185, plan-019) với lý do "giá là con số khách
 * đọc rồi quyết định đi ăn". Bỏ giá là quyết định THIẾT KẾ, không phải dọn code:
 * muốn trả lại thì lấy `effectiveUnitPrice` + `formatCurrency` ở lịch sử git của
 * chính file này, và nhớ trả cả `homeFeatured.priceLabel` / `badge*` vào ba
 * catalogue `messages/{ja,en,vi}.json`.
 *
 * Trang chủ là cấp THƯƠNG HIỆU nên có thể chưa chọn chi nhánh: ưu tiên chi
 * nhánh đang chọn, không có thì lấy chi nhánh đầu trong danh sách chỉ để
 * TRƯNG BÀY — thẻ dẫn sang `/select-branch`, khách vẫn phải tự chọn nơi mua.
 *
 * Không có menu (ngoài giờ, API lỗi, chi nhánh chưa cấu hình) → ẩn hẳn section
 * thay vì dựng khung rỗng, giống `blog-excerpt-section`.
 */

/** Số thẻ trên lưới — 2 hàng × 3 cột theo mockup. */
const MAX_DISHES = 6;

/**
 * Số chi nhánh tối đa được hỏi khi khách CHƯA chọn chi nhánh nào.
 *
 * Không phải chi nhánh nào cũng đang mở menu online: ở dữ liệu hiện tại chi
 * nhánh ĐẦU DANH SÁCH trả 404 `menu_unavailable`, nên nếu chỉ hỏi mỗi nó thì
 * section im lặng biến mất dù 8 chi nhánh khác có menu. Hỏi thêm vài chi nhánh
 * nữa rồi dừng ở chi nhánh đầu tiên có món — có chặn trên để một brand toàn
 * chi nhánh đóng cửa không kéo theo hàng chục request.
 */
const MAX_BRANCH_ATTEMPTS = 4;

/**
 * Đích của thẻ món và nút "xem toàn bộ" — giống CTA ở `home-story.tsx`
 * (`ORDER_HREF`). Đổi thì đổi cả hai chỗ.
 */
const ORDER_HREF = "/select-branch?next=takeaway";

type MenuApiResponse = {
  data: {
    categories: MenuCategory[];
  };
};

export default function HomeFeaturedDishes() {
  const locale = useLocale();
  const t = useTranslations("homeFeatured");
  const { currentBranch, branches } = useBrand();
  const [dishes, setDishes] = useState<MenuItem[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [brokenImages, setBrokenImages] = useState<Set<string>>(new Set());

  /**
   * Thứ tự chi nhánh sẽ hỏi menu: chi nhánh đang gắn với phiên trước, rồi tới
   * các chi nhánh còn lại.
   *
   * `currentBranch` ở trang chủ KHÔNG đồng nghĩa "khách đã chọn": brand-context
   * tự gán `mapped[0]` khi danh sách về, nên nó có thể chỉ là chi nhánh đầu
   * bảng — và ở dữ liệu hiện tại chính chi nhánh đó trả 404 `menu_unavailable`.
   * Dừng lại ở nó là section im lặng biến mất dù 8 chi nhánh khác đang có menu.
   *
   * Đi tiếp sang chi nhánh khác an toàn ở ĐÂY vì section chỉ TRƯNG BÀY: mỗi thẻ
   * dẫn sang `/select-branch`, không thẻ nào đặt hàng thẳng, nên khách vẫn phải
   * tự chọn nơi mua trước khi thấy giá có hiệu lực với mình.
   */
  const candidates: Branch[] = useMemo(() => {
    const ordered = currentBranch.slug
      ? [currentBranch, ...branches.filter((b) => b.slug !== currentBranch.slug)]
      : branches;
    return ordered.slice(0, MAX_BRANCH_ATTEMPTS);
  }, [currentBranch, branches]);

  /**
   * Danh tính của tập ứng viên, dạng chuỗi. `useBrand()` dựng lại object value
   * mỗi lần render (`branches ?? []`), nên `candidates` cũng là mảng MỚI mỗi
   * render — để nó thẳng vào deps của effect là fetch vô hạn. So bằng slug thì
   * effect chỉ chạy lại khi tập chi nhánh thật sự đổi.
   */
  const candidateKey = candidates.map((b) => b.slug).join(",");

  useEffect(() => {
    if (candidates.length === 0) return;

    const ac = new AbortController();
    (async () => {
      for (const branch of candidates) {
        let data: MenuApiResponse["data"];
        try {
          ({ data } = await apiFetch<MenuApiResponse>(
            `/api/v1/customer/branches/${branch.slug}/menu`,
            { signal: ac.signal },
          ));
        } catch {
          // 404 `menu_unavailable` (ngoài giờ / chưa cấu hình) là chuyện bình
          // thường — thử chi nhánh kế, chỉ bỏ cuộc khi hết danh sách.
          if (ac.signal.aborted) return;
          continue;
        }
        if (ac.signal.aborted) return;

        // Section `is_featured` là do shop bật (#1187) — tôn trọng nó trước,
        // hết mới lấy tới các section thường theo đúng thứ tự admin đã xếp.
        const featuredFirst = [
          ...data.categories.filter((cat) => cat.is_featured),
          ...data.categories.filter((cat) => !cat.is_featured),
        ];
        const picked: MenuItem[] = [];
        const seen = new Set<string>();
        for (const cat of featuredFirst) {
          for (const item of cat.items) {
            // Một món có thể nằm ở cả section nổi bật lẫn section thường —
            // lọc theo id để lưới không lặp cùng một bát phở hai lần.
            if (seen.has(item.id)) continue;
            if (item.status === "sold_out") continue;
            seen.add(item.id);
            picked.push(item);
            if (picked.length >= MAX_DISHES) break;
          }
          if (picked.length >= MAX_DISHES) break;
        }
        if (picked.length === 0) continue;

        setDishes(picked);
        return;
      }
      if (!ac.signal.aborted) setFailed(true);
    })();

    return () => ac.abort();
    // `candidates` cố tình không nằm trong deps — xem candidateKey ở trên.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [candidateKey, locale]);

  // Không chi nhánh nào để hỏi, hoặc không chi nhánh nào có menu → không render.
  if (failed || candidates.length === 0) return null;

  return (
    <section className="shrink-0 bg-background">
      <div className="mx-auto w-full max-w-5xl px-4 py-16 md:px-6 md:py-20">
        <div className="text-center" data-reveal>
          <h2 className="heading-display">{t("title")}</h2>
          <p className="mx-auto mt-4 max-w-md text-[15px] leading-[24px] text-foreground/70 md:text-[16px]">
            {t("subtitle")}
          </p>
        </div>

        {/* Thẻ KHÔNG có khung/nền/bóng — mockup để ảnh món nổi thẳng trên nền
            trang, chữ canh giữa ngay dưới ảnh. Vì thế ảnh cũng không bo góc:
            viền bo trên một tấm ảnh không khung sẽ lộ ra bốn cái vát vô cớ. */}
        <div
          className="mt-12 grid gap-x-8 gap-y-12 sm:grid-cols-2 md:mt-16 md:gap-x-10 lg:grid-cols-3"
          data-reveal-stagger
        >
          {dishes === null
            ? Array.from({ length: MAX_DISHES }).map((_, i) => (
                <div key={`skeleton-${i}`} className="flex flex-col items-center">
                  <div className="aspect-[16/10] w-full animate-pulse bg-muted" />
                  <div className="mt-4 h-5 w-1/2 animate-pulse rounded bg-muted" />
                  <div className="mt-3 h-3 w-full animate-pulse rounded bg-muted" />
                  <div className="mt-2 h-3 w-4/5 animate-pulse rounded bg-muted" />
                </div>
              ))
            : dishes.map((item) => (
                <Link
                  key={item.id}
                  href={ORDER_HREF}
                  className="group flex flex-col items-center text-center"
                >
                  <div className="w-full overflow-hidden">
                    {item.image && !brokenImages.has(item.id) ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img
                        src={item.image}
                        alt={item.name}
                        onError={() =>
                          setBrokenImages((prev) => new Set(prev).add(item.id))
                        }
                        className="aspect-[16/10] w-full object-cover transition-transform duration-500 group-hover:scale-105"
                      />
                    ) : (
                      <div
                        className="aspect-[16/10] w-full"
                        style={{
                          background:
                            "linear-gradient(135deg, #e8f3e6 0%, #cce5cf 100%)",
                        }}
                      />
                    )}
                  </div>

                  <h3 className="mt-4 text-[18px] font-bold leading-snug text-foreground transition group-hover:text-primary md:text-[20px]">
                    {item.name}
                  </h3>

                  {item.description && (
                    <p className="mt-2 line-clamp-4 text-[13px] leading-[1.7] text-foreground/70">
                      {item.description}
                    </p>
                  )}
                </Link>
              ))}
        </div>

        <div className="mt-14 text-center md:mt-16" data-reveal>
          <Link
            href={ORDER_HREF}
            className="inline-flex min-w-[260px] items-center justify-center rounded-md bg-primary px-10 py-4 text-[16px] font-semibold text-primary-foreground shadow-sm transition hover:-translate-y-0.5 hover:bg-primary/90 hover:shadow-md md:min-w-[340px]"
          >
            {t("viewAll")}
          </Link>
        </div>
      </div>
    </section>
  );
}
