"use client";

import { useTimezone, useTranslation } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
import type { Device } from "@/types/models/Device";

/**
 * #2900 — số phiên bản LUÔN đi kèm nguồn của nó.
 *
 * Backend (`DeviceResource`) trả ba trạng thái, và chúng KHÔNG đáng tin như
 * nhau. Đo trên production 2026-08-15: **12/19 máy** mang số có nguồn `pairing`
 * — tức giá trị đóng dấu lúc ghép cặp rồi không bao giờ cập nhật. Chỉ 3 máy có
 * số `heartbeat` phản ánh hiện trạng.
 *
 * Nên hiện "0.1.0" trơn là dựng ra một con số trông như phép đo để người vận
 * hành ra quyết định — cùng lớp lỗi với `received_by_id` mang UUID toàn số 0
 * (#2863). Ở đây chọn hướng ngược lại: số nào không phải hiện trạng thì phải
 * nhìn ra ngay, kể cả khi liếc nhanh.
 *
 * Dùng chung cho cả bảng Devices của shop lẫn HQ — một định nghĩa, không chép
 * đôi rồi lệch nhau.
 */
export type DeviceAppVersionCellProps = {
  device: Device;
};

export function DeviceAppVersionCell({ device }: DeviceAppVersionCellProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  const version = device.app_version;
  const source = device.app_version_source ?? "unknown";

  // Chưa bao giờ báo gì ⇒ em dash, đúng quy ước bảng của repo. KHÔNG hiện "0.0.0"
  // hay "chưa rõ" như thể đó là một giá trị.
  if (!version || source === "unknown") {
    return (
      <span data-slot="device-app-version-cell" className="text-xs text-muted-foreground">
        —
      </span>
    );
  }

  if (source === "heartbeat") {
    return (
      <div data-slot="device-app-version-cell" className="flex flex-col">
        <span className="text-xs tabular-nums">{version}</span>
        {device.app_version_seen_at && (
          <span className="text-xs text-muted-foreground">
            {formatDateTime(device.app_version_seen_at, locale, timezone)}
          </span>
        )}
      </div>
    );
  }

  // `pairing` — con số CÓ, nhưng nó là phỏng đoán. Làm mờ số và nói thẳng lý do
  // ngay dưới, thay vì một badge nhỏ dễ lướt qua.
  return (
    <div data-slot="device-app-version-cell" className="flex flex-col">
      <span className="text-xs tabular-nums text-muted-foreground line-through">
        {version}
      </span>
      <span className="text-xs text-destructive">
        {t("shop.devices.version.pairing_only")}
      </span>
    </div>
  );
}
