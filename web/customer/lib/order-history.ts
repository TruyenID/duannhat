/**
 * Logic thuần của card lịch sử đơn hàng — dùng chung cho `/orders` (khách vãng
 * lai) và `/account/orders` (khách đã đăng nhập).
 *
 * Nằm ở `lib/` chứ không nằm cạnh component vì đây là phần dễ sai nhất và là
 * phần duy nhất kiểm chứng được bằng test: quyết định đơn nào thuộc tab nào, và
 * đơn nào được hiện nút "Thanh toán" / "Viết đánh giá". Hai màn hình từng có
 * hai bản riêng và đã lệch nhau; giờ chỉ còn một bản, có test.
 *
 * Render (JSX) sống ở `components/order-history-card.tsx`.
 */

export interface OrderHistoryItem {
  id: string;
  /** Backend line item SKU (CustomerOrderItem.product_sku_id). Used for reorder. */
  product_sku_id?: string | null;
  name: string | null;
  image_url: string | null;
  qty: number;
  /**
   * BE `OrderItemStatusEnum`: pending | preparing | ready | served | voided.
   * Dùng để derive kitchen status ở FE (BE order.status không phản ánh
   * tiến độ bếp — card payment sẽ set order.status=closed ngay, nhưng
   * items vẫn `pending` cho tới khi staff bếp update).
   */
  status: string;
  /**
   * Toppings/options snapshot for this line. Each option entry corresponds to a
   * CustomerOrderItemTopping row on the backend. `product_sku_id` is exposed in
   * case the FE wants to reconstruct toppings when implementing reorder.
   */
  options?: Array<{
    id: string;
    name: string | null;
    unit_price: number;
    quantity: number;
    product_sku_id?: string | null;
  }>;
}

/** Shape của `CustomerOrderSummaryResource` — chung cho cả hai màn hình. */
export interface OrderHistorySummary {
  id: string;
  code: string;
  status: string;
  /** BE `OrderTypeEnum`: takeaway | dine_in | spot. Thiếu → coi là takeaway. */
  order_type?: string | null;
  placed_at?: string | null;
  items: OrderHistoryItem[];
  total: number;
  paid: number;
  /** ISO 4217 currency the order was created in (from its branch). Each card
   *  formats money with THIS, not the ambient selected-branch currency. */
  currency: string;
  is_fully_paid: boolean;
  /**
   * Số payment record trên BE. 0 = chưa có payment intent nào (counter
   * flow chưa trả tiền). >0 = đã có ít nhất 1 Stripe PI (card flow, dù
   * succeeded hay failed). Dùng để phân biệt "chưa thanh toán tại quầy"
   * (cần show button "Thanh toán") vs "đã chọn flow card" (không show).
   */
  payment_count: number;
  /**
   * plan-031 — payment deadline for takeaway counter orders.
   * ISO 8601 timestamp. Null for paid orders or dine-in.
   */
  payment_due_at?: string | null;
  /** plan-031 — server-authoritative remaining seconds (skew-immune anchor). */
  seconds_until_due?: number | null;
  /** plan-031 — server says the deadline is past. Authoritative over client clock. */
  is_payment_overdue?: boolean | null;
  /** Branch the order belongs to — reorder resolves the menu from THIS slug
   *  (more reliable than the localStorage pointer's `shop`). */
  branch?: { id?: string; name?: string; slug?: string | null } | null;
  /**
   * #1758 — đơn đã có đánh giá (món HOẶC chi nhánh). BE tính theo đơn, không
   * theo từng món: trang `/review` cho gửi một trong hai là đủ, nên nếu chia
   * nhỏ hơn thì card lại mời khách bấm vào một trang không còn gì để gửi.
   *
   * Thiếu trường (BE cũ) → coi như chưa đánh giá: mời đánh giá thừa một lần là
   * phiền, còn nuốt mất nút đánh giá thì mất hẳn dữ liệu của khách.
   */
  is_reviewed?: boolean | null;
}

export type OrderHistoryTab = "all" | "pending" | "paid";

/**
 * Đơn có thuộc tab đang chọn không.
 *
 * `nowMs` là tham số chứ không phải `Date.now()` ngầm bên trong: hạn thanh toán
 * là một so sánh với đồng hồ, và một hàm đọc lén đồng hồ thì không test được.
 * Call site trong lúc render truyền `Date.now()` — đúng như bản cũ vẫn làm.
 */
