/**
 * SplitBillDialog — modal shell for the split-bill flow.
 *
 * Hosts a two-tab UI:
 *   - "even"    → SplitBillEvenTab (existing chia-đều flow)
 *   - "by_items" → SplitBillByItemsTab (plan-021 chia-theo-món)
 *
 * Both tabs share the same payment plumbing — they receive the parent's
 * `methods` + `onCreatePayment` + `onAllRowsPaid` callbacks and each
 * tab's internal payment loop fires those directly. The by-items tab
 * also picks up `taxRate` / `serviceChargeRate` so its calculator can
 * project tax / service per bill.
 *
 * The dialog owns Dialog/Content/Header only. Each tab renders its own
 * body + footer; switching tabs preserves the inactive tab's state via
 * `forceMount` so staff can flip back and forth while deciding.
 */

import { useState } from "react";
import {
  Dialog,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import { useTranslation } from "@/providers/app-provider";
import type { CustomerOrder, PaymentMethod, SplitMode } from "../types";
import { formatCurrency } from "../lib/totals";
import { SplitBillEvenTab, type SplitBillEvenTabProps } from "./split-bill-even-tab";
import { SplitBillByItemsTab } from "./split-bill-by-items-tab";
import { SplitBillByAmountTab } from "./split-bill-by-amount-tab";
import { CashChangerOverlay } from "./cash-changer-overlay";
import { useCashChanger } from "../hooks/use-cash-changer";
import { useMachineCollector } from "../hooks/use-machine-collector";
import type { CashChangerSplitMetadata } from "@/services/workstation-cash-changer-service";

export type { SplitPaymentBody, SplitBillSessionResult } from "./split-bill-even-tab";

export interface SplitBillDialogProps extends SplitBillEvenTabProps {
  /**
   * Service-charge rate (percent) used by the by-items calculator. POS
   * pulls this from `ShopOrderSetting`. Falls back to 0 when undefined.
   */
  serviceChargeRate?: number;
  /**
   * VAT rate (percent) used by the by-items calculator. Falls back to 0
   * when undefined.
   */
  taxRate?: number;
  /**
   * ISO 4217 currency of the shop (#555 L1) — threaded to the by-amount
   * tab for minor-unit rounding. From `ShopOrderSetting.currency_code`.
   */
  currencyCode?: string | null;
  /**
   * Slug cửa hàng — chỉ để thu bằng máy 釣銭機 (#2946) làm tươi cache đúng
   * phạm vi. Bỏ trống ⇒ nút "thu bằng máy" vẫn chạy, chỉ là hook không có khoá
   * cache nào để vô hiệu hoá, nên đừng bỏ trống ở đường thật.
   */
  shopSlug?: string;
}

export function SplitBillDialog(props: SplitBillDialogProps) {
  const { t } = useTranslation();
  const {
    open,
    onOpenChange,
    order,
    methods,
    methodsLoading,
    onCreatePayment,
    onAllRowsPaid,
    taxRate,
    serviceChargeRate,
    shopSlug,
  } = props;

  /**
   * Thu bằng máy 釣銭機 cho MỘT hàng chia bill (#2946).
   *
   * Hook nằm ở ĐÂY chứ không ở `page.tsx`: file đó đang đúng trên trần dòng
   * (`page-size-budget.arch.test.ts`), và cùng lý do đã đẩy `PosHeader` đi tự
   * suy chủ đề từ route.
   *
   * `available` sai (chưa ghép máy trạm) ⇒ `machineIdle` sai ⇒ tab **không
   * render nút nào** và chạy y hệt hôm nay — đúng hợp đồng im-lặng-không-làm-gì
   * mà cả luồng in và luồng 釣銭機 dùng chung.
   */
  const cashChanger = useCashChanger(shopSlug ?? "", order?.customer_id);
  const machine = useMachineCollector(cashChanger);
  /**
   * Một closure cho CẢ BA tab. Ba tab có ba row-state và ba đường submit
   * riêng — chúng đã từng lệch nhau — nên thứ gì dùng chung được thì phải
   * dùng chung, để phần lệch còn lại là phần thật sự khác nhau.
   */
  const collectWithMachine = order
    ? (amount: number, metadata: CashChangerSplitMetadata) =>
        machine.collect(order.id, amount, metadata)
    : undefined;
  const orderRemaining = Number(order?.remaining_amount ?? 0);

  /**
   * `mode` is lifted here so each tab can take over its own footer.
   * Switching tabs preserves both tabs' internal state because
   * `forceMount` keeps them in the DOM.
   */
  const [mode, setMode] = useState<SplitMode>("even");

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[90vh] w-[min(96vw,1040px)] max-w-[1040px] flex-col gap-0 p-0 sm:max-w-[1040px]">
        <DialogHeader className="shrink-0 px-6 pt-6 pb-2">
          <div className="flex items-center gap-1.5">
            <DialogTitle className="text-xl font-bold">
              {t("pos.dialog.split_bill.title")}
            </DialogTitle>
            {/* One button for all three tabs: the rules that trip cashiers up
                (exact-sum on by-amount, tendered clearing on method change,
                the tab staying open until the order is settled) are shared. */}
            <HelpButton topic="split-bill" className="size-7" />
          </div>
          <DialogDescription className="text-sm">
            {order ? (
              <>
                <span className="font-mono">{order.order_code}</span>
                <span className="text-muted-foreground/60 mx-1.5">•</span>
                <span className="text-foreground font-semibold tabular-nums">
                  {formatCurrency(orderRemaining)}
                </span>
              </>
            ) : (
              t("pos.dialog.split_bill.no_order")
            )}
          </DialogDescription>
        </DialogHeader>

        <Tabs
          value={mode}
          onValueChange={(v) => setMode(v as SplitMode)}
          className="flex min-h-0 flex-1 flex-col"
        >
          <TabsList className="mx-6 mt-1 grid w-auto grid-cols-3">
            <TabsTrigger value="even">{t("pos.split_bill.tab.even")}</TabsTrigger>
            <TabsTrigger value="by_items">{t("pos.split_bill.tab.by_items")}</TabsTrigger>
            <TabsTrigger value="by_amount">{t("pos.split_bill.tab.by_amount")}</TabsTrigger>
          </TabsList>

          <TabsContent
            value="even"
            className="flex min-h-0 flex-1 flex-col data-[state=inactive]:hidden"
            forceMount
          >
            <SplitBillEvenTab
              {...props}
              machineIdle={machine.idle}
              onCollectWithMachine={collectWithMachine}
            />
          </TabsContent>

          <TabsContent
            value="by_items"
            className="flex min-h-0 flex-1 flex-col data-[state=inactive]:hidden"
            forceMount
          >
            <SplitBillByItemsTab
              key={order?.id ?? "no-order"}
              order={order}
              taxRate={taxRate ?? 0}
              serviceRate={serviceChargeRate ?? 0}
              methods={methods}
              methodsLoading={methodsLoading}
              onCreatePayment={onCreatePayment}
              onAllRowsPaid={onAllRowsPaid}
              onClose={() => onOpenChange(false)}
              machineIdle={machine.idle}
              onCollectWithMachine={collectWithMachine}
            />
          </TabsContent>

          <TabsContent
            value="by_amount"
            className="flex min-h-0 flex-1 flex-col data-[state=inactive]:hidden"
            forceMount
          >
            <SplitBillByAmountTab
              key={order?.id ?? "no-order"}
              order={order}
              methods={methods}
              methodsLoading={methodsLoading}
              onCreatePayment={onCreatePayment}
              onAllRowsPaid={onAllRowsPaid}
              onClose={() => onOpenChange(false)}
              currencyCode={props.currencyCode}
              machineIdle={machine.idle}
              onCollectWithMachine={collectWithMachine}
            />
          </TabsContent>
        </Tabs>
      
        {/* Trạng thái lượt thu của máy — dựng CÙNG khuôn với màn thu tiền, vì
            câu hỏi nó phải trả lời đúng vẫn là MỘT: tiền của khách đang ở đâu.
            `onDismiss` chỉ đóng màn; hàng chia bill đã được `useMachineCollector`
            giải quyết từ chính snapshot này, nên ở đây KHÔNG ghi gì thêm. */}
        {cashChanger.session && (
          <CashChangerOverlay
            session={cashChanger.session}
            busy={cashChanger.busy}
            outcomeUnknown={cashChanger.outcomeUnknown}
            onCancel={() => void cashChanger.cancel()}
            onDismiss={cashChanger.dismiss}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

// Re-export the underlying types for backwards-compat with importers that
// reference these names by way of the dialog file.
export type { CustomerOrder, PaymentMethod };
