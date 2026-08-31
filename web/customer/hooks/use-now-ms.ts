"use client";

import { useEffect, useState } from "react";

/**
 * Mốc "bây giờ" (ms) dùng được trong lúc render.
 *
 * Đọc thẳng `Date.now()` trong thân component là không thuần khiết — giá trị
 * đổi giữa hai lần render mà React không hề biết, nên nó có thể hiển thị một
 * đằng và tính một nẻo. Giữ trong state và tự nhích theo nhịp cố định thì
 * render là hàm của state, và màn hình tự cập nhật mà không cần khách reload.
 *
 * Nhịp mặc định 60s hợp với hai chỗ đang dùng: hạn thanh toán còn hay hết, và
 * ETA bếp còn mấy phút. Riêng đồng hồ đếm ngược từng giây có
 * `OrderCountdownBadge` lo.
 */
export function useNowMs(intervalMs = 60_000): number {
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), intervalMs);
    return () => clearInterval(id);
  }, [intervalMs]);

  return now;
}