export function matchesOrderTab(
  order: OrderHistorySummary,
  tab: OrderHistoryTab,
  nowMs: number,
): boolean {
  // plan-037: đơn ở trạng thái awaiting_confirmation tuyệt đối không hiển
  // thị (bất kỳ tab nào). User đang trong bước review/xác nhận — đơn chưa
  // "vào sổ".
  if (order.status === "awaiting_confirmation") return false;

  // Fallback an toàn: tính toán paid vs total nếu BE không expose
  // is_fully_paid một cách nhất quán.
  const total = typeof order.total === "number" ? order.total : 0;
  const paid = typeof order.paid === "number" ? order.paid : 0;
  const isFullyPaid =
    typeof order.is_fully_paid === "boolean"
      ? order.is_fully_paid
      : total > 0 && paid >= total;

  if (tab === "all") return true;

  if (tab === "pending") {
    // "Chưa thanh toán" — LOẠI BỎ cancelled + closed orders
    if (order.status === "cancelled") return false;
    // Đơn đã closed (kiosk pay xong) không tính pending nữa
    if (order.status === "closed") return false;
    // Đã fully paid không tính pending
    if (isFullyPaid) return false;

    // 1) Đơn có payment_due_at VÀ CHƯA quá hạn (vẫn trong thời gian countdown)
    if (order.payment_due_at) {
      const dueMs = Date.parse(order.payment_due_at);
      if (!Number.isNaN(dueMs)) {
        // Đã hết hạn → không còn trong tab "Chưa thanh toán"
        if (dueMs <= nowMs) return false;
        // Còn thời gian → vẫn coi là pending
        return true;
      }
    }

    // 2) Mọi đơn chưa thanh toán đủ (paid < total hoặc BE báo chưa fully paid)
    if (!isFullyPaid) return true;

    // 3) Fallback bảo vệ: status còn đang active vẫn coi là chưa thanh toán
    const activeStatuses = ["open", "dining", "checkout", "paying", "pending"];
    return activeStatuses.includes(order.status);
  }

  if (tab === "paid") {
    // "Đã thanh toán" — chỉ đơn đã fully paid (LOẠI BỎ cancelled)
    if (order.status === "cancelled") return false;
    return isFullyPaid;
  }

  return true;
}

/**
 * Card có 2 thông tin độc lập:
 *   - `kitchen` = top pill (Đang chuẩn bị / Đã sẵn sàng / none)
 *   - `payment` = bottom row (Chưa thanh toán / Đã thanh toán / Đã hoàn
 *     thành) + action button kèm theo
 *
 * 2 mảng này render độc lập — 1 card có thể có cả top pill + bottom row
 * (vd: "Đang chuẩn bị" + "Đã thanh toán" / "Viết đánh giá").
 */
export type OrderStatusUI = {
  kitchen: {
    labelKey: "statusPreparing" | "statusReady";
    bg: string;
    fg: string;
  } | null;
  payment: {
    labelKey:
      | "statusUnpaid"
      | "statusPaid"
      | "statusCompleted"
      | "statusCancelled";
    dotColor: "red" | "green";
    /** `reviewed` = trạng thái tĩnh "Đã đánh giá", KHÔNG phải nút bấm được. */
    action: "continue-pay" | "review" | "reviewed" | null;
  } | null;
};

/**
 * Aggregate per-item `OrderItemStatusEnum` thành 1 kitchen state cho cả
 * order. BE order.status không phản ánh tiến độ bếp (card payment set
 * closed ngay), nên tin items[].status là nguồn chính xác hơn.
 *
 * Logic:
 *   - Tất cả items `served` (đã giao cho khách) → "all-served"
 *   - Tất cả items `ready` hoặc `served` (bếp xong, chờ giao / đã giao)
 *     → "ready" — báo khách đến lấy
 *   - Còn lại (có `pending` / `preparing`) → "preparing"
 *   - Không có item nào (voided hết) → null
 */
export function deriveKitchenFromItems(
  items: { status: string }[] | undefined,
): "preparing" | "ready" | "all-served" | null {
  if (!items || items.length === 0) return null;
  const active = items.map((i) => i.status).filter((s) => s !== "voided");
  if (active.length === 0) return null;

  if (active.every((s) => s === "served")) return "all-served";
  if (active.every((s) => s === "ready" || s === "served")) return "ready";
  return "preparing";
}

