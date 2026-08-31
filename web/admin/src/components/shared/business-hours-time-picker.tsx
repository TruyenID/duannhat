"use client";

import { useState, useEffect } from "react";
import { Input } from "@godxjp/ui";

export interface BusinessHoursTimePickerProps {
  /** Chuỗi "HH:MM - HH:MM" lưu trong DB (vd: "11:00 - 22:00"). */
  value: string;
  /** Callback khi user thay đổi giờ mở hoặc đóng — nhận string đã format. */
  onChange: (value: string) => void;
}

/**
 * Time picker cho field business_hours của shop. 2 ô `<input type="time">`
 * (mở + đóng) thay vì 1 ô text free-form → UX dễ hơn, không sai format.
 *
 * Lưu vào DB / form parent dưới dạng string "HH:MM - HH:MM" để backward compat
 * với schema hiện có. Internal state giữ open/close độc lập → khi user chỉ
 * mới nhập 1 nửa, giá trị còn lại không bị reset (fix bug cũ với parse regex
 * strict mỗi render).
 */
export function BusinessHoursTimePicker({ value, onChange }: BusinessHoursTimePickerProps) {
  // Parse value ban đầu — chấp nhận cả partial "HH:MM" hoặc full "HH:MM - HH:MM".
  // Optional ở cả 2 vế của regex để match: "", "HH:MM", "HH:MM - HH:MM",
  // " - HH:MM" (rare nhưng vẫn handle được).
  const parsed = (() => {
    const v = value.trim();
    const m = v.match(/^(\d{1,2}:\d{2})?\s*[-–]?\s*(\d{1,2}:\d{2})?$/);
    return {
      open: m?.[1] ?? "",
      close: m?.[2] ?? "",
    };
  })();

  const [openTime, setOpenTime] = useState(parsed.open);
  const [closeTime, setCloseTime] = useState(parsed.close);

  // Sync khi parent reset value (vd: dialog mở lại với shop khác).
  useEffect(() => {
    setOpenTime(parsed.open);
    setCloseTime(parsed.close);
    // Chỉ sync khi `value` từ parent đổi — nội bộ openTime/closeTime đổi
    // qua handler không cần sync ngược.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);

  const formatHours = (open: string, close: string) =>
    open && close ? `${open} - ${close}` : open || close;

  const handleOpenChange = (v: string) => {
    setOpenTime(v);
    onChange(formatHours(v, closeTime));
  };

  const handleCloseChange = (v: string) => {
    setCloseTime(v);
    onChange(formatHours(openTime, v));
  };

  return (
    <div data-slot="business-hours-time-picker" className="flex items-center gap-1.5">
      <Input
        type="time"
        className="h-8 w-24 text-xs"
        value={openTime}
        onChange={(e: React.ChangeEvent<HTMLInputElement>) => handleOpenChange(e.target.value)}
      />
      <span className="text-xs text-muted-foreground">–</span>
      <Input
        type="time"
        className="h-8 w-24 text-xs"
        value={closeTime}
        onChange={(e: React.ChangeEvent<HTMLInputElement>) => handleCloseChange(e.target.value)}
      />
    </div>
  );
}
