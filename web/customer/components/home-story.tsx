"use client";

import { useTranslations } from "next-intl";
import { Link } from "@/i18n/routing";
import type { ComponentType } from "react";
import SplitText from "@/components/split-text";

/**
 * HomeStory — hai khối đầu trang chủ theo mockup 2026-08 (godx-tempo#2050):
 * hero (dải sóng 青海波 + bát phở) → lời dẫn thương hiệu + CTA → "công phu"
 * (tiêu đề giữa + 3 mục icon-trái).
 *
 * Section "Menu" nằm riêng ở `home-featured-dishes.tsx` vì nó gọi API menu;
 * hai khối dưới đây thuần tĩnh nên vẫn render được khi mạng chết.
 *
 * So với bản trước đã BỎ, vì mockup mới không còn:
 * - hero ảnh full-bleed + card chữ nền mờ (nay là dải sóng + bát phở trên nền
 *   xanh, chữ tụt xuống nền kem bên dưới);
 * - bát phở khổng lồ ở cột phải của khối "công phu" — bát chuyển lên hero, khối
 *   này chỉ còn icon + chữ;
 * - section "Hai kênh đặt hàng". Kênh "ăn tại quán" vốn đã bị ẩn từ trước, kênh
 *   "mang về" trùng đích với nút CTA ngay dưới lời dẫn (`ORDER_HREF`), nên xoá
 *   section là bỏ một thẻ lặp lại chính nút phía trên chứ không mất lối đi nào.
 *
 * Animation dùng class CSS có sẵn trong `globals.css` (anim-enter,
 * data-reveal / data-reveal-stagger).
 */

/**
 * Đích của MỌI CTA đặt hàng trên trang chủ.
 *
 * Trang chủ là cấp THƯƠNG HIỆU — chưa biết khách muốn chi nhánh nào, mà menu và
 * giá chỉ có nghĩa sau khi đã chọn (#1717). Nên không trỏ thẳng `/menuorder`.
 * Nút "Xem toàn bộ thực đơn" ở `home-featured-dishes.tsx` dùng cùng đích này —
 * đổi thì đổi cả hai chỗ.
 */
const ORDER_HREF = "/select-branch?next=takeaway";

/** Icon của từng mục "công phu", theo đúng thứ tự `craftItems` trong catalogue. */
const CRAFT_ICONS: ComponentType<{ className?: string }>[] = [
  BrothIcon,
  LeafIcon,
  NoodleIcon,
];

type CraftItem = { title: string; desc: string };