export function mapOrderStatusUI(
  status: string | undefined,
  isFullyPaid: boolean | undefined,
  paymentCount: number | undefined,
  items: OrderHistoryItem[] | undefined,
  isReviewed?: boolean | null,
): OrderStatusUI {
  // Cancelled (timeout) → show cancelled status
  if (status === "cancelled") {
    return {
      kitchen: null,
      payment: {
        labelKey: "statusCancelled",
        dotColor: "red",
        action: null,
      },
    };
  }

  // Voided → không pill, không payment row (đơn đã huỷ)
  if (status === "voided") {
    return { kitchen: null, payment: null };
  }

  // ─── Kitchen base state — derive từ items[].status (chính xác hơn
  // order.status). Card paid: BE set order.status=closed ngay, nhưng items
  // vẫn `pending` → derive sẽ trả "preparing" → khách thấy đang nấu thay vì
  // "hoàn thành" nhầm. Phần này CHỈ áp dụng cho đơn đã thanh toán; với đơn
  // chưa thanh toán, pill bếp sẽ bị ẩn để tránh gây hiểu nhầm.
  const itemDerived = deriveKitchenFromItems(items);

  // ─── Payment row — combine items state + payment state ────────────
  //   - itemDerived === "all-served" → đã thanh toán + đã giao cho khách
  //     → "Đã hoàn thành" + button "Viết đánh giá"
  //   - is_fully_paid (nhưng món chưa served xong) → "Đã thanh toán"
  //     với dot only, KHÔNG có button review (khách chưa nhận hàng,
  //     chưa thể đánh giá món)
  //   - !is_fully_paid && payment_count = 0 → counter chưa trả tại quầy
  //   - else (card flow đang dở) → null hide bottom
  let payment: OrderStatusUI["payment"] = null;
  // QUAN TRỌNG: chỉ coi là "Đã hoàn thành" + cho "Viết đánh giá" khi đơn ĐÃ
  // THANH TOÁN đủ. Trước đây chỉ check items all-served nên một đơn takeaway
  // chưa trả tiền (nhưng bếp đã giao hết món) bị hiện "Đã hoàn thành" + nút
  // review thay vì nút "Thanh toán". Payment state phải ưu tiên: chưa trả đủ
  // → luôn rơi xuống nhánh statusUnpaid + continue-pay bên dưới.
  if (isFullyPaid && itemDerived === "all-served") {
    payment = {
      labelKey: "statusCompleted",
      dotColor: "green",
      // #1758 — đã đánh giá thì đổi nút thành trạng thái tĩnh. Bấm lại chỉ dẫn
      // tới một trang review đã rỗng (món `already_reviewed` bị lọc ra, còn
      // branch-review thì BE trả 422 `already_reviewed`) — nút mời bấm nhưng
      // phía sau không còn gì để làm.
      action: isReviewed ? "reviewed" : "review",
    };
  } else if (isFullyPaid) {
    // Đã trả tiền nhưng món chưa server hết → show "Đã thanh toán" để
    // khách yên tâm là payment OK, nhưng KHÔNG cho review (món chưa
    // tới tay khách, đánh giá vô nghĩa).
    payment = {
      labelKey: "statusPaid",
      dotColor: "green",
      action: null,
    };
  } else if ((paymentCount ?? 0) === 0) {
    payment = {
      labelKey: "statusUnpaid",
      dotColor: "red",
      action: "continue-pay",
    };
  }

  // ─── Kitchen pill — chỉ hiển thị cho đơn ĐANG được pay/serve (vd:
  // đã pay từ kiosk nhưng bếp đang làm món → "Đang chuẩn bị"). Đơn
  // chưa thanh toán: ẩn để tránh nhầm "đã pay rồi đang nấu". Đơn đã
  // statusPaid/statusCompleted (hoàn tất giao dịch): cũng ẩn vì
  // payment status đã đủ tự thể hiện cho khách biết đơn xong.
  let kitchen: OrderStatusUI["kitchen"] = null;
  const hideKitchenPill =
    !payment ||
    payment.labelKey === "statusUnpaid" ||
    payment.labelKey === "statusPaid" ||
    payment.labelKey === "statusCompleted";
  if (!hideKitchenPill) {
    if (itemDerived === "ready") {
      kitchen = {
        labelKey: "statusReady",
        bg: "#FEF3C7", // amber-100
        fg: "#B45309", // amber-700
      };
    } else if (itemDerived === "preparing") {
      kitchen = {
        labelKey: "statusPreparing",
        bg: "#FEF3C7",
        fg: "#B45309",
      };
    }
  }
  // itemDerived === "all-served" hoặc null → không show kitchen pill

  return { kitchen, payment };
}
