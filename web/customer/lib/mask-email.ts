/**
 * Che bớt địa chỉ email để hiện trên màn nhập mã xác thực.
 *
 * `vananh@gmail.com` → `v****h@gmail.com`.
 *
 * Vì sao che: màn nhập mã hiện ra ngay sau khi đăng ký, tức là trên một máy có
 * thể đang ở giữa quán. Địa chỉ đầy đủ nằm chình ình trên màn hình là thứ người
 * đứng sau đọc được — trong khi thứ khách CẦN chỉ là "đúng hộp thư của mình
 * chưa", và vài ký tự đầu-cuối đã đủ trả lời.
 *
 * Tên miền KHÔNG che: `@gmail.com` mới là chỉ dẫn hữu ích nhất ("à, mình đăng
 * ký bằng Gmail chứ không phải mail công ty"), và nó không định danh ai cả.
 *
 * Module thuần (không React, không `next/*`) để `node --test` chạy thẳng.
 */

/** Số dấu `*` tối đa. Tên hộp thư dài không được đẩy dòng chữ tràn ra ngoài. */
const MAX_STARS = 8;

export function maskEmail(email: string): string {
  const trimmed = email.trim();
  const at = trimmed.lastIndexOf('@');

  // Không phải địa chỉ (không có `@`, hoặc `@` đứng đầu) thì trả nguyên chuỗi
  // thay vì dựng ra một thứ trông như email mà không phải: hàm này chỉ để
  // HIỂN THỊ, và một giá trị lạ đã là dấu hiệu cần nhìn thấy nguyên trạng.
  if (at <= 0) return trimmed;

  const local = trimmed.slice(0, at);
  const domain = trimmed.slice(at);

  // Tên hộp thư 1–2 ký tự: giữ ký tự đầu, còn lại che hết. Giữ cả đầu lẫn cuối
  // của một chuỗi 2 ký tự thì chẳng che được gì.
  if (local.length <= 2) {
    return `${local[0]}${'*'.repeat(Math.max(1, local.length - 1))}${domain}`;
  }

  const stars = Math.min(local.length - 2, MAX_STARS);

  return `${local[0]}${'*'.repeat(stars)}${local[local.length - 1]}${domain}`;
}
