import { Button } from "@godxjp/ui";
import { HelpButton } from "@/help/help-button";
import { useTranslation } from "@/providers/app-provider";
import { formatCurrency } from "../lib/totals";
import {
  cashCollectedAndRecorded,
  cashCollectedButNotRecorded,
  cashRetainedByMachine,
  type CashChangerSession,
} from "@/services/workstation-cash-changer-service";

interface CashChangerOverlayProps {
  session: CashChangerSession;
  busy: boolean;
  /**
   * Polling gave up without ever seeing a terminal status (#1810) — we do NOT
   * know whether the machine took the money. Rendered with the same weight as
   * a retained-cash ending, never as a cancellation.
   */
  outcomeUnknown?: boolean;
  onCancel: () => void;
  onDismiss: () => void;
}

/**
 * Full-screen state of one 釣銭機 collection, mirroring the card-terminal
 * overlay in payment-dialog.
 *
 * The screen exists to say ONE thing correctly: where the customer's cash is.
 * The machine's terminal states are not interchangeable —
 *
 *   finish  → money taken, change dispensed, payment already recorded
 *   cancel  → deposit RETURNED to the customer
 *   timeout → deposit KEPT by the machine (the adapter's own wording)
 *   abort   → power/OS crash mid-transaction, cash state needs a human
 *   failure → machine error while dispensing/awaiting pickup
 *
 * Collapsing the last three into a generic "failed" would tell a cashier the
 * customer has their money when the machine is still holding it, so they are
 * rendered as a warning that names the amount and refuses to look like a
 * dismissible error.
 */
export function CashChangerOverlay({
  session,
  busy,
  outcomeUnknown = false,
  onCancel,
  onDismiss,
}: CashChangerOverlayProps) {
  const { t } = useTranslation();
  // "We don't know" belongs on the SAME side as "the machine kept it". Treating
  // an unobserved ending as benign is how a cashier ends up telling a customer
  // their money was returned when nobody has checked (#1810).
  const retained = outcomeUnknown || cashRetainedByMachine(session.status);
  // #2535 B3 — "đã thu nhưng ghi sổ hỏng" là trạng thái RIÊNG, không phải một
  // biến thể của thành công. Bản cũ chỉ hỏi `status === "finish"`, mà
  // workstation trả `finish` cho cả ca đó — kèm `payment_id` rỗng và một chuỗi
  // `error` chỉ được render bên trong khối `retained`, tức không bao giờ lên
  // màn hình. Thu ngân đọc "Đã thu xong" trong khi sổ trống trơn.
  const collectedNotRecorded = !outcomeUnknown && cashCollectedButNotRecorded(session);
  const finished = !outcomeUnknown && cashCollectedAndRecorded(session);

  return (
    <div className="fixed inset-0 z-[60] flex flex-col items-center justify-center gap-6 bg-background/95 px-6 text-center">
      {/* Top-right, away from the actions: "cancel returned the cash, timeout
          did NOT" is the distinction this screen exists for, and the one a
          cashier will want spelled out while a customer is waiting. */}
      <div className="absolute right-4 top-4">
        <HelpButton topic="cash-changer" />
      </div>

      {session.running && (
        <div className="h-10 w-10 animate-spin rounded-full border-4 border-muted border-t-primary" />
      )}

      <div className="space-y-1">
        <p className="text-lg font-semibold">
          {outcomeUnknown
            ? t("pos.dialog.payment.cash_changer.unknown")
            : session.running
            ? t("pos.dialog.payment.cash_changer.collecting")
            : collectedNotRecorded
              ? t("pos.dialog.payment.cash_changer.not_recorded")
              : finished
              ? t("pos.dialog.payment.cash_changer.done")
              : session.status === "cancel"
                ? t("pos.dialog.payment.cash_changer.returned")
                : t("pos.dialog.payment.cash_changer.retained")}
        </p>
        <p className="text-sm text-muted-foreground">
          {t("pos.dialog.payment.cash_changer.total")}: {formatCurrency(session.total)}
        </p>
      </div>

      {finished && (
        <div className="space-y-1 text-sm">
          <p>
            {t("pos.dialog.payment.cash_changer.tendered")}:{" "}
            {formatCurrency(session.tendered)}
          </p>
          <p className="text-base font-semibold">
            {t("pos.dialog.payment.cash_changer.change")}:{" "}
            {formatCurrency(session.change)}
          </p>
        </div>
      )}

      {/* #2535 B3 — tiền ĐÃ vào máy, máy ĐÃ thối tiền, nhưng sổ trống. Không
          dùng lại chữ "Đã thu xong", và cũng không dùng lại cảnh báo tiền kẹt:
          ở đây không phải đi mở máy lấy tiền ra mà là GHI TAY dòng thu rồi đối
          soát. Mã giao dịch Glory hiện ngay trên màn để đối chiếu được. */}
      {collectedNotRecorded && (
        <p className="max-w-md rounded-lg border border-destructive bg-destructive/10 px-4 py-3 text-sm font-medium text-destructive">
          {t("pos.dialog.payment.cash_changer.not_recorded_warning")}
          {session.error ? ` (${session.error})` : ""}
        </p>
      )}

      {retained && (
        <p className="max-w-md rounded-lg border border-destructive bg-destructive/10 px-4 py-3 text-sm font-medium text-destructive">
          {outcomeUnknown
            ? t("pos.dialog.payment.cash_changer.unknown_warning")
            : t("pos.dialog.payment.cash_changer.retained_warning")}
          {session.error ? ` (${session.error})` : ""}
        </p>
      )}

      {session.running && !outcomeUnknown ? (
        <Button
          variant="outline"
          onClick={onCancel}
          disabled={busy}
          className="h-11 rounded-lg"
        >
          {t("pos.dialog.payment.cash_changer.cancel")}
        </Button>
      ) : (
        <Button onClick={onDismiss} className="h-11 rounded-lg">
          {t("pos.dialog.payment.cash_changer.close")}
        </Button>
      )}
    </div>
  );
}
