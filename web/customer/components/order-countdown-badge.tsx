"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { Clock } from "lucide-react";
import { computeSecondsLeft } from "@/lib/order-expiry";

interface OrderCountdownBadgeProps {
  paymentDueAt: string; // ISO 8601 timestamp
  /**
   * plan-031 — server `seconds_until_due`. When present the countdown is
   * anchored to this delta (immune to a skewed client clock) instead of the
   * raw `paymentDueAt - now` subtraction.
   */
  secondsUntilDue?: number | null;
  compact?: boolean; // If true, show only MM:SS without label
  /** Optional callback — dùng ở trang /orders để reconcile đơn khi hết hạn. */
  onExpired?: () => void;
}

/**
 * Compact countdown badge for orders list (plan-031).
 * 
 * Displays time remaining in MM:SS format with color coding.
 * Smaller version of TakeawayCountdownTimer for use in order cards.
 */
export default function OrderCountdownBadge({
	  paymentDueAt,
	  secondsUntilDue,
	  compact = false,
	  onExpired,
	}: OrderCountdownBadgeProps) {
	  const t = useTranslations("guestOrders");
	  const [secondsLeft, setSecondsLeft] = useState<number | null>(null);
	  const anchoredAtRef = useRef<number>(0);

  // Calculate initial seconds left
	  useEffect(() => {
	    anchoredAtRef.current = Date.now();
	    setSecondsLeft(
	      computeSecondsLeft({
	        secondsUntilDue,
	        paymentDueAt,
	        anchoredAtMs: anchoredAtRef.current,
	        nowMs: anchoredAtRef.current,
	      }),
	    );
	  }, [paymentDueAt, secondsUntilDue]);

  // Countdown tick every second
	  useEffect(() => {
	    if (secondsLeft === null || secondsLeft <= 0) return;

	    const timer = setInterval(() => {
	      setSecondsLeft((prev) => {
	        if (prev === null) return prev;
	        return Math.max(0, prev - 1);
	      });
	    }, 1000);

	    return () => clearInterval(timer);
	  }, [secondsLeft]);

	  // Khi countdown về 0 → gọi callback (nếu có). Dùng ở /orders để auto xoá đơn
	  // khỏi tab "Chưa thanh toán". secondsLeft = null: chưa tính xong, KHÔNG gọi.
	  // secondsLeft = 0 sau khi tính diff hoặc sau countdown → gọi onExpired.
	  useEffect(() => {
	    if (!onExpired) return;
	    if (secondsLeft === 0) {
	      onExpired();
	    }
	  }, [secondsLeft, onExpired]);

  // Format seconds to MM:SS
	  const formatTime = (seconds: number): string => {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins.toString().padStart(2, "0")}:${secs.toString().padStart(2, "0")}`;
	  };

	  // Color coding based on time left
	  const getColor = (seconds: number): string => {
    const minutes = seconds / 60;

    if (minutes > 10) {
      return "#737373"; // neutral
    } else if (minutes > 5) {
      return "#D97706"; // orange warning
    } else {
      return "#DC2626"; // red urgent
    }
	  };

	  if (secondsLeft === null) {
	    // Đang tính thời gian còn lại – tránh chớp 00:00
	    return null;
	  }

	  const color = getColor(secondsLeft);
	  const isExpired = secondsLeft === 0;

  if (isExpired) {
    return (
      <span
        className="inline-flex items-center gap-1 text-xs font-medium"
        style={{ color: "#DC2626" }}
      >
        <Clock size={14} />
        {t("countdownExpired")}
      </span>
    );
  }

  if (compact) {
    return (
      <span
        className="inline-flex items-center gap-1 text-xs font-semibold tabular-nums"
        style={{ color }}
      >
        <Clock size={14} />
        {formatTime(secondsLeft)}
      </span>
    );
  }

  return (
    <span
      className="inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-xs font-medium tabular-nums"
      style={{
        color,
        backgroundColor: color === "#DC2626" ? "#FEE2E2" : color === "#D97706" ? "#FEF3C7" : "#F5F5F5",
      }}
    >
      <Clock size={12} />
      {formatTime(secondsLeft)}
    </span>
  );
}
