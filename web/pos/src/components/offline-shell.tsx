/**
 * #1170 tầng 1 — đăng ký service worker + lời mời nạp lại khi có bản mới.
 *
 * Hai việc, và chỉ hai việc:
 *
 *   1. Đăng ký SW từ CODE APP, không để plugin chèn script vào index.html.
 *      `index.html` khai `script-src 'self'` (không 'unsafe-inline'), nên một
 *      script đăng ký kiểu inline sẽ bị CSP chặn — và hỏng theo kiểu tệ nhất:
 *      im lặng, trang vẫn chạy, chỉ là không bao giờ có offline. Đi qua bundle
 *      thì hợp lệ với CSP.
 *
 *   2. Bản mới thì HỎI, không tự nạp lại. Đây là máy tính tiền: tự reload giữa
 *      lúc thu ngân đang gõ đơn là mất việc đang làm. `registerType: "prompt"`
 *      giữ SW mới ở trạng thái waiting cho tới khi người bấm — cùng họ với
 *      version-skew P-19 của plan-052.
 *
 * KHÔNG phải offline-sales. Xem ranh giới ở #1170/#1169: tầng này chỉ chống
 * màn hình trắng. Mọi `/api/**` là NetworkOnly ở tầng service worker.
 *
 * Bản workstation: plugin chạy với `disable: true` nên `useRegisterSW` là stub
 * không đăng ký gì — component này mount vô hại ở cả hai bản phân phối.
 */
import { useEffect } from "react";
import { toast } from "sonner";
import { useRegisterSW } from "virtual:pwa-register/react";
import { useTranslation } from "@/providers/app-provider";

const UPDATE_TOAST_ID = "sw-update-available";

export function OfflineShell() {
  const { t } = useTranslation();
  const {
    needRefresh: [needRefresh, setNeedRefresh],
    updateServiceWorker,
  } = useRegisterSW({
    onRegisterError(error: unknown) {
      // Không nuốt: SW không đăng ký được nghĩa là offline shell không tồn tại,
      // và triệu chứng chỉ hiện ra khi đã mất mạng — lúc đó thì muộn.
      console.error("[pwa] service worker registration failed", error);
    },
  });

  useEffect(() => {
    if (!needRefresh) return;
    // Không tự tắt: người phải chủ động chọn, nên duration Infinity.
    toast(t("pwa.update.title"), {
      id: UPDATE_TOAST_ID,
      description: t("pwa.update.description"),
      duration: Infinity,
      action: {
        label: t("pwa.update.action"),
        onClick: () => {
          void updateServiceWorker(true);
        },
      },
      onDismiss: () => setNeedRefresh(false),
    });
  }, [needRefresh, setNeedRefresh, updateServiceWorker, t]);

  return null;
}
