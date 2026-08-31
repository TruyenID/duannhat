"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Clock } from "lucide-react";

interface TakeawayCountdownTimerProps {
  paymentDueAt: string; // ISO 8601 timestamp
  onExpire?: () => void;
}

/**
 * Takeaway order payment countdown timer (plan-031).
 * 
 * Displays time remaining until payment_due_at in MM:SS format.
 * Color-coded: neutral (>10min) → orange (5-10min) → red (<5min).
 * Auto-updates every second. Calls onExpire when timer reaches 0.
 */
export default function TakeawayCountdownTimer({
  paymentDueAt,
  onExpire,
}: TakeawayCountdownTimerProps) {
  const t = useTranslations("orderSuccess");
  const [secondsLeft, setSecondsLeft] = useState<number>(0);

  // Calculate initial seconds left
  useEffect(() => {
    const dueTime = new Date(paymentDueAt).getTime();
    const now = Date.now();
    const diff = Math.max(0, Math.floor((dueTime - now) / 1000));
    setSecondsLeft(diff);
  }, [paymentDueAt]);

  // Countdown tick every second
  useEffect(() => {
    if (secondsLeft <= 0) {
      // Nếu ngay khi mở trang mà đã hết hạn (secondsLeft = 0)
      // thì chỉ hiển thị trạng thái hết hạn, KHÔNG tự reload.
      // onExpire chỉ được gọi khi timer đang chạy và đếm về 0.
      return;
    }

    const timer = setInterval(() => {
      setSecondsLeft((prev) => {
        const next = Math.max(0, prev - 1);
        if (next === 0) {
          onExpire?.();
        }
        return next;
      });
    }, 1000);

    return () => clearInterval(timer);
  }, [secondsLeft, onExpire]);

  // Format seconds to MM:SS
  const formatTime = (seconds: number): string => {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins.toString().padStart(2, "0")}:${secs.toString().padStart(2, "0")}`;
  };

  // Color coding based on time left
  const getColorScheme = (seconds: number) => {
    const minutes = seconds / 60;

    if (minutes > 10) {
      // Neutral (>10min)
      return {
        bg: "#F5F5F5",
        border: "#E5E5E5",
        text: "#525252",
        icon: "#737373",
      };
    } else if (minutes > 5) {
      // Warning (5-10min)
      return {
        bg: "#FEF3C7",
        border: "#FDE68A",
        text: "#92400E",
        icon: "#D97706",
      };
    } else {
      // Urgent (<5min)
      return {
        bg: "#FEE2E2",
        border: "#FECACA",
        text: "#991B1B",
        icon: "#DC2626",
      };
    }
  };

  const colors = getColorScheme(secondsLeft);
  const isExpired = secondsLeft === 0;

  return (
    <div
      className="rounded-lg border p-3 flex gap-2.5 items-center"
      style={{
        backgroundColor: colors.bg,
        borderColor: colors.border,
      }}
    >
      {/* Clock Icon */}
      <Clock
        className="flex-shrink-0"
        size={20}
        style={{ color: colors.icon }}
      />

      {/* Timer Content */}
      <div className="flex-1">
        <p
          className="text-xs font-medium mb-0.5"
          style={{ color: colors.text }}
        >
          {isExpired ? t("paymentExpired") : t("paymentDueIn")}
        </p>
        <p
          className="text-lg font-bold tabular-nums"
          style={{ color: colors.text, lineHeight: "24px" }}
        >
          {isExpired ? "--:--" : formatTime(secondsLeft)}
        </p>
      </div>
    </div>
  );
}
