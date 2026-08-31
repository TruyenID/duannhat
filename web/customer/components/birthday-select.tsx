"use client";

import { useMemo } from "react";
import { ChevronDownIcon } from "lucide-react";
import {
  type BirthdayParts,
  type BirthdayUnit,
  birthYearOptions,
  birthdayFieldOrder,
  clampBirthday,
  daysInMonth,
} from "@/lib/birthday";

/**
 * `<select>` gốc chứ không phải popup tự dựng: trên điện thoại nó mở bánh xe
 * chọn của hệ điều hành — cách chọn năm sinh trong danh sách 121 mục nhanh hơn
 * hẳn một danh sách cuộn trong trang — và bàn phím/trình đọc màn hình đã hiểu
 * sẵn nó mà không cần thêm dòng ARIA nào.
 */
const SELECT_CLASS =
  "h-11 w-full min-w-0 appearance-none rounded-lg border border-neutral-300 bg-white py-1 pr-8 pl-3.5 text-base transition-colors outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 md:text-sm";

/** Ô tháng rộng hơn vì nó chứa chữ ("September", "tháng 9"), hai ô kia chứa số. */
const UNIT_BASIS: Record<BirthdayUnit, string> = {
  day: "flex-[0.85]",
  month: "flex-[1.4]",
  year: "flex-[1.1]",
};

interface BirthdaySelectProps {
  value: BirthdayParts;
  onChange: (next: BirthdayParts) => void;
  /** Ngôn ngữ đang hiển thị — quyết định thứ tự ba ô và tên tháng. */
  locale: string;
  /** Nhãn placeholder của từng ô, đã dịch. */
  labels: Record<BirthdayUnit, string>;
  invalid?: boolean;
  disabled?: boolean;
  /** Gắn ô đầu tiên vào `<label>` của nhóm. */
  firstId?: string;
  describedBy?: string;
}

export default function BirthdaySelect({
  value,
  onChange,
  locale,
  labels,
  invalid,
  disabled,
  firstId,
  describedBy,
}: BirthdaySelectProps) {
  const order = useMemo(() => birthdayFieldOrder(locale), [locale]);

  // Tên tháng lấy từ Intl, không phải 12 khoá dịch: đúng cho mọi ngôn ngữ mà
  // messages/*.json không phải nuôi thêm 36 dòng.
  const monthNames = useMemo(() => {
    const fmt = new Intl.DateTimeFormat(locale, { month: "long", timeZone: "UTC" });
    return Array.from({ length: 12 }, (_, i) => {
      const name = fmt.format(new Date(Date.UTC(2000, i, 1)));
      // vi cho ra "tháng 1" — viết hoa để đứng cạnh nhãn khác không lệch.
      return name.charAt(0).toUpperCase() + name.slice(1);
    });
  }, [locale]);

  const years = useMemo(() => birthYearOptions(new Date().getFullYear()), []);
  const dayCount = daysInMonth(Number(value.month) || null, Number(value.year) || null);

  function update(unit: BirthdayUnit, raw: string) {
    // `clampBirthday` chạy sau MỌI thay đổi, không chỉ khi đổi tháng: chọn 29
    // rồi đổi năm sang 2003 cũng làm ngày đó biến mất.
    onChange(clampBirthday({ ...value, [unit]: raw }));
  }

  const options: Record<BirthdayUnit, Array<{ value: string; label: string }>> = {
    day: Array.from({ length: dayCount }, (_, i) => ({
      value: String(i + 1),
      label: String(i + 1),
    })),
    month: monthNames.map((label, i) => ({ value: String(i + 1), label })),
    year: years.map((y) => ({ value: String(y), label: String(y) })),
  };

  return (
    <div className="flex gap-2">
      {order.map((unit, index) => (
        <div key={unit} className={`relative ${UNIT_BASIS[unit]}`}>
          <select
            id={index === 0 ? firstId : undefined}
            aria-label={labels[unit]}
            aria-invalid={invalid || undefined}
            aria-describedby={describedBy}
            disabled={disabled}
            value={value[unit]}
            onChange={(e) => update(unit, e.target.value)}
            className={`${SELECT_CLASS} ${value[unit] ? "text-neutral-900" : "text-neutral-400"}`}
          >
            {/* Chọn lại mục rỗng ở CẢ BA ô = xoá ngày sinh đã khai; để trống một
                ô thôi thì form chặn submit chứ không đoán phần còn thiếu. */}
            <option value="">{labels[unit]}</option>
            {options[unit].map((opt) => (
              <option key={opt.value} value={opt.value} className="text-neutral-900">
                {opt.label}
              </option>
            ))}
          </select>
          <ChevronDownIcon className="pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-neutral-400" />
        </div>
      ))}
    </div>
  );
}
