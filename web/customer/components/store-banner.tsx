"use client";

import type { ReactNode } from "react";
import type { BannerSource } from "@/lib/store-banner";
import { resolveBannerSet } from "@/lib/store-banner";

/**
 * #936 — banner cửa hàng theo breakpoint.
 *
 * Admin upload 3 ảnh riêng (desktop / tablet / mobile) vì một ảnh ngang duy
 * nhất bị crop hụt trên điện thoại và trống trải trên desktop. Component này
 * dùng `<picture><source media>` để trình duyệt CHỈ tải đúng 1 ảnh cho
 * viewport hiện tại — không render cả 3 rồi ẩn bằng CSS (làm vậy tốn băng
 * thông gấp 3 trên mobile, đúng nơi cần tiết kiệm nhất).
 *
 * Breakpoint khớp Tailwind: lg = 1024px, md = 768px.
 */

/** Ngưỡng phải khớp `md`/`lg` của Tailwind — dùng cho cả media query lẫn class. */
const DESKTOP_MIN_PX = 1024;
const TABLET_MIN_PX = 768;

// #1198 — the fallback chain and its types live in lib/store-banner.ts so
// `node --test` can actually reach them: this file is .tsx and the runner
// strips types but cannot transform JSX, so a test importing from here dies
// on ERR_MODULE_NOT_FOUND. Re-exported so every existing import site keeps
// working unchanged.
export type { BannerSet, BannerSource } from "@/lib/store-banner";
export { hasBanner, resolveBannerSet } from "@/lib/store-banner";

export interface StoreBannerProps {
  branch: BannerSource & { name: string };
  /**
   * Class cho wrapper. Chỉ cần khi muốn ép khung (`fit="cover"`) hoặc thêm
   * nền/bo góc — mặc định khung tự co theo đúng tỉ lệ ảnh nên KHÔNG cần
   * truyền class tỉ lệ nữa.
   */
  className?: string;
  /**
   * `natural` (mặc định) — khung cao theo đúng tỉ lệ thật của ảnh, không cắt
   * mất phần nào. `cover` — ép ảnh lấp đầy khung do `className` quy định
   * (cắt phần thừa); chỉ dùng khi layout bắt buộc chiều cao cố định.
   */
  fit?: "natural" | "cover";
  /** Nội dung khi shop chưa có banner nào (null = không render gì). */
  fallback?: ReactNode;
  /** Overlay/nội dung đè lên ảnh (gradient + tên shop ở hero). */
  children?: ReactNode;
  /** `loading="eager"` cho banner nằm ngay đầu trang (LCP). */
  priority?: boolean;
}

/**
 * Chỉ dùng cho ô trống khi shop CHƯA upload ảnh nào — lúc đó không có tỉ lệ
 * thật để bám theo nên vẫn cần một khung dựng sẵn.
 */
const DEFAULT_RATIO_CLASS = "aspect-[3/2] md:aspect-[8/3] lg:aspect-[4/1]";

/**
 * Chặn ảnh dựng đứng (admin lỡ upload ảnh portrait) chiếm trọn màn hình ở chế
 * độ `natural`. Banner đúng tỉ lệ (ngang) không bao giờ chạm ngưỡng này.
 */
const NATURAL_MAX_HEIGHT = "max-h-[70vh]";

export default function StoreBanner({
  branch,
  className,
  fit = "natural",
  fallback = null,
  children,
  priority = false,
}: StoreBannerProps) {
  const { desktop, tablet, mobile } = resolveBannerSet(branch);
  const cover = fit === "cover";

  // `mobile` là đáy chuỗi fallback → null nghĩa là không có ảnh nào cả.
  if (!mobile) {
    return fallback === null ? null : (
      <div
        data-slot="store-banner"
        className={`relative w-full overflow-hidden bg-muted ${className ?? ""} ${cover ? "" : DEFAULT_RATIO_CLASS}`}
      >
        {fallback}
        {children}
      </div>
    );
  }

  return (
    <div
      data-slot="store-banner"
      className={`relative w-full overflow-hidden bg-muted ${className ?? ""}`}
    >
      {/* `display: contents` ở chế độ natural để <picture> (inline) không đẻ
          ra khoảng trắng baseline vài px ngay dưới ảnh. */}
      <picture className={cover ? undefined : "contents"}>
        {/* Chỉ phát <source> khi ảnh của breakpoint đó KHÁC ảnh dưới nó —
            source trùng chỉ làm nặng markup, không đổi kết quả. */}
        {desktop && desktop !== tablet && (
          <source media={`(min-width: ${DESKTOP_MIN_PX}px)`} srcSet={desktop} />
        )}
        {tablet && tablet !== mobile && (
          <source media={`(min-width: ${TABLET_MIN_PX}px)`} srcSet={tablet} />
        )}
        {/* `<img>` chứ không phải next/image: đây là 3 URL từ object storage
            đã đúng kích thước cho từng breakpoint, và next/image không hỗ trợ
            art-direction qua <source media>. */}
        {/* `natural`: ảnh tự quyết chiều cao khung → giữ đúng tỉ lệ file,
            không cắt phần nào. Ảnh admin upload hiếm khi khớp tỉ lệ khuyến
            nghị (banner desktop thật 1440×300 = 4.8:1 trong khi khung cũ ép
            4:1) — `object-cover` khi đó phóng ảnh 1.6× rồi xén hai mép trái
            phải, đúng phần bày món ăn. */}
        <img
          src={mobile}
          alt={branch.name}
          loading={priority ? "eager" : "lazy"}
          decoding="async"
          className={
            cover
              ? "absolute inset-0 h-full w-full object-cover"
              : `block h-auto w-full object-contain ${NATURAL_MAX_HEIGHT}`
          }
        />
      </picture>
      {children}
    </div>
  );
}
