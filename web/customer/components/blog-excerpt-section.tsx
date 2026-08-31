"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { formatGuestDate } from "@/lib/date-format";

/**
 * BlogExcerptSection — "Thông báo & Khuyến mãi".
 *
 * **DỮ LIỆU CỨNG, TẠM THỜI.** Bản này KHÔNG gọi API — 3 bài viết nằm thẳng
 * trong `POSTS` bên dưới, đúng như bản thiết kế đang duyệt. Bản #2050 fetch
 * `GET /api/v1/customer/posts?limit=6`; muốn dựng lại thì lấy ở lịch sử git của
 * chính file này.
 *
 * Cứng ở đây là cứng DỮ LIỆU, không phải cứng NGÔN NGỮ: `POSTS` chỉ giữ id +
 * ngày + ảnh, còn tiêu đề nằm ở `blogExcerpt.posts.*` trong cả ba catalogue
 * `messages/{ja,en,vi}.json`. App mặc định `ja`, nên nhét chuỗi tiếng Việt
 * thẳng vào component là để khách Nhật đọc tiếng Việt — và `messages.parity`
 * không bắt được, vì nó chỉ soi catalogue chứ không soi component.
 *
 * **Thẻ không phải link.** Route `/posts` và `/posts/[slug]` CHƯA TỒN TẠI trong
 * `app/[locale]/`. Có route rồi thì bọc lại bằng `Link` + `slug`.
 *
 * Ngày vẫn giữ dạng ISO rồi format theo locale (#1261) chứ không ghi cứng chuỗi
 * hiển thị — app chạy 3 thứ tiếng, `2026/06/05` (ja) và `05/06/2026` (vi) là hai
 * chuỗi khác nhau.
 */

type MockPost = {
  /** Khoá dưới `blogExcerpt.posts.*` trong `messages/{ja,en,vi}.json`. */
  id: "summerPho" | "tsukijiAnniversary" | "hongoBirthday";
  /** ISO — hiển thị qua `formatGuestDate`, không ghi cứng chuỗi. */
  publishedAt: string;
  image: string;
};

/**
 * ⚠️ Ảnh của bài 1 và 2 là ẢNH TẠM — hai poster có sẵn trong `public/images/`,
 * dùng để nhìn được layout. Poster thật trong bản thiết kế là:
 *   - bài 1: 夏限定メニュー 2026 (phở lạnh, ¥1200)
 *   - bài 2: 築地店 5周年記念 — ドリンク全品 50%OFF
 * Có file rồi thì thả vào `public/images/` và sửa đúng dòng `image` ở đây.
 * Bài 3 đã đúng ảnh thật (`banner-spring-menu.webp`).
 */
const POSTS: MockPost[] = [
  {
    id: "summerPho",
    publishedAt: "2026-06-05",
    image: "/images/banner-family-day.webp", // TODO: poster 夏限定メニュー 2026
  },
  {
    id: "tsukijiAnniversary",
    publishedAt: "2026-04-01",
    image: "/images/banner-trung-chan.webp", // TODO: poster 築地店 5周年記念
  },
  {
    id: "hongoBirthday",
    publishedAt: "2026-04-01",
    image: "/images/banner-spring-menu.webp",
  },
];

