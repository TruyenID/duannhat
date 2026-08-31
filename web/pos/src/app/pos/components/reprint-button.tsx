/**
 * ReprintButton — in lại PHIẾU BẾP / PHIẾU ORDER cho một đơn, từ màn lịch sử.
 *
 * Hai tờ này **không phải chứng từ tiền**, và đó là lý do chúng có một nút chứ
 * không phải cặp `In gốc` / `In lại` như hoá đơn (xem `print-doc-actions.tsx`):
 * chúng không đi qua `printjob.Reserve`, không mang số bản, không có dấu
 * 「BAN IN #N」. Không có bộ đếm thì "gốc" và "bản sao" không phải hai thứ khác
 * nhau, và dựng một cặp nút ở đây sẽ hứa một sự phân biệt mà tờ giấy không hề có.
 *
 * | kind      | route                            | tờ giấy                       |
 * |-----------|----------------------------------|-------------------------------|
 * | `kitchen` | `/api/lan/print/kitchen-reprint` | PHIẾU BẾP (không điều món)    |
 * | `hold`    | `/api/lan/print/order-bill`      | PHIẾU ORDER + QR (hold/hall)  |
 *
 * `kitchen` KHÔNG gọi `/kitchen-ticket`. Đường đó là ĐIỀU MÓN: nó gửi phần chưa
 * in, đóng delta, và bắn `order.kitchen_printed` cho mọi màn KDS nạp lại. Trên
 * một đơn đã xong delta bằng 0 nên nó trả 422 — và nếu nó in thật thì đơn đã
 * thanh toán xong sẽ rơi trở lại màn hình bếp như việc mới. Xem
 * `reprintKitchenForOrder` phía workstation.
 *
 * Giao diện fail-OPEN, tầng in fail-closed (`lib/on-hold.ts`): nút vẫn hiện khi
 * cờ treo đã cũ, và lời từ chối của workstation mới là lời cuối.
 */

import { useState } from "react";
import { Button } from "@godxjp/ui";
import { ChefHatIcon, PrinterIcon } from "lucide-react";
import { toast } from "sonner";

import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";
import { workstationPrintService } from "@/services/workstation-print-service";
import { printErrorToast } from "../lib/print-error-message";
import type { TFn } from "./order-history-shared";

export type SlipKind = "kitchen" | "hold";

const ICON = {
  kitchen: ChefHatIcon,
  hold: PrinterIcon,
} as const;

export function ReprintButton({
  kind,
  orderId,
  label,
  compact,
  t,
  className,
}: {
  kind: SlipKind;
  orderId: string;
  label: string;
  compact?: boolean;
  t: TFn;
  className?: string;
}) {
  const [printing, setPrinting] = useState(false);
  const [printCount, setPrintCount] = useState(0);

  // Không có workstation thì không có máy in — `/api/lan/print/*` chỉ tồn tại ở
  // LAN, không bao giờ rơi về Cloud. Hợp đồng silent-no-op như mọi bề mặt in khác.
  if (!workstationPrintService.enabled) return null;

  async function handlePrint() {
    setPrinting(true);
    try {
      if (kind === "kitchen") {
        await workstationPrintService.printKitchenReprint({ orderId });
      } else {
        await workstationPrintService.printOrderBill({ orderId });
      }
      setPrintCount((n) => n + 1);
      toast.success(t(`pos.order_history.reprint_ok.${kind}`));
    } catch (err) {
      const { level, key } = printErrorToast(err);
      toast[level](t(key));
    } finally {
      setPrinting(false);
    }
  }

  // "In" → "In ✓" → "In #3". Đếm theo phiên xem thôi: hai tờ này không có bộ đếm
  // phía server, nên con số này là một lời nhắc cho thu ngân, không phải một
  // khẳng định về tờ giấy.
  const text =
    printCount === 0 ? label : printCount === 1 ? `${label} ✓` : `${label} #${printCount + 1}`;

  const Icon = ICON[kind];

  return (
    <Button
      type="button"
      variant="outline"
      size="sm"
      data-slot={`reprint-${kind}-button`}
      onClick={handlePrint}
      disabled={printing}
      className={cn(
        compact ? "h-8 gap-1.5 px-2.5 text-xs" : "h-9 gap-1.5 px-3",
        className,
      )}
    >
      {printing ? (
        <Spinner className={compact ? "size-3.5" : "size-4"} />
      ) : (
        <Icon className={compact ? "size-3.5" : "size-4"} />
      )}
      {text}
    </Button>
  );
}
