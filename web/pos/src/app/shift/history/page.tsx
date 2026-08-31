"use client";

import { useState } from "react";
import { useParams } from "react-router-dom";
import { PosHeader } from "@/app/pos/components/pos-header";
import { Badge, Button, Card, Spinner } from "@godxjp/ui";
import { useShop } from "@/hooks/api/use-shop";
import { useTillSessionHistory } from "@/hooks/api/use-till";
import { formatDateTime } from "@/lib/format-date";
import { useTranslation } from "@/providers/app-provider";
import { workstationPrintService } from "@/services/workstation-print-service";
import type { TillSession } from "@/services/till-service";
import { toast } from "sonner";

/**
 * #3062 — lịch sử ca + IN LẠI phiếu 精算.
 *
 * ## Vì sao trang này tồn tại
 *
 * Phiếu kết ca vốn là **hiệu ứng phụ bắn một lần** của việc chốt ca: pos-web
 * gọi in ngay sau khi settle, và nếu lượt đó hỏng thì tờ giấy mất vĩnh viễn —
 * bấm chốt lại chỉ nhận 409 `SHIFT_ALREADY_FINALIZED`.
 *
 * Đo ở 本郷店 ngày 2026-08-16: máy in hoá đơn offline từ 20:00 JST, ca chốt lúc
 * 21:50 — offline gần hai tiếng, và tờ 精算 của ca đó không bao giờ ra. Nhân
 * viên bấm chốt lại, nhận 409, và không còn đường nào.
 *
 * Máy trạm thì in lại được từ lâu: `handleLANPrintShiftReport` không kiểm trạng
 * thái ca. Thứ thiếu là chỗ bấm — và đó là trang này.
 *
 * ## In lại KHÔNG đổi gì trong sổ
 *
 * Nó chỉ ra giấy. `settlement_snapshot` là ảnh chụp bất biến (plan-046 R7) và
 * mọi con số đối soát đọc từ đó, nên in lại một ca của tuần trước vẫn ra đúng
 * tờ của tuần trước — không tính lại theo dữ liệu hôm nay.
 */
export function ShiftHistoryPage() {
  const { shopSlug = "" } = useParams();
  const { t, locale } = useTranslation();
  const { data: shopResponse } = useShop(shopSlug);
  const shop = shopResponse?.data;
  const sessions = useTillSessionHistory(shopSlug);
  // Khoá theo TỪNG ca, không phải một cờ chung: hai ca in liên tiếp thì cờ
  // chung sẽ khoá cả hàng không liên quan.
  const [printing, setPrinting] = useState<string | null>(null);

  const reprint = async (s: TillSession) => {
    setPrinting(s.id);
    try {
      const res = await workstationPrintService.printShiftReport({
        shopSlug,
        sessionId: s.id,
        // Ca cuối chuỗi in 精算; ca bàn giao in 引き継ぎ. Đọc từ chính bản ghi
        // để tờ in lại giống hệt tờ gốc — một tờ "in lại" mang tiêu đề khác
        // tờ đầu là hai chứng từ khác nhau về cùng một ca.
        reportKind: s.settlement_kind === "handover" ? "handover" : "settlement",
      });
      if (res.status === "no_printer") {
        toast.warning(t("shift.close.print.no_printer"));
      } else if (res.status === "offline" || res.status === "unsupported") {
        toast.warning(t("shift.close.print.offline"));
      } else {
        toast.success(t("shift.history.reprint.done"));
      }
    } catch {
      toast.error(t("shift.close.print.failed"));
    } finally {
      setPrinting(null);
    }
  };

  const rows = sessions.data ?? [];

  return (
    <div className="min-h-dvh bg-muted/30 text-foreground">
      <PosHeader shopName={shop?.name ?? ""} helpTopic="shift-close" />

      <div className="mx-auto max-w-3xl px-3 py-4 sm:px-6">
        <h1 className="mb-1 text-base font-semibold sm:text-lg">
          {t("shift.history.title")}
        </h1>
        <p className="mb-4 text-xs text-muted-foreground sm:text-sm">
          {t("shift.history.desc")}
        </p>

        {sessions.isLoading ? (
          <div className="flex justify-center py-10">
            <Spinner className="size-5" />
          </div>
        ) : rows.length === 0 ? (
          <Card className="gap-0 p-0">
            <div className="px-5 py-8 text-center text-sm text-muted-foreground">
              {t("shift.history.empty")}
            </div>
          </Card>
        ) : (
          <ul className="space-y-2">
            {rows.map((s) => {
              // Chỉ ca ĐÃ CHỐT mới in lại được. Một ca đang mở chưa có
              // `settlement_snapshot`, nên tờ giấy sẽ nói về một việc chưa xảy
              // ra — cùng lý lẽ với hoá đơn của đơn chưa đóng (#3040).
              const settled = s.status === "settled";

              return (
                <li key={s.id}>
                  <Card className="gap-0 p-0">
                    <div className="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5">
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                          <span className="text-sm font-medium tabular-nums">
                            {s.session_code}
                          </span>
                          <Badge
                            variant={settled ? "secondary" : "outline"}
                            className="text-[11px]"
                          >
                            {t(`shift.status.${s.status}`)}
                          </Badge>
                        </div>
                        <div className="mt-0.5 text-xs text-muted-foreground">
                          {s.opened_at
                            ? formatDateTime(new Date(s.opened_at), locale)
                            : "—"}
                          {s.closed_at
                            ? ` — ${formatDateTime(new Date(s.closed_at), locale)}`
                            : ""}
                        </div>
                      </div>

                      <Button
                        variant="outline"
                        size="sm"
                        disabled={!settled || printing === s.id}
                        onClick={() => void reprint(s)}
                      >
                        {printing === s.id ? (
                          <Spinner className="mr-2 size-3.5" />
                        ) : null}
                        {t("shift.history.reprint")}
                      </Button>
                    </div>
                  </Card>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </div>
  );
}
