/**
 * #1501 — dải báo mất kết nối.
 *
 * Nó phải nói được BA điều, thiếu điều nào cũng làm nó vô dụng:
 *
 *   1. đang mất kết nối;
 *   2. **dữ liệu trên màn hình cũ tới lúc nào** — một màn POS hiện số liệu cũ
 *      mà không nói là cũ thì tệ hơn màn báo lỗi, vì thu ngân vẫn tin nó;
 *   3. **cái gì đã bị khoá** — thanh toán, mở/đóng ca. Nguyên tắc P-27 của
 *      plan-052: không để nút "bấm → chết im".
 *
 * Vị trí: `fixed` ở góc dưới, KHÔNG phải dải trên cùng. Màn POS là
 * `h-screen … overflow-hidden` (`src/app/pos/page.tsx`), nên một dải trên cùng
 * hoặc đè lên header hoặc đẩy đáy trang ra ngoài vùng nhìn thấy. Ở mobile nó
 * ngồi cao hơn `MobileCartDock` (`bottom-4`, z-30) để không chồng.
 */
import { WifiOffIcon } from "lucide-react";
import { formatTime } from "@/lib/format-date";
import { isOffline, useNetworkStatus } from "@/lib/network-status";
import { useTranslation } from "@/providers/app-provider";

export function OfflineBanner() {
  const { t, locale } = useTranslation();
  const status = useNetworkStatus();

  if (!isOffline(status)) return null;

  return (
    <div
      role="status"
      aria-live="polite"
      data-testid="offline-banner"
      className="fixed bottom-[max(4.5rem,env(safe-area-inset-bottom))] left-3 right-3 z-40 flex items-start gap-2.5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2.5 text-amber-900 shadow-lg sm:right-auto sm:max-w-md lg:bottom-[max(1rem,env(safe-area-inset-bottom))] dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
    >
      <WifiOffIcon className="mt-0.5 size-4 shrink-0" />
      <div className="min-w-0 space-y-0.5 text-xs leading-snug">
        <p className="text-sm font-semibold">{t("offline.banner.title")}</p>
        <p>
          {status.lastSyncedAt === null
            ? t("offline.banner.no_data")
            : t("offline.banner.data_as_of", {
                time: formatTime(new Date(status.lastSyncedAt), locale),
              })}
        </p>
        <p className="opacity-90">{t("offline.banner.blocked")}</p>
      </div>
    </div>
  );
}