export default function HomeStory() {
  const t = useTranslations("homeStory");
  const craftItems = t.raw("craftItems") as CraftItem[];

  return (
    <>
      {/* ── 1. Hero ─────────────────────────────────────────────────────────
          Dải sóng xanh chiếm phần trên, bát phở đè lên ranh giới và thò xuống
          nền kem — đúng cách mockup xếp.

          Ba thứ phải cùng đúng thì bát mới tràn được: dải xanh là lớp
          `absolute` RIÊNG (chỉ nó bị `clip-path` cắt, bát nằm ngoài nên không
          bị cắt theo), section mang sẵn nền kem (phần dưới bát rơi lên nền đó,
          không phải lên section kế), và chiều cao dải tính bằng
          `calc(100% - n)` để phần thò xuống luôn là n px bất kể bát to bao
          nhiêu ở từng breakpoint. */}
      <section className="relative shrink-0 overflow-hidden bg-[#fbf7ee]">
        {/* Đáy dải xanh là chữ V nông: giữa sâu nhất, hai mép nông hơn, để lộ
            hai tam giác kem ở hai góc dưới. 90% chứ không phải một số px cố
            định — độ sâu V phải co theo chiều cao dải khi đổi breakpoint. */}
        <div
          aria-hidden
          className="pointer-events-none absolute inset-x-0 top-0 h-[calc(100%-56px)] bg-[#00974b] md:h-[calc(100%-96px)]"
          style={{ clipPath: "polygon(0 0, 100% 0, 100% 90%, 50% 100%, 0 90%)" }}
        >
          <SeigaihaPattern />
        </div>

        <div className="relative flex justify-center px-4 pb-6 pt-8 md:pb-10 md:pt-12">
          {/* Đúng tấm ảnh bát phở của mockup — nền trong suốt nên đặt được
              thẳng lên nền xanh. Dùng bản CẮT SÁT (`-tight`): bản gốc có lề
              trong suốt dày ~10% dưới đáy, lề đó ăn hết phần thò xuống nên bát
              không bao giờ chạm tới ranh giới xanh/kem.

              Bóng đổ bằng `drop-shadow` chứ không `box-shadow`: ảnh nền trong
              suốt, box-shadow sẽ vẽ bóng của Ô VUÔNG bao quanh thay vì của cái
              bát. */}
          <div
            className="aspect-square w-[72vw] max-w-[380px] anim-enter md:max-w-[560px]"
            style={{
              backgroundImage: "url(/images/craft-pho-bowl-tight.webp)",
              backgroundSize: "contain",
              backgroundPosition: "center",
              backgroundRepeat: "no-repeat",
              filter: "drop-shadow(0 26px 34px rgba(0, 0, 0, 0.22))",
            }}
            aria-hidden
          />
        </div>
      </section>

      {/* ── 2. Lời dẫn + công phu ────────────────────────────────────────────
          Một section duy nhất cho hai khối: mockup vẽ chúng trên CÙNG một nền
          kem liền mạch, tách đôi thì phải đồng bộ hai lần khai báo nền và cái
          nêm chéo ở đáy sẽ rơi nhầm chỗ. */}
      <section className="relative shrink-0 overflow-hidden bg-[#fbf7ee] pb-24 md:pb-32">
        <div className="mx-auto w-full max-w-5xl px-4 md:px-6">
          <div className="text-center anim-enter">
            <h1 className="heading-display text-balance">
              <SplitText text={t("heroTitle")} />
            </h1>
            <p className="mx-auto mt-5 max-w-3xl text-[16px] font-normal leading-[28px] text-[#374151] md:text-[17px]">
              {t("heroLead")}
            </p>
            <div className="mt-8">
              <Link
                href={ORDER_HREF}
                className="inline-flex min-w-[240px] items-center justify-center rounded-md bg-primary px-8 py-3.5 text-[15px] font-semibold text-primary-foreground shadow-sm transition hover:-translate-y-0.5 hover:bg-primary/90 hover:shadow-md"
              >
                {t("heroOrderOnline")}
              </Link>
            </div>
          </div>

          <h2 className="heading-display mt-20 text-balance text-center md:mt-24" data-reveal>
            {t("craftTitle")}
          </h2>

          {/* Icon cột trái, chữ cột phải — `items-start` chứ không `items-center`
              vì phần mô tả dài ngắn khác nhau giữa ba ngôn ngữ, canh giữa thì
              icon trôi xuống lệch khỏi hàng tiêu đề nó đang chú thích. */}
          <div className="mt-10 space-y-8 md:mt-12 md:space-y-10" data-reveal-stagger>
            {craftItems.map(({ title, desc }, idx) => {
              const Icon = CRAFT_ICONS[idx];
              return (
                <div key={title} className="flex items-start gap-5 md:gap-7">
                  <Icon className="mt-1 h-10 w-10 shrink-0 text-[#4b5563] md:h-12 md:w-12" />
                  <div className="min-w-0 flex-1">
                    {/* Spec Figma ghi `background: #00974B`, nhưng trong mockup
                        đây là CHỮ xanh trên nền kem chứ không phải khối nền —
                        Figma gọi fill của text layer là "background". Dựng
                        thành nền thì ba mục sẽ thành ba thanh xanh đặc. */}
                    <h3 className="text-[19px] font-semibold leading-[1.3] tracking-[0.5px] text-[#00974b] md:text-[22px]">
                      {title}
                    </h3>
                    <p className="mt-2 text-[15px] font-light leading-[26px] tracking-[0.2px] text-[#222222] md:text-[16px] md:leading-[28px]">
                      {desc}
                    </p>
                  </div>
                </div>
              );
            })}
          </div>
        </div>

        {/* Nêm chéo sang section "Menu": mép trên của nền trắng cao dần về bên
            phải. Màu phải TRÙNG nền của section kế (`bg-background`) — lệch một
            nấc là hiện ra một vệt ngang ngay dưới nêm. */}
        <div
          aria-hidden
          className="pointer-events-none absolute inset-x-0 bottom-0 h-[44px] bg-background md:h-[72px]"
          style={{ clipPath: "polygon(0 100%, 100% 0, 100% 100%)" }}
        />
      </section>
    </>
  );
}

