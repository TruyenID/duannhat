"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { computeSecondsLeft } from "@/lib/order-expiry";

interface PaymentWarningBannerProps {
	  paymentDueAt: string; // ISO 8601 timestamp
	  /**
	   * plan-031 — server `seconds_until_due`. When present the countdown is
	   * anchored to this delta (immune to a skewed client clock) instead of the
	   * raw `paymentDueAt - now` subtraction.
	   */
	  secondsUntilDue?: number | null;
	  onExpired?: () => void;
	}

/**
 * Payment warning banner for unpaid takeaway orders (plan-031).
 * 
 * Displays a red alert box with warning text and dynamic countdown timer.
 * The countdown updates every second in the format HH:MM:SS or MM:SS.
 */
export default function PaymentWarningBanner({
		  paymentDueAt,
		  secondsUntilDue,
		  onExpired,
		}: PaymentWarningBannerProps) {
	  const t = useTranslations("orderSuccess");
		  const [secondsLeft, setSecondsLeft] = useState<number | null>(null);
	  // Client `Date.now()` captured (in the effect below) when the server value
	  // arrived — the anchor the skew-immune delta advances from.
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
		    if (secondsLeft === null || secondsLeft <= 0) {
		      return;
		    }

		    const timer = setInterval(() => {
		      setSecondsLeft((prev) => {
		        if (prev === null) return prev;
		        return Math.max(0, prev - 1);
		      });
		    }, 1000);

		    return () => clearInterval(timer);
		  }, [secondsLeft]);

  // Format seconds to H:MM:SS or MM:SS
	  const formatTime = (seconds: number): string => {
    const hours = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;

    if (hours > 0) {
      return `${hours}:${mins.toString().padStart(2, "0")}:${secs.toString().padStart(2, "0")}`;
    }
    return `${mins.toString().padStart(2, "0")}:${secs.toString().padStart(2, "0")}`;
	  };

		  const safeSeconds = secondsLeft ?? 0;
		  const timeString = formatTime(safeSeconds);
		  const isExpired = secondsLeft === 0;

		  // Khi countdown về 0 → gọi callback (nếu có).
		  // secondsLeft = null: chưa tính xong, KHÔNG gọi.
		  // secondsLeft = 0 sau khi tính diff hoặc sau countdown → gọi onExpired.
		  useEffect(() => {
		    if (!onExpired) return;
		    if (secondsLeft === 0) {
		      onExpired();
		    }
		  }, [secondsLeft, onExpired]);

	  // Lấy chuỗi dịch và thay thế placeholder time bằng phần bold
	  // Khi đã hết hạn (secondsLeft = 0) → hiển thị thông báo riêng
	  // "Đơn hàng của bạn đã bị huỷ do quá thời gian thanh toán." (per user).
	  const rawText = isExpired
	    ? t("paymentTimeoutCancelled")
	    : t("paymentWarningAlert", { time: "__TIME__" });
	  const [beforeTime, afterTime] = rawText.split("__TIME__");

  return (
    <div
      className="mb-3 flex gap-2.5 rounded-[20px] border p-3"
      style={{
        backgroundColor: "#FFF5F5",
        borderColor: "#FED7D7",
      }}
    >
      {/* Info icon (i) giống design */}
      <svg
        width="20"
        height="20"
        viewBox="0 0 20 20"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        className="mt-0.5 flex-shrink-0"
      >
        <circle cx="10" cy="10" r="9" stroke="#DC2626" strokeWidth="1.5" />
        <circle cx="10" cy="6" r="0.75" fill="#DC2626" />
        <path d="M10 9V14" stroke="#DC2626" strokeWidth="1.5" strokeLinecap="round" />
      </svg>

      {/* Warning text với countdown bold ở cuối dòng */}
      <p
        className="flex-1 text-left leading-relaxed"
        style={{
          color: "#B91C1C",
          fontSize: "12px",
          fontWeight: 400,
          lineHeight: "18px",
        }}
        >
          {beforeTime}
          {!isExpired && (
            <span style={{ fontWeight: 700 }}>{timeString}</span>
          )}
          {afterTime}
        </p>
    </div>
  );
}
