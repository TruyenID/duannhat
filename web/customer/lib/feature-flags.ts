/**
 * Feature flags — bật/tắt các mảng tính năng đang tạm ẩn khỏi Customer Web.
 *
 * Đây là flag build-time (hằng số), KHÔNG phải remote config: đổi `false` →
 * `true` rồi rebuild là khôi phục đầy đủ tính năng cũ. Code của các tính năng
 * bị ẩn được giữ nguyên, không xoá.
 *
 * 📖 Danh sách đầy đủ từng điểm đã ẩn (file:line) + nợ kỹ thuật khi bật lại:
 *    docs/HIDDEN-FEATURES.md
 *
 * Import được ở cả middleware (edge runtime), Server Component và Client
 * Component — module này không phụ thuộc runtime nào.
 */
export const FEATURES = {
  /** Luồng đặt bàn (`/booking`). Xem godx-tempo-customer-web#47. */
  booking: false,
  /** Login / Register / Account (`/login`, `/register`, `/account/*`). */
  auth: true,
  /**
   * Nút/link MỜI khách vãng lai đăng nhập hoặc đăng ký.
   *
   * Khác `auth` ở chỗ nó chỉ tắt phần CHÀO MỜI, không tắt tính năng:
   * `/login/{shop}` · `/register/{shop}` · `/account/*` vẫn vào được bằng link
   * trực tiếp, khách đang đăng nhập vẫn giữ nguyên phiên, chip tài khoản và
   * mục Đăng xuất vẫn còn. Lật `auth` sang false thì mới chặn route và ép mọi
   * request về guest — hai việc khác nhau, đừng gộp.
   *
   * Tắt theo yêu cầu chủ dự án: khách không được thấy lời mời đăng nhập ở bất
   * cứ trang nào. Danh sách đầy đủ điểm chịu ảnh hưởng: `docs/HIDDEN-FEATURES.md`.
   */
  authEntryPoints: false,
} as const;
