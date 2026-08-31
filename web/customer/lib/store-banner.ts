import type { Branch } from "@/data/brands";

/**
 * #936 — chuỗi fallback ảnh banner theo breakpoint.
 *
 * Sống ở `lib/` chứ không nằm trong component (#1198): đây là logic thuần, và
 * `components/store-banner.tsx` là `.tsx` — `node --test` strip được type nhưng
 * KHÔNG transform JSX, nên mọi test import từ component đó đều chết ở
 * ERR_MODULE_NOT_FOUND. Test cũ vì thế chưa từng chạy một lần nào.
 */

export interface BannerSet {
  desktop: string | null;
  tablet: string | null;
  mobile: string | null;
}

export type BannerSource = Pick<
  Branch,
  "img_branches" | "banner_desktop" | "banner_tablet" | "banner_mobile"
>;

/**
 * Giải chuỗi fallback cho từng breakpoint. Backend trả giá trị thô (null khi
 * chưa upload) nên client tự fallback: một response phải phục vụ mọi viewport.
 *
 * Ưu tiên đi từ ảnh nhỏ lên ảnh lớn — thiếu ảnh mobile thì mượn tablet rồi
 * desktop, và `img_branches` (banner cũ, trước #936) là đáy chuỗi để shop chưa
 * upload gì vẫn hiển thị y như trước.
 */
export function resolveBannerSet(branch: BannerSource): BannerSet {
  const legacy = branch.img_branches ?? null;
  const desktop = branch.banner_desktop ?? legacy;
  const tablet = branch.banner_tablet ?? desktop;
  const mobile = branch.banner_mobile ?? tablet;

  return { desktop, tablet, mobile };
}

/** true khi có ít nhất 1 ảnh để hiển thị (dùng để bỏ hẳn block banner). */
export function hasBanner(branch: BannerSource): boolean {
  return resolveBannerSet(branch).mobile !== null;
}
