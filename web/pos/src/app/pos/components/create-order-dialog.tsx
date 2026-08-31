/**
 * CreateOrderDialog — unified "+" action. Every field is optional; the POST
 * body contains only fields staff actually filled. Covers the 5 scenarios
 * in plan-007 DESIGN §"Order creation flow" from dine-in-with-table to
 * pure floating / takeaway.
 */

import { useEffect, useMemo, useState } from "react";
import {
  Alert,
  AlertDescription,
  Button,
  Dialog,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Label,
  Textarea,
} from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import { AlertCircleIcon, MinusIcon, PhoneIcon, PlusIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import type { CustomerOrderType, TableResource } from "../types";
import type { OrderCreateInput } from "@/services/order-service";
import { customerService } from "@/services/customer-service";
import { TablePicker } from "./table-picker";

// localStorage key for the last zone staff picked from in this shop. Scoped
// per shop so multi-shop deployments don't leak across. Versioned so a
// shape change (if zoneIds become strings like "zone:123") can bump without
// orphaning keys. Null / missing = "Tất cả" tab on the next open.
const LAST_ZONE_KEY = (shopSlug: string) => `pos:last-zone:v1:${shopSlug}`;

export interface CreateOrderDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  shopSlug: string;
  tables: TableResource[];
  onConfirm: (body: OrderCreateInput) => Promise<void>;
  /**
   * Pre-tick these table ids when the dialog opens. Used by the
   * tables-overview "tap a free table" shortcut so staff doesn't have to
   * pick the table again after the dialog mounts. Re-applied on every
   * open transition so reusing the dialog from a different table still
   * resets the selection correctly.
   */
  defaultTableIds?: string[];
}

