"use client";

import { useEffect } from "react";
import { ThumbsUp } from "lucide-react";
import { useTranslations } from 'next-intl';
import { useRouter } from "@/i18n/routing";
import { useAuth } from "@/context/auth-context";
import { Button } from "@/components/ui/button";
import type { ActiveOrder } from "@/data/orders";
import type { TableInfo } from "../page";
import { FEATURES } from "@/lib/feature-flags";
import { useCurrency } from "@/lib/currency";
import { shortOrderCode } from "@/lib/utils";

interface PaidViewProps {
  table: TableInfo;
  order: ActiveOrder | null;
  /** plan-034 — set by TablePage when device B scans a table that is
   *  already paid but not yet cleared. Clicking the button calls
   *  /join?force_new=true to spin up a fresh session and drops the user
   *  into the menu so they can add another round. */
  onAddMoreItems?: () => void;
}

export default function PaidView({ table, order, onAddMoreItems }: PaidViewProps) {
  const t = useTranslations('dineIn');
  const tr = useTranslations('review');
  const { format: fmt } = useCurrency();
  const { isLoggedIn } = useAuth();
  const router = useRouter();
  // issue #362 — "Về trang chủ" button removed. Khách rời quán không nên
  // có cách re-enter dine-in flow từ màn paid; idle-timeout + refresh
  // guard ở TablePage cấp trên đã kéo khách về `/` khi cần.

  // issue #362 — auto-redirect to homepage after `IDLE_REDIRECT_MS` of no
  // user interaction. Reset on any click / pointer / touch / scroll
  // event so a user reading the success message slowly isn't snatched
  // back to home mid-thought. The timer is cleared on unmount (user
  // navigated to /review, /order-more, etc.) so it never fires after
  // the component has gone away.
  useEffect(() => {
    const IDLE_REDIRECT_MS = 30_000;
    if (typeof window === 'undefined') return;

    let timer = window.setTimeout(() => {
      router.replace('/');
    }, IDLE_REDIRECT_MS);

    const reset = () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => {
        router.replace('/');
      }, IDLE_REDIRECT_MS);
    };

    const events: Array<keyof WindowEventMap> = [
      'click',
      'pointermove',
      'touchstart',
      'keydown',
      'scroll',
    ];
    events.forEach((evt) => window.addEventListener(evt, reset, { passive: true }));

    return () => {
      window.clearTimeout(timer);
      events.forEach((evt) => window.removeEventListener(evt, reset));
    };
  }, [router]);

  // Giống takeaway: mở trang đánh giá đầy đủ /review/[orderId] (đánh giá sản phẩm
  // + cửa hàng). Khách chưa đăng nhập thì đẩy sang login rồi quay lại trang đánh giá.
  const handleReviewClick = () => {
    if (!order) return;
    const target = `/review/${order.id}?code=${encodeURIComponent(order.code)}`;
    // FEATURES.auth off (#47) → /login bị chặn, đi thẳng trang đánh giá.
    if (FEATURES.auth && !isLoggedIn) {
      router.push(`/login?redirect=${encodeURIComponent(target)}`);
      return;
    }
    router.push(target);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="relative w-full max-w-[320px] rounded-2xl bg-white p-6 shadow-xl">
        {/* Close (X) button đã được gỡ — modal "Thanh toán thành công" chỉ
            cho phép dismiss qua 2 button bên dưới ("Đánh giá món ăn" /
            "Về trang menu"), tránh user lỡ tay tắt mất xác nhận payment. */}

        {/* Success Celebration Animation - Circle + Checkmark + Confetti */}
        <div className="relative mx-auto mb-8" style={{ width: '100px', height: '100px' }}>
          {/* Confetti particles */}
          <div className="absolute inset-0" style={{ pointerEvents: 'none' }}>
            {/* Confetti 1 - Orange */}
            <div
              className="absolute"
              style={{
                top: '20%',
                left: '30%',
                width: '8px',
                height: '8px',
                backgroundColor: '#FF9800',
                borderRadius: '2px',
                animation: 'confetti-fall-1 2s ease-out forwards',
                opacity: 0,
              }}
            />
            {/* Confetti 2 - Pink */}
            <div
              className="absolute"
              style={{
                top: '25%',
                left: '60%',
                width: '6px',
                height: '12px',
                backgroundColor: '#E91E63',
                borderRadius: '50%',
                animation: 'confetti-fall-2 2.2s ease-out forwards',
                opacity: 0,
              }}
            />
            {/* Confetti 3 - Blue */}
            <div
              className="absolute"
              style={{
                top: '30%',
                left: '45%',
                width: '10px',
                height: '6px',
                backgroundColor: '#2196F3',
                borderRadius: '2px',
                animation: 'confetti-fall-3 1.8s ease-out forwards',
                opacity: 0,
              }}
            />
            {/* Confetti 4 - Yellow */}
            <div
              className="absolute"
              style={{
                top: '20%',
                right: '25%',
                width: '7px',
                height: '7px',
                backgroundColor: '#FFC107',
                borderRadius: '50%',
                animation: 'confetti-fall-4 2.1s ease-out forwards',
                opacity: 0,
              }}
            />
            {/* Confetti 5 - Green */}
            <div
              className="absolute"
              style={{
                top: '28%',
                left: '20%',
                width: '9px',
                height: '5px',
                backgroundColor: '#4CAF50',
                borderRadius: '2px',
                animation: 'confetti-fall-5 1.9s ease-out forwards',
                opacity: 0,
              }}
            />
            {/* Confetti 6 - Purple */}
            <div
              className="absolute"
              style={{
                top: '22%',
                right: '35%',
                width: '6px',
                height: '6px',
                backgroundColor: '#9C27B0',
                borderRadius: '50%',
                animation: 'confetti-fall-6 2.3s ease-out forwards',
                opacity: 0,
              }}
            />
          </div>

          {/* Main check circle with animated ring */}
          <div className="absolute top-1/2 left-1/2" style={{ transform: 'translate(-50%, -50%)' }}>
            {/* Animated SVG Circle Ring */}
            <svg width="80" height="80" viewBox="0 0 80 80" style={{ position: 'absolute', top: '50%', left: '50%', transform: 'translate(-50%, -50%) rotate(-90deg)' }}>
              <circle
                cx="40"
                cy="40"
                r="36"
                fill="none"
                stroke="#299236"
                strokeWidth="4"
                strokeLinecap="round"
                style={{
                  strokeDasharray: 226,
                  strokeDashoffset: 226,
                  animation: 'draw-circle 0.8s ease-out forwards',
                }}
              />
            </svg>

            {/* Success Circle Background */}
            <div
              className="size-20 rounded-full flex items-center justify-center"
              style={{
                backgroundColor: '#299236',
                boxShadow: '0px 4px 6px -4px #5EE83066, 0px 10px 15px -3px #5EE83066, 0 0 0 0 rgba(41, 146, 54, 0.4)',
                animation: 'scale-in 0.4s 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275) backwards, pulse-success 2s 1.5s ease-in-out infinite',
              }}
            >
              {/* Animated Checkmark SVG */}
              <svg width="40" height="40" viewBox="0 0 40 40" fill="none" style={{ position: 'relative', zIndex: 1 }}>
                <path
                  d="M10 20 L18 28 L30 12"
                  stroke="white"
                  strokeWidth="4"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  style={{
                    strokeDasharray: 30,
                    strokeDashoffset: 30,
                    animation: 'draw-check 0.5s 0.8s ease-out forwards',
                  }}
                />
              </svg>
            </div>
          </div>

          <style dangerouslySetInnerHTML={{ __html: `
            /* Circle ring drawing animation */
            @keyframes draw-circle {
              to {
                stroke-dashoffset: 0;
              }
            }

            /* Checkmark drawing animation */
            @keyframes draw-check {
              to {
                stroke-dashoffset: 0;
              }
            }

            /* Circle scale-in animation */
            @keyframes scale-in {
              from {
                transform: scale(0);
                opacity: 0;
              }
              to {
                transform: scale(1);
                opacity: 1;
              }
            }

            /* Pulse after appearance */
            @keyframes pulse-success {
              0%, 100% {
                box-shadow:
                  0px 4px 6px -4px #5EE83066,
                  0px 10px 15px -3px #5EE83066,
                  0 0 0 0 rgba(41, 146, 54, 0.4);
              }
              50% {
                box-shadow:
                  0px 4px 10px -4px #5EE830AA,
                  0px 10px 25px -3px #5EE830AA,
                  0 0 0 20px rgba(41, 146, 54, 0);
              }
            }

            /* Confetti falling animations */
            @keyframes confetti-fall-1 {
              0% {
                transform: translateY(0) rotate(0deg);
                opacity: 0;
              }
              10% {
                opacity: 1;
              }
              100% {
                transform: translateY(150px) translateX(-30px) rotate(180deg);
                opacity: 0;
              }
            }

            @keyframes confetti-fall-2 {
              0% {
                transform: translateY(0) rotate(0deg) scale(1);
                opacity: 0;
              }
              10% {
                opacity: 1;
              }
              100% {
                transform: translateY(160px) translateX(40px) rotate(240deg) scale(0.8);
                opacity: 0;
              }
            }

            @keyframes confetti-fall-3 {
              0% {
                transform: translateY(0) rotate(0deg);
                opacity: 0;
              }
              10% {
                opacity: 1;
              }
              100% {
                transform: translateY(140px) translateX(-15px) rotate(150deg);
                opacity: 0;
              }
            }

            @keyframes confetti-fall-4 {
              0% {
                transform: translateY(0) rotate(0deg) scale(1);
                opacity: 0;
              }
              10% {
                opacity: 1;
              }
              100% {
                transform: translateY(155px) translateX(25px) rotate(200deg) scale(0.9);
                opacity: 0;
              }
            }

            @keyframes confetti-fall-5 {
              0% {
                transform: translateY(0) rotate(0deg);
                opacity: 0;
              }
              10% {
                opacity: 1;
              }
              100% {
                transform: translateY(145px) translateX(-35px) rotate(170deg);
                opacity: 0;
              }
            }

            @keyframes confetti-fall-6 {
              0% {
                transform: translateY(0) rotate(0deg) scale(1);
                opacity: 0;
              }
              10% {
                opacity: 1;
              }
              100% {
                transform: translateY(165px) translateX(30px) rotate(220deg) scale(0.85);
                opacity: 0;
              }
            }
          ` }} />
        </div>

        <h1
          className="mb-8 text-center text-neutral-900"
          style={{
            fontSize: '24px',
            fontWeight: 400,
            lineHeight: '100%',
            letterSpacing: '-0.26px',
          }}
        >
          {t('paymentSuccessTitle')}
        </h1>

        {/* Info card */}
        {order && (
          <div
            className="mb-8 space-y-4 rounded-lg p-6"
            style={{
              backgroundColor: '#F6F3F2',
              border: '1px solid #BECABD4D',
            }}
          >
            <div className="flex items-center justify-between">
              <span
                style={{
                  fontWeight: 400,
                  fontSize: '14px',
                  lineHeight: '20px',
                  letterSpacing: '0px',
                  color: '#3F4940',
                }}
              >
                {t('orderCodeLabel')}
              </span>
              <span
                style={{
                  fontWeight: 600,
                  fontSize: '14px',
                  lineHeight: '20px',
                }}
                className="text-neutral-900"
              >
                #{shortOrderCode(order.code)}
              </span>
            </div>
            <div
              className="flex items-center justify-between pt-4"
              style={{
                borderTop: '1px solid #BECABD4D',
              }}
            >
              <span
                style={{
                  fontWeight: 400,
                  fontSize: '14px',
                  lineHeight: '20px',
                  letterSpacing: '0px',
                  color: '#3F4940',
                }}
              >
                {t('totalPaidLabel')}
              </span>
              <span
                className="tabular-nums"
                style={{
                  fontWeight: 700,
                  fontSize: '18px',
                  lineHeight: '24px',
                  letterSpacing: '0px',
                  color: '#006A34',
                }}
              >
                {fmt(order.paid ?? order.total)}
              </span>
            </div>
          </div>
        )}

        {/* Rate dishes button */}
        {order && (
          <Button
            onClick={handleReviewClick}
            className="w-full gap-2"
            style={{
              width: '274px',
              height: '52px',
              borderRadius: '12px',
              backgroundColor: '#27A14F',
            }}
          >
            <ThumbsUp className="h-4 w-4" />
            {tr('rateYourDishes')}
          </Button>
        )}

        {/* plan-034 — only shown when device B scans a paid-but-not-
            cleared table. Click → /join?force_new=true → open a fresh
            TableSession on top of the same table → land in MenuView
            with an empty cart for the next round. */}
        {onAddMoreItems && (
          <Button
            onClick={onAddMoreItems}
            className="mt-3 w-full gap-2"
            style={{
              width: '274px',
              height: '52px',
              borderRadius: '12px',
              backgroundColor: '#27A14F',
            }}
          >
            {t('orderMoreItems')}
          </Button>
        )}

        {/* issue #362 — "Về trang chủ" button removed. Khách rời quán
            không nên có cách re-enter dine-in flow từ màn paid; idle
            timer + refresh-redirect ở TablePage cấp trên đảm bảo
            khách về `/` khi không tương tác. */}
      </div>
    </div>
  );
}
