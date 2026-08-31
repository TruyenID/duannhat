import { useSyncExternalStore } from "react";

import { useTranslation } from "@/i18n";
import {
  workstationAlerts,
  type WorkstationAlert,
} from "@/services/realtime/workstation-alerts";

/**
 * #1806 S2 — banner sự cố máy trạm, bản KDS.
 *
 * Bếp nhìn màn hình từ xa, tay bận, và không ai đứng đó để bấm. Nên bản này
 * khác bản pos-web ở đúng hai chỗ: **chữ to hơn** và **không có nút nào**. Mọi
 * thao tác xử lý đều ở máy trạm hoặc ở quầy.
 *
 * Chỉ hiện `critical` — cùng lý do như pos-web: thứ được phép chiếm chỗ trên
 * màn hình đang điều phối món phải là thứ đang chặn việc nấu hoặc việc bán.
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
      className="mb-2 rounded-md bg-red-600 px-4 py-3 text-white"
    >
      <p className="text-base font-bold">{t("workstation.alert.title")}</p>
      {alerts.map((a) => (
        <p key={`${a.kind}::${a.subject}`} className="text-sm">
          {a.title}
          {typeof a.count === "number" && a.count > 1 && ` ×${a.count}`}
        </p>
      ))}
    </div>
  );
}

const EMPTY: WorkstationAlert[] = [];

/*
 * getSnapshot phải trả CÙNG tham chiếu khi không có gì đổi — lọc trực tiếp
 * trong đó tạo mảng mới mỗi lần gọi và React sẽ render vô hạn.
 */
let cachedCritical: WorkstationAlert[] = EMPTY;

workstationAlerts.subscribe((next) => {
  const critical = next.filter((a) => a.severity === "critical");
  cachedCritical = critical.length === 0 ? EMPTY : critical;
});