export function CreateOrderDialog({
  open,
  onOpenChange,
  shopSlug,
  tables,
  onConfirm,
  defaultTableIds,
}: CreateOrderDialogProps) {
  const { t } = useTranslation();
  const ORDER_TYPES: { value: CustomerOrderType; label: string }[] = [
    { value: "spot", label: t("pos.order_type.spot") },
    { value: "dine_in", label: t("pos.order_type.dine_in") },
    { value: "takeaway", label: t("pos.order_type.takeaway") },
  ];
  const [orderType, setOrderType] = useState<CustomerOrderType>("spot");
  const [tableIds, setTableIds] = useState<Set<string>>(new Set());
  const [guestCount, setGuestCount] = useState<number | null>(null);
  const [note, setNote] = useState("");
  const [phone, setPhone] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  // #3211 — mở picker hay không.
  //
  // Vào dialog TỪ MỘT BÀN thì bàn đã được chọn xong rồi; bắt người ta nhìn thêm
  // một màn chọn bàn là hỏi lại câu vừa trả lời. Vào từ nút "Tạo đơn" chung thì
  // chưa chọn gì, nên picker phải mở như cũ.
  //
  // GẤP LẠI chứ không ẨN HẲN: ẩn hẳn thì bấm nhầm bàn là không sửa được trong
  // dialog, và luồng GỘP BÀN mất luôn đường thêm bàn thứ hai. Nút "Đổi bàn" giữ
  // cả hai đường mà không bắt ai chọn lại.
  const [pickerOpen, setPickerOpen] = useState(true);

  // Reset on close, hydrate `tableIds` from `defaultTableIds` on open.
  // Hydrating on open (not just on close-reset) lets the parent change
  // which table is preselected between two opens — e.g. tapping table A,
  // closing, then tapping table B should land on B not A.
  useEffect(() => {
    if (!open) {
      setOrderType("spot");
      setTableIds(new Set());
      setGuestCount(null);
      setNote("");
      setPhone("");
      setErrorMessage(null);
      return;
    }
    const preset = defaultTableIds && defaultTableIds.length > 0;
    if (preset) {
      setTableIds(new Set(defaultTableIds));
    }
    // Quyết định ở LƯỢT MỞ, không phải một lần lúc mount: cùng một dialog được
    // mở từ hai đường khác nhau, và `open` là thời điểm duy nhất biết đường nào.
    setPickerOpen(!preset);
  }, [open, defaultTableIds]);

  // Read last-used zone on each dialog open. Using useMemo keyed on `open`
  // means the value is fresh (picks up a zone stored by the PREVIOUS
  // successful submit within this same browser session) without having to
  // unmount/remount the dialog. The Radix Dialog unmounts DialogContent
  // when closed, so TablePicker re-reads this prop via its useState
  // initializer on each open.
  const defaultZoneId = useMemo<string | null>(() => {
    if (!open || typeof window === "undefined") return null;
    return localStorage.getItem(LAST_ZONE_KEY(shopSlug));
  }, [open, shopSlug]);

  function toggleTable(tableId: string) {
    setTableIds((prev) => {
      const next = new Set(prev);
      if (next.has(tableId)) next.delete(tableId);
      else next.add(tableId);
      return next;
    });
  }

  const selectedTableCodes = tables
    .filter((t) => tableIds.has(t.id))
    .map((t) => t.name ?? t.code);

  function formatError(e: unknown): string {
    if (e instanceof ApiError) {
      // Surface per-field validation errors when present
      const errors = e.body?.errors as Record<string, string[]> | undefined;
      if (errors) {
        return Object.entries(errors)
          .map(([field, msgs]) => `${field}: ${msgs.join(", ")}`)
          .join(" · ");
      }
      return (
        (e.body?.message as string | undefined) ?? `${t("common.error_api")} ${e.status}: ${e.message}`
      );
    }
    if (e instanceof Error) return e.message;
    return t("common.error_unknown");
  }

  async function handleConfirm() {
    const body: OrderCreateInput = {};
    if (orderType !== "spot") body.order_type = orderType;
    if (tableIds.size > 0) body.table_ids = Array.from(tableIds);
    if (guestCount !== null) body.guest_count = guestCount;
    const trimmedNote = note.trim();
    if (trimmedNote) body.note = trimmedNote;

    setErrorMessage(null);
    setSubmitting(true);
    try {
      // Resolve phone → customer_id (find-or-create) BEFORE creating the
      // order. Phone empty = no customer attached (customer_id stays
      // unset, backend stores null). Any error here shows in the banner
      // and prevents the order from being created so staff can retry.
      const trimmedPhone = phone.trim();
      if (trimmedPhone) {
        const resolved = await customerService.findOrCreateByPhone(
          shopSlug,
          trimmedPhone,
        );
        body.customer_id = resolved.data.id;
      }

      await onConfirm(body);
      // After a successful create, persist the zone of the FIRST picked
      // table. Staff typically stay in one area per shift, so the next
      // create-order will default to that zone. Skipped when no table was
      // picked (floating order) — floating orders carry no area context.
      if (tableIds.size > 0) {
        const firstPicked = tables.find((t) => tableIds.has(t.id));
        const zoneId = firstPicked?.zone?.id;
        if (zoneId && typeof window !== "undefined") {
          localStorage.setItem(LAST_ZONE_KEY(shopSlug), zoneId);
        }
      }
      onOpenChange(false);
    } catch (e) {
      setErrorMessage(formatError(e));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[90vh] w-[95vw] !max-w-5xl flex-col p-0">
        <DialogHeader className="shrink-0 border-b px-6 py-4">
          <div className="flex items-center gap-1.5">
            <DialogTitle>{t("pos.dialog.create_order.title")}</DialogTitle>
            <HelpButton topic="create-order" className="size-7" />
          </div>
          <DialogDescription>
            {t("pos.dialog.create_order.desc")}
          </DialogDescription>
        </DialogHeader>

        <div className="flex-1 overflow-y-auto px-6 py-4">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-[3fr_2fr]">
            {/* Left column — order type + table picker */}
            <div className="space-y-5">
              <div className="space-y-2">
                <Label>{t("pos.dialog.create_order.order_type")}</Label>
                <div className="flex flex-wrap gap-2">
                  {ORDER_TYPES.map((t) => (
                    <Button
                      key={t.value}
                      type="button"
                      variant={orderType === t.value ? "default" : "outline"}
                      size="sm"
                      style={{padding: "8px 16px", borderRadius: 10}}
                      onClick={() => setOrderType(t.value)}
                    >
                      {t.label}
                    </Button>
                  ))}
                </div>
              </div>

              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label>
                    {t("pos.dialog.create_order.table")}{" "}
                    {tableIds.size > 0 && (
                      <span className="text-muted-foreground font-normal">
                        ({tableIds.size} {t("pos.dialog.create_order.table_selected")})
                      </span>
                    )}
                  </Label>
                  {tableIds.size > 0 && (
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => setTableIds(new Set())}
                    >
                      {t("pos.dialog.create_order.deselect_all")}
                    </Button>
                  )}
                </div>
                {selectedTableCodes.length > 0 && (
                  <div className="text-xs text-muted-foreground">
                    {t("pos.dialog.create_order.selected")}{" "}
                    <span className="font-medium text-foreground">
                      {selectedTableCodes.join(", ")}
                    </span>
                  </div>
                )}
                {pickerOpen ? (
                  <>
                    <div className="rounded-md border">
                      <TablePicker
                        multi
                        tables={tables}
                        selectedIds={tableIds}
                        onToggle={(t) => toggleTable(t.id)}
                        maxHeightClass="h-72"
                        defaultZoneId={defaultZoneId}
                      />
                    </div>
                    <p className="text-[11px] text-muted-foreground">
                      {t("pos.dialog.create_order.table_hint")}
                    </p>
                  </>
                ) : (
                  // Bàn đã chọn sẵn. Tên bàn nằm ngay trên (`selectedTableCodes`),
                  // nên ở đây chỉ cần đường QUAY LẠI picker — cho lượt bấm nhầm
                  // bàn, và cho luồng gộp bàn thêm bàn thứ hai.
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-slot="create-order-change-table"
                    onClick={() => setPickerOpen(true)}
                  >
                    {t("pos.dialog.create_order.change_table")}
                  </Button>
                )}
              </div>
            </div>

            {/* Right column — guest count + phone + note */}
            <div className="space-y-5">
              <div className="space-y-2">
                <div className="flex h-8 items-center justify-between">
                  <Label>{t("pos.dialog.create_order.guest_count")}</Label>
                  {guestCount !== null && (
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      style={{height:"50px"}}
                      onClick={() => setGuestCount(null)}
                    >
                      {t("common.skip")}
                    </Button>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="h-9 w-9 shrink-0"
                    onClick={() =>
                      setGuestCount((n) => (n === null ? null : Math.max(1, n - 1)))
                    }
                    disabled={guestCount === null || guestCount <= 1}
                    aria-label={t("common.decrease")}
                  >
                    <MinusIcon className="size-4" />
                  </Button>
                  <Input
                    value={guestCount === null ? "" : String(guestCount)}
                    onChange={(e) => {
                      const raw = e.target.value.trim();
                      if (raw === "") return setGuestCount(null);
                      const parsed = parseInt(raw, 10);
                      if (!Number.isNaN(parsed) && parsed >= 1) {
                        setGuestCount(parsed);
                      }
                    }}
                    placeholder="—"
                    className={cn("h-9 w-20 text-center", "tabular-nums")}
                  />
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="h-9 w-9 shrink-0"
                    onClick={() => setGuestCount((n) => (n ?? 0) + 1)}
                    aria-label={t("common.increase")}
                  >
                    <PlusIcon className="size-4" />
                  </Button>
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="create-order-phone">
                  {t("pos.dialog.create_order.phone")}
                </Label>
                <div className="relative">
                  <PhoneIcon className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    id="create-order-phone"
                    type="tel"
                    inputMode="tel"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    placeholder={t("pos.dialog.create_order.phone_placeholder")}
                    className="h-9 pl-9"
                  />
                </div>
                <p className="text-[11px] text-muted-foreground">
                  {t("pos.dialog.create_order.phone_hint")}
                </p>
              </div>

              <div className="space-y-2">
                <Label htmlFor="create-order-note">{t("pos.dialog.create_order.note")}</Label>
                <Textarea
                  id="create-order-note"
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                  placeholder={t("pos.dialog.create_order.note_placeholder")}
                  rows={3}
                />
              </div>
            </div>
          </div>
        </div>

        {errorMessage && (
          <div className="w-full shrink-0 px-6 pb-3">
            <Alert variant="destructive" className="w-full overflow-hidden">
              <AlertCircleIcon className="size-4 shrink-0" />
              <AlertDescription className="min-w-0 break-words">
                {errorMessage}
              </AlertDescription>
            </Alert>
          </div>
        )}

        <DialogFooter className="shrink-0 border-t bg-muted/30 px-6 py-3">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={submitting}
            style={{padding: "12px 25px", borderRadius: "10px"}}
          >
            {t("common.cancel")}
          </Button>
          <Button onClick={handleConfirm} disabled={submitting} style={{padding: "12px 35px", borderRadius: "10px"}}>
            {submitting ? t("pos.dialog.create_order.state") : t("pos.dialog.create_order.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