/**
 * Hoa văn 青海波 (seigaiha) — "sóng biển xanh", nền của hero.
 *
 * Mỗi nan quạt là 4 cung tròn đồng tâm; các nan xếp so le hàng chẵn/hàng lẻ.
 * Vẽ bằng NỬA cung trên (`A` một chiều) chứ không phải `<circle>`: circle vẽ cả
 * vòng, phần dưới của hàng này sẽ cắt qua phần trên của hàng dưới và ra một cái
 * lưới mắt cáo thay vì các nan chồng lên nhau.
 *
 * Ô lặp 90×90 chứa hai hàng (y=45 và y=90) lệch nhau nửa bước ngang (22.5px),
 * nên ghép ô liền mạch theo cả hai chiều. Các tâm ở ngoài rìa ô (x=-22.5,
 * x=112.5) là cố ý — chúng vá phần nan bị cắt ở mép.
 *
 * Bước 45px chứ không phải 30: ở 30 các nan bé tới mức đọc ra như một tấm lưới
 * kim tuyến chứ không phải sóng, và nó cạnh tranh với bát phở đặt ngay trên.
 */
function SeigaihaPattern() {
  const centers = [
    [0, 45],
    [45, 45],
    [90, 45],
    [-22.5, 90],
    [22.5, 90],
    [67.5, 90],
    [112.5, 90],
  ];
  const radii = [42, 30, 18, 6];

  return (
    <svg aria-hidden className="h-full w-full" role="presentation">
      <defs>
        <pattern id="seigaiha" width="90" height="90" patternUnits="userSpaceOnUse">
          <g fill="none" stroke="#ffffff" strokeOpacity="0.2" strokeWidth="1.6">
            {centers.map(([cx, cy]) =>
              radii.map((r) => (
                <path
                  key={`${cx}-${cy}-${r}`}
                  d={`M${cx - r},${cy} A${r},${r} 0 0 1 ${cx + r},${cy}`}
                />
              )),
            )}
          </g>
        </pattern>
      </defs>
      <rect width="100%" height="100%" fill="url(#seigaiha)" />
    </svg>
  );
}

/* ── Icon của khối "công phu" ─────────────────────────────────────────────────
   Vẽ tay chứ không lấy từ `lucide-react`: mockup dùng bát-bốc-khói, lá, và đũa
   gắp bánh phở — lucide không có cái thứ ba (`Utensils` là dao-nĩa, sai hẳn ngữ
   cảnh), mà trộn hai nguồn icon thì nét không đồng bộ. Giữ nguyên bộ tham số
   nét của lucide (fill none, stroke currentColor, cap/join tròn) để nếu sau này
   thay bằng icon thật của design thì chỉ đổi path. */

function BrothIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 48 48"
      className={className}
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <path d="M4 22h40c0 10.5-8.5 19-19 19h-2C12.5 41 4 32.5 4 22Z" />
      <path d="M2 22h44" />
      <path d="M17 16c0-2.4 2.6-3 2.6-5.4S17 5.8 17 5.8" />
      <path d="M24 16c0-2.4 2.6-3 2.6-5.4S24 5.8 24 5.8" />
      <path d="M31 16c0-2.4 2.6-3 2.6-5.4S31 5.8 31 5.8" />
    </svg>
  );
}

function LeafIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 48 48"
      className={className}
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <path d="M9 41c-3-14 6-31 33-33 2 25-11 35-25 35-4 0-8-2-8-2Z" />
      <path d="M9 41c5-10 13-19 26-26" />
    </svg>
  );
}

function NoodleIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 48 48"
      className={className}
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <path d="M6 28h30c0 8-6.7 14.5-15 14.5S6 36 6 28Z" />
      <path d="M4 28h34" />
      <path d="M28 4.5 45 10" />
      <path d="M25.5 10.5 42.5 16" />
      <path d="M16 24c-.5-5 2-9.5 7.5-12" />
      <path d="M22 24c-.4-3.6 1.2-6.6 4.6-8.6" />
    </svg>
  );
}
