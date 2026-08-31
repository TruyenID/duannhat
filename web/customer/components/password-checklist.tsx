"use client";

import { useTranslations } from "next-intl";
import { Check } from "lucide-react";
import {
  PASSWORD_MIN_LENGTH,
  PASSWORD_RULE_KEYS,
  checkPasswordPolicy,
} from "@/lib/password-policy";
import { cn } from "@/lib/utils";

interface PasswordChecklistProps {
  /** Giá trị đang gõ trong ô mật khẩu. */
  value: string;
}

/**
 * Bốn điều kiện của chính sách mật khẩu, sáng lên khi đạt (#1780).
 *
 * Hiện LUÔN LUÔN, kể cả khi ô còn trống, chứ không chỉ hiện lúc gõ sai: khách
 * cần biết luật TRƯỚC khi nghĩ ra mật khẩu, không phải sau khi bị từ chối.
 *
 * `aria-live="polite"` để người dùng screen reader nghe được điều kiện vừa đạt
 * — không có nó thì checklist là thông tin chỉ dành cho người nhìn thấy màu.
 */
export default function PasswordChecklist({ value }: PasswordChecklistProps) {
  const t = useTranslations("register");
  const state = checkPasswordPolicy(value);

  return (
    <ul
      aria-live="polite"
      className="grid grid-cols-1 gap-x-4 gap-y-1.5 pt-1 sm:grid-cols-2"
    >
      {PASSWORD_RULE_KEYS.map((rule) => {
        const met = state[rule];
        return (
          <li key={rule} className="flex items-center gap-2 text-xs">
            <span
              className={cn(
                "flex size-3.5 shrink-0 items-center justify-center rounded-full transition-colors",
                met ? "bg-primary text-primary-foreground" : "bg-muted-foreground/25",
              )}
            >
              {met && <Check className="size-2.5" strokeWidth={4} />}
            </span>
            <span className={met ? "text-foreground" : "text-muted-foreground"}>
              {t(`passwordRule.${rule}`, { min: PASSWORD_MIN_LENGTH })}
            </span>
          </li>
        );
      })}
    </ul>
  );
}
