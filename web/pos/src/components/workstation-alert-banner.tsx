import { useSyncExternalStore } from "react";
import { AlertTriangleIcon } from "lucide-react";

import { workstationAlerts, type WorkstationAlert } from "@/hooks/workstation-alerts";
import { useTranslation } from "@/providers/app-provider";

/**
 * #1806 S2 — banner sự cố của máy trạm.
 *
 * Máy trạm đã dựng và gộp alert; ở đây chỉ hiển thị. KHÔNG lọc thêm, KHÔNG đặt
 * ngưỡng riêng — một tầng quyết định thứ hai về "cái gì đáng báo" sẽ lệch khỏi
 * tầng thứ nhất, và lúc đó không ai trả lời được vì sao một sự cố có trên panel
 * máy trạm mà không có ở đây.
 *
 * Chỉ hiện `critical`. Người đứng quầy đang phục vụ khách; `warning`/`info` có
 * chỗ của chúng trên panel máy trạm và ở HQ, còn thứ được phép cắt ngang màn
 * hình bán hàng phải là thứ đang CHẶN việc bán hàng.
 */
export function WorkstationAlertBanner() {
  const { t } = useTranslation();

  const alerts = useSyncExternalStore(
    workstationAlerts.subscribe,
    () => cachedCritical,
    () => EMPTY,
  );

  if (alerts.length === 0) return null;

  return (
    <div
      role="status"
      aria-live="polite"
      data-testid="workstation-alert-banner"
      className="fixed top-2 left-3 right-3 z-50 flex items-start gap-2 rounded-md bg-destructive px-3 py-2 text-destructive-foreground shadow-lg"
    >
      <AlertTriangleIcon className="mt-0.5 size-4 shrink-0" />
      <div className="min-w-0 space-y-0.5 text-xs leading-snug">
        <p className="text-sm font-semibold">{t("workstation.alert.title")}</p>
        {alerts.map((a) => (
          <p key={`${a.kind}::${a.subject}`} className="truncate">
            {a.title}
            {/* Số lần xảy ra phân biệt một sự cố thoáng qua với một sự cố đang
                diễn ra — cùng lý do panel máy trạm hiện nó. */}
            {typeof a.count === "number" && a.count > 1 && ` ×${a.count}`}
          </p>
        ))}
      </div>
    </div>
  );
}

const EMPTY: WorkstationAlert[] = [];

/*
 * `useSyncExternalStore` so sánh THAM CHIẾU của giá trị trả về, nên getSnapshot
 * phải trả cùng một mảng khi không có gì đổi. Lọc trực tiếp trong getSnapshot
 * tạo mảng mới mỗi lần gọi và React sẽ báo "getSnapshot should be cached" rồi
 * render vô hạn — nên bộ lọc chạy trong subscribe và kết quả được giữ lại.
 */
let cachedCritical: WorkstationAlert[] = EMPTY;

workstationAlerts.subscribe((next) => {
  const critical = next.filter((a) => a.severity === "critical");
  cachedCritical = critical.length === 0 ? EMPTY : critical;
});
