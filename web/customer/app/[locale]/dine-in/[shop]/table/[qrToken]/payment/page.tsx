"use client";

import { useState, useEffect } from "react";
import { useParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { useRouter } from "@/i18n/routing";
import { AlertCircle, Loader2 } from "lucide-react";
import { apiFetch, ApiError } from "@/lib/api";
import {
  branchFromTablePayload,
  type TableBranchPayload,
} from "@/lib/branch-from-table-payload";
import { useBrand } from "@/context/brand-context";
import type { ActiveOrder } from "@/data/orders";
import Header from "@/components/Header";
import PaymentView from "../components/payment-view";
import PaidView from "../components/paid-view";
import type { TableInfo } from "../page";

interface TableApiData {
  table: { id: string; number: string; seats: number; status: string; qr_token: string };
  zone: { id: string; name: string } | null;
  /** #1778 — same shared shape as the table page. This file carried its own
   * copy which had drifted further still: it never even listed `timezone`
   * (#1447), so landing on /payment wiped the zone the table page had just
   * resolved. */
  branch: TableBranchPayload | null;
}

/**
 * Route /dine-in/{shop}/table/{qrToken}/payment
 *
 * Trang thanh toán riêng cho dine-in flow. Tự fetch table + order qua qrToken.
 * Reload-safe (URL là source of truth) — không cần localStorage persist.
 *
 * Navigation:
 *   - onBack → router.back() về trang chính (summary/menu view)
 *   - onConfirmed → setView("paid") inline (URL không đổi, paid là terminal)
 *   - onPartialPaid → re-fetch order, stay on payment view
 *
 * Guards:
 *   - Loading → spinner
 *   - notFound (404 table hoặc 404 order) → redirect về main page
 *   - Order remaining=0 lúc fetch → auto switch sang paid view
 */
export default function DineInPaymentPage() {
  const { shop, qrToken } = useParams<{ shop: string; qrToken: string }>();
  const router = useRouter();
  const t = useTranslations("dineIn");
  const { setCurrentBranch } = useBrand();

  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [tableInfo, setTableInfo] = useState<TableInfo | null>(null);
  const [order, setOrder] = useState<ActiveOrder | null>(null);
  const [paidOrder, setPaidOrder] = useState<ActiveOrder | null>(null);
  const [view, setView] = useState<"payment" | "paid">("payment");

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        // 1. Fetch table info
        const tableRes = await apiFetch<{ data: TableApiData }>(
          `/api/v1/customer/tables/${qrToken}`,
        );
        if (cancelled) return;
        const { table, zone, branch } = tableRes.data;

        setTableInfo({
          code: table.number,
          zone: zone?.name ?? "",
          seats: table.seats,
        });

        // 2. Sync brand context (cho currency_code dùng trong PaymentView)
        //    #1778 — fold, không thay thế: màn này còn đọc
        //    `split_bill_rounding_mode` để chia bill, mà payload bàn không mang
        //    trường đó. Thay thế nguyên object là ném nó đi ngay trước lúc cần.
        if (branch) {
          setCurrentBranch((prev) => branchFromTablePayload(prev, branch));
        }

        // 3. Fetch current order on table
        try {
          const orderRes = await apiFetch<{ data: { order: ActiveOrder | null } }>(
            `/api/v1/customer/tables/${qrToken}/order?_t=${Date.now()}`,
            { cache: "no-store" },
          );
          if (cancelled) return;

          const fetchedOrder = orderRes.data.order ?? null;
          if (!fetchedOrder) {
            // Không có order → user không nên ở payment page → về main
            router.replace(`/dine-in/${shop}/table/${qrToken}`);
            return;
          }
          setOrder(fetchedOrder);

          // Guard: nếu order đã thanh toán/hoàn tất → hiển paid view ngay.
          // QUAN TRỌNG: chỉ coi là "xong" khi order thực sự đã đóng —
          // `is_fully_paid` (đã trả tiền) HOẶC status closed/voided. KHÔNG
          // dùng riêng `remaining === 0`: đơn 0đ (comp / coupon -100%) có
          // remaining = 0 nhưng chưa đóng → phải rơi xuống PaymentView để
          // khách bấm "Hoàn tất", chứ không bị đá về trang chủ.
          const isSettled =
            fetchedOrder.is_fully_paid ||
            fetchedOrder.status === "closed" ||
            fetchedOrder.status === "voided";
          if (isSettled) {
            // issue #362 Path B — refresh /payment sau khi đã thanh toán.
            // Nếu device này có localStorage `dine_in_session_*` thì
            // chính nó là khách vừa pay → đẩy thẳng về homepage và xoá
            // localStorage để lần quét QR sau (nếu có) là device mới
            // hoàn toàn. Tránh khách rời quán mà tab cũ vẫn re-mount
            // PaidView + nhỡ tay chạm nút.
            let hadSession = false;
            if (typeof window !== "undefined") {
              try {
                hadSession = !!localStorage.getItem(`dine_in_session_${qrToken}`);
              } catch {
                // localStorage disabled — fall through to PaidView render.
              }
            }
            if (hadSession) {
              try {
                localStorage.removeItem(`dine_in_session_${qrToken}`);
                localStorage.removeItem(`dine_in_occupied_${qrToken}`);
              } catch {
                // ignore
              }
              router.replace("/");
              return;
            }
            setPaidOrder(fetchedOrder);
            setView("paid");
          }
        } catch (err) {
          if (err instanceof ApiError && err.status === 404) {
            // Bàn chưa có order → về main
            router.replace(`/dine-in/${shop}/table/${qrToken}`);
            return;
          }
          throw err;
        }

        if (!cancelled) setLoading(false);
      } catch (err) {
        if (cancelled) return;
        console.error("[DineInPaymentPage] Load failed:", err);
        setNotFound(true);
        setLoading(false);
      }
    }

    void load();
    return () => {
      cancelled = true;
    };
  }, [qrToken, shop, router, setCurrentBranch]);

  if (loading) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-3 bg-[#FAFAFA]">
        <Loader2 className="size-8 animate-spin text-primary" />
        <p className="text-sm text-muted-foreground">{t("loading")}</p>
      </div>
    );
  }

  if (notFound || !tableInfo || !order) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-3 bg-[#FAFAFA] px-6 text-center">
        <AlertCircle className="size-12 text-red-300" />
        <p className="font-semibold text-neutral-700">{t("tableNotFound")}</p>
        <p className="text-sm text-neutral-400">{t("scanOtherTable")}</p>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-[#FAFAFA]">
      <Header showLogo hideSwitcher hideAuth hideOrderCta hideOrderHistory hideShadow={view === "payment"} />
      {view === "payment" ? (
        <PaymentView
          table={tableInfo}
          order={order}
          onBack={() => router.push(`/dine-in/${shop}/table/${qrToken}`)}
          onConfirmed={(o) => {
            // Payment success → switch sang paid view inline (URL giữ nguyên)
            localStorage.removeItem(`dine_in_occupied_${qrToken}`);
            setPaidOrder(o);
            setView("paid");
          }}
          onPartialPaid={(updatedOrder) => {
            setOrder(updatedOrder);
          }}
        />
      ) : (
        <PaidView table={tableInfo} order={paidOrder ?? order} />
      )}
    </div>
  );
}