export default function BlogExcerptSection() {
  const locale = useLocale();
  const t = useTranslations("blogExcerpt");
  const [brokenImages, setBrokenImages] = useState<Set<string>>(new Set());
  const scroller = useRef<HTMLDivElement>(null);
  const [atStart, setAtStart] = useState(true);
  const [atEnd, setAtEnd] = useState(true);

  /**
   * Trạng thái bật/tắt của hai mũi tên.
   *
   * Ở desktop cả 3 thẻ vừa khít khung nên KHÔNG cuộn được — hai mũi tên phải
   * mờ đi và `disabled`, không phải vẫn sáng rồi bấm không ra gì. Đo bằng
   * `scrollWidth` thay vì đếm số bài: cùng 3 bài đó vẫn cuộn được ở mobile
   * (mỗi thẻ chiếm ~78% bề ngang), nên số bài không nói lên điều gì.
   *
   * `- 1` là biên an toàn cho phép làm tròn sub-pixel: ở nhiều mức zoom
   * `scrollLeft + clientWidth` dừng ở 0.5px dưới `scrollWidth` và mũi tên phải
   * sẽ sáng vĩnh viễn dù đã cuộn hết.
   */
  const syncArrows = useCallback(() => {
    const el = scroller.current;
    if (!el) return;
    setAtStart(el.scrollLeft <= 1);
    setAtEnd(el.scrollLeft + el.clientWidth >= el.scrollWidth - 1);
  }, []);

  useEffect(() => {
    syncArrows();
    window.addEventListener("resize", syncArrows);
    return () => window.removeEventListener("resize", syncArrows);
  }, [syncArrows]);

  /** Cuộn đúng MỘT thẻ mỗi lần bấm, đo từ thẻ đầu nên không phải ghim con số. */
  const scrollByCard = (dir: -1 | 1) => {
    const el = scroller.current;
    if (!el) return;
    const card = el.firstElementChild as HTMLElement | null;
    const step = card ? card.offsetWidth + 16 : el.clientWidth;
    el.scrollBy({ left: dir * step, behavior: "smooth" });
  };

  return (
    <section className="relative shrink-0 overflow-hidden bg-linen">
      {/* Hai nêm chéo cắt nền vải: mép trên cao dần về bên phải, mép dưới thấp
          dần về bên phải — đúng cách mockup tách khối này khỏi hai section
          trắng kẹp trên dưới. Màu phải TRÙNG `bg-background` của hai section
          đó, lệch một nấc là hiện ra vệt ngang. */}
      <div
        aria-hidden
        className="pointer-events-none absolute inset-x-0 top-0 h-[44px] bg-background md:h-[72px]"
        style={{ clipPath: "polygon(0 0, 100% 0, 0 100%)" }}
      />
      <div
        aria-hidden
        className="pointer-events-none absolute inset-x-0 bottom-0 h-[44px] bg-background md:h-[72px]"
        style={{ clipPath: "polygon(0 100%, 100% 0, 100% 100%)" }}
      />

      <div className="relative mx-auto w-full max-w-5xl px-4 py-20 md:px-6 md:py-24">
        <h2 className="heading-display text-center" data-reveal>
          {t("title")}
        </h2>

        <div className="relative mx-auto mt-10 w-full max-w-4xl px-9 md:mt-12 md:px-14">
          <CarouselArrow
            side="left"
            label={t("previous")}
            disabled={atStart}
            onClick={() => scrollByCard(-1)}
          />

          {/* `snap-x` + `shrink-0` trên từng thẻ: khung cuộn ngang thật, không
              phải lưới 3 cột — ở mobile mỗi thẻ chiếm ~78% bề ngang để lộ mép
              thẻ kế, dấu hiệu duy nhất cho biết còn bài phía sau. */}
          <div
            ref={scroller}
            onScroll={syncArrows}
            className="scrollbar-hide flex snap-x snap-mandatory gap-4 overflow-x-auto pb-1"
          >
            {POSTS.map((post) => {
              const title = t(`posts.${post.id}`);

              return (
                <article
                  key={post.id}
                  className="w-[78%] shrink-0 snap-start overflow-hidden rounded-md bg-white shadow-[0_10px_28px_-18px_rgba(0,0,0,0.45)] sm:w-[46%] lg:w-[calc((100%-2rem)/3)]"
                >
                  <div className="relative aspect-[16/10] w-full overflow-hidden bg-muted">
                    {!brokenImages.has(post.id) ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img
                        src={post.image}
                        alt={title}
                        onError={() =>
                          setBrokenImages((prev) => new Set(prev).add(post.id))
                        }
                        className="h-full w-full object-cover"
                      />
                    ) : (
                      <div
                        className="h-full w-full"
                        style={{
                          background:
                            "linear-gradient(135deg, #e8f3e6 0%, #cce5cf 100%)",
                        }}
                      />
                    )}
                  </div>
                  <div className="flex flex-col gap-2 px-4 pb-5 pt-3">
                    <span className="text-[11px] text-muted-foreground">
                      {formatGuestDate(post.publishedAt, locale)}
                    </span>
                    <h3 className="line-clamp-3 text-[13px] font-medium leading-[1.6]">
                      {title}
                    </h3>
                  </div>
                </article>
              );
            })}
          </div>

          <CarouselArrow
            side="right"
            label={t("next")}
            disabled={atEnd}
            onClick={() => scrollByCard(1)}
          />
        </div>
      </div>
    </section>
  );
}

function CarouselArrow({
  side,
  label,
  disabled,
  onClick,
}: {
  side: "left" | "right";
  label: string;
  disabled: boolean;
  onClick: () => void;
}) {
  const Icon = side === "left" ? ChevronLeft : ChevronRight;
  return (
    <button
      type="button"
      aria-label={label}
      disabled={disabled}
      onClick={onClick}
      className={`absolute top-1/2 z-10 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-md bg-primary text-primary-foreground shadow-sm transition md:h-10 md:w-10 ${
        side === "left" ? "left-0" : "right-0"
      } ${disabled ? "cursor-default opacity-35" : "hover:bg-primary/90"}`}
    >
      <Icon className="h-5 w-5" aria-hidden />
    </button>
  );
}
