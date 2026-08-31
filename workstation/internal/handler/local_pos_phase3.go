package handler

import (
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"math"
	"net/http"
	"strconv"
	"strings"
	"sync"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// Phase 3 — multi-table merge/unmerge + read-only split-bill preview.

// POST /api/v1/pos/orders/{id}/merge-table
//
// Body: { table_id }
func (s *Server) handleLocalPosMergeTable(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	var body struct {
		TableID string `json:"table_id"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	o, err := s.orders.MergeTable(id, body.TableID)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		if errors.Is(err, service.ErrOrderNotOpen) {
			writeError(w, http.StatusConflict, "order is not open")
			return
		}
		if errors.Is(err, service.ErrTableOccupied) {
			writeError(w, http.StatusConflict, "Table is already occupied by another order.")
			return
		}
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	s.hub.BroadcastEvent("order_updated", o)
	s.enqueueOrderSync("order.merge_table", id, map[string]any{"table_id": body.TableID})
	s.auditLogPOS(r, "order.merge_table", "order", id, "")
	writeJSON(w, http.StatusOK, map[string]any{"data": s.shapeOrderForResponse(r, o)})
}

// POST /api/v1/pos/orders/{id}/unmerge-table
//
// Body: { table_id }
func (s *Server) handleLocalPosUnmergeTable(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	var body struct {
		TableID string `json:"table_id"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	o, err := s.orders.UnmergeTable(id, body.TableID)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "binding not found")
			return
		}
		if errors.Is(err, service.ErrOrderNotOpen) {
			writeError(w, http.StatusConflict, "order is not open")
			return
		}
		if errors.Is(err, service.ErrCannotUnmergeLastDineIn) {
			writeError(w, http.StatusConflict, "cannot unmerge the last table from a dine-in order")
			return
		}
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	s.hub.BroadcastEvent("order_updated", o)
	s.enqueueOrderSync("order.unmerge_table", id, map[string]any{"table_id": body.TableID})
	s.auditLogPOS(r, "order.unmerge_table", "order", id, "")
	writeJSON(w, http.StatusOK, map[string]any{"data": s.shapeOrderForResponse(r, o)})
}

// GET /api/v1/pos/orders/{id}/split-bill?split_count={n}
//
// Read-only equal-split preview. Response shape EXACTLY mirrors backend's
// CustomerOrderService::splitBill so pos-web's useSplitBill hook reads
// the LAN and Cloud responses identically:
//
//	{
//	  total_amount:        "1000.00",       // string (decimal)
//	  remaining_amount:    "1000.00",       // string — total - paid
//	  split_count:         4,
//	  per_person_amount:   "250.00",        // string (= per_person_amounts[0])
//	  per_person_amounts:  ["250.00", ...], // []string, len = split_count
//	  rounding_note:       null | string    // populated when first-payer absorbs remainder
//	}
//
// Rounding rule mirrors backend:
//   - base = floor(remaining / split_count)
//   - remainder = remaining - base*split_count
//   - person[0] = base + remainder  (FIRST person absorbs, not last)
//   - person[1..] = base
//
// Pre-fix this handler returned `{bill_totals: []int, items_per_bill: [...]}`
// — a workstation-native shape pos-web's TypeScript types reject, which
// is why the "chia đều" button looked broken on the LAN. The result was
// "GET succeeded but per_person_amounts is undefined" → render crash.
func (s *Server) handleLocalPosSplitBill(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")

	splitCount := 0
	if raw := r.URL.Query().Get("split_count"); raw != "" {
		if n, err := strconv.Atoi(raw); err == nil {
			splitCount = n
		}
	}

	resp, status, err := s.computeEqualSplitPayload(id, splitCount)
	if err != nil {
		writeError(w, status, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, resp)
}

// computeEqualSplitPayload returns the JSON envelope plus HTTP status
// and error. Split out so the rich POST path (by_items / by_amount) can
// reuse the order lookup + decimal formatting.
//
// Returns (payload, statusCode, err). status is only meaningful when
// err != nil; on success the caller writes 200.
//
// plan-043 T5.6 — 4th split mirror (per-rate). The equal-split-by-people
// path does NOT compute tax here: it divides order.TotalAmount, which the
// §8 per-rate engine (service.priceGroups via computeOrderTotals /
// NormalizedTotals) already stamped onto orders.total_amount at every
// AddItems/void/update/coupon mutation. That total is the sum of the
// per-rate tax groups (端数処理は税率ごとに1回), so a mixed-rate order
// (bentō ¥1000 @8% + beer ¥500 @10% → tax 130, total 1630) splits equally
// exactly like the backend calculator. There is no legacy single-rate
// computation in this handler — the per-rate correctness lives upstream in
// the engine and flows through order.TotalAmount unchanged.
func (s *Server) computeEqualSplitPayload(orderID string, splitCount int) (map[string]any, int, error) {
	order, err := s.orders.GetByID(orderID)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, http.StatusNotFound, fmt.Errorf("order not found")
		}
		return nil, http.StatusInternalServerError, err
	}

	// Default split_count to guest_count (nullable post-031 — fall
	// through to 0 then the validation below rejects with 422).
	if splitCount == 0 && order.GuestCount != nil {
		splitCount = *order.GuestCount
	}
	if splitCount < 2 {
		return nil, http.StatusUnprocessableEntity,
			fmt.Errorf("split_count must be at least 2")
	}

	remaining := order.TotalAmount - order.PaidAmount
	if remaining < 0 {
		remaining = 0
	}

	zeroes := func(n int) []string {
		out := make([]string, n)
		for i := range out {
			out[i] = s.formatAmount(0)
		}
		return out
	}

	if remaining == 0 {
		// Backend's CustomerOrderController@splitBill returns the
		// computed array directly (`response()->json($result)`) — NO
		// `data:` envelope. pos-web's getSplitBill() reads the flat
		// shape via `apiFetch<SplitBillResponse>`. Wrapping here would
		// land on the wire as `{data:{per_person_amounts:[...]}}` and
		// then `splitData.per_person_amounts` is undefined → endless
		// loading skeleton (the user-reported "rows never render"
		// bug). Stay flat for parity.
		return map[string]any{
			"total_amount":       s.formatAmount(order.TotalAmount),
			"remaining_amount":   "0.00",
			"split_count":        splitCount,
			"per_person_amount":  "0.00",
			"per_person_amounts": zeroes(splitCount),
			"rounding_note":      nil,
		}, http.StatusOK, nil
	}

	base := remaining / splitCount
	remainder := remaining - base*splitCount

	amounts := make([]string, splitCount)
	var roundingNote any
	for i := 0; i < splitCount; i++ {
		if i == 0 && remainder > 0 {
			amounts[i] = s.formatAmount(base + remainder)
			roundingNote = fmt.Sprintf(
				"First person pays extra %s due to rounding",
				s.formatAmount(remainder),
			)
		} else {
			amounts[i] = s.formatAmount(base)
		}
	}

	return map[string]any{
		"total_amount":       s.formatAmount(order.TotalAmount),
		"remaining_amount":   s.formatAmount(remaining),
		"split_count":        splitCount,
		"per_person_amount":  amounts[0],
		"per_person_amounts": amounts,
		"rounding_note":      roundingNote,
	}, http.StatusOK, nil
}

// formatAmount renders a WHOLE-UNIT integer amount as the decimal-string shape
// the backend uses ("1000.00"). Clients (pos-web) coerce back via Number() when
// arithmetic is needed.
//
// The name matters (#1246). This used to be `formatYen`, which read as a
// currency-specific formatter and hid a whole-pipeline constraint: the LAN money
// path carries amounts as `int` WHOLE UNITS, which is only correct while every
// shop runs a zero-decimal currency. JPY and VND both are, so nothing is wrong
// today — but the rounding layer beside it already understands every currency
// (`service.CurrencyStep` returns 0.01 / 0.001 for the rest), so the two layers
// disagree, and only this one is silent about it.
//
// What breaks the day a two-decimal shop opens: the cents are gone BEFORE this
// function runs — the amount reached here as an `int`, so 12.34 already became
// 12 — and appending ".00" makes the wrong number look perfectly well-formed.
// 34 cents off per row, nothing raised.
//
// So this does NOT try to format the cents back; they are unrecoverable here.
// It fails LOUDLY instead, once per process, and leaves the actual fix (making
// the LAN pipeline carry minor units) to whoever opens that currency. Turning a
// silent wrong number into a logged one is the whole point of this change.
func (s *Server) formatAmount(amount int) string {
	assertWholeUnitCurrency(s.currencyCode())

	return formatWholeUnits(amount)
}

// wholeUnitSuffix is the fractional part this whole-unit pipeline always emits.
// It exists so the render and the parse below cannot drift: the reconciliation
// step reads its own output back, and when the day comes to carry minor units
// BOTH sides have to change together.
const wholeUnitSuffix = ".00"

// formatWholeUnits is the pure rendering half — no settings, no logging — so the
// shape stays testable on its own.
func formatWholeUnits(amount int) string {
	return strconv.Itoa(amount) + wholeUnitSuffix
}

// parseWholeUnits reads back what formatWholeUnits wrote. Only the split-by-items
// reconciliation needs it: it re-opens the last non-empty row to absorb the
// rounding difference, and it had been stripping the literal ".00" by hand in
// two places — a second copy of the same assumption, one grep away from being
// missed.
func parseWholeUnits(formatted string) int {
	n, _ := strconv.Atoi(strings.TrimSuffix(formatted, wholeUnitSuffix))

	return n
}

// isWholeUnitCurrency reports whether one unit of `code` is the smallest unit,
// i.e. whether an `int` amount can represent this currency without loss. Derived
// from the SAME table the rounding layer uses, so the two can never drift apart.
func isWholeUnitCurrency(code string) bool {
	return service.CurrencyStep(code) == 1
}

// warnOnce keeps the currency complaint to one line per process: formatAmount
// runs up to 17 times per response, and a misconfigured deployment does not need
// 17 identical errors per request to be understood.
var warnOnce sync.Once

func assertWholeUnitCurrency(code string) {
	if isWholeUnitCurrency(code) {
		return
	}

	warnOnce.Do(func() {
		slog.Error(
			"LAN money is whole-unit only — amounts for this currency lose their fractional part before they are formatted (#1246)",
			"currency", code,
			"currency_step", service.CurrencyStep(code),
			"impact", "split-bill preview and order totals served over LAN are wrong by the fractional part",
		)
	})
}

// currencyCode is the shop's ISO 4217 code, defaulting the same way the backend
// does when `shop_order_settings` has no row.
func (s *Server) currencyCode() string {
	if code := s.settingValue("currency_code"); code != "" {
		return code
	}

	return "JPY"
}

// ─── Split-by-items preview ──────────────────────────────────────────────
//
// GET /api/v1/pos/orders/{id}/split-by-items/preview?allocations=<JSON>
//
// pos-web's SplitBillByItemsTab POSTs allocations as a URL-encoded JSON
// query param (see backend's CustomerOrderController@splitByItemsPreview
// for the exact contract). Response shape mirrors Cloud verbatim so
// pos-web's TypeScript reads both LAN + Cloud identically:
//
//	{
//	  data: {
//	    order_id,
//	    total_amount, allocated_amount, remaining_amount,
//	    rounding_mode, rounding_step, currency_code,
//	    items:[
//	      {item_id, product_sku_id, quantity, units_claimed, units_remaining,
//	       claims:[{payment_id, bill_index, units, status}, ...]}
//	    ],
//	    preview_bills:[
//	      {bill_index, subtotal, discount, tax, service, total, is_empty}
//	    ]
//	  }
//	}
//
// preview_bills is omitted when no `allocations` query param is supplied
// (preview-only mode: just return per-item claim state).
type splitByItemsAllocationInput struct {
	ItemID    string `json:"item_id"`
	Units     int    `json:"units"`
	BillIndex int    `json:"bill_index"`
}

func (s *Server) handleLocalPosSplitByItemsPreview(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")

	order, err := s.orders.GetByID(id)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		writeServerError(w, r, err)
		return
	}

	// Decode `allocations` query param (URL-encoded JSON array). Empty
	// allocations = preview-only mode (don't compute preview_bills).
	var allocations []splitByItemsAllocationInput
	if raw := r.URL.Query().Get("allocations"); raw != "" {
		if len(raw) > 4096 {
			writeError(w, http.StatusUnprocessableEntity,
				"allocations exceeds 4096-byte limit")
			return
		}
		if err := json.Unmarshal([]byte(raw), &allocations); err != nil {
			writeError(w, http.StatusUnprocessableEntity,
				"invalid allocations JSON: "+err.Error())
			return
		}
	}

	// #2165 — `people_count` tường minh, như PHP SplitByItemsCalculator nhận
	// $peopleCount. Không có (client cũ) → 0, compute rơi về max(bill_index)+1;
	// nhưng suy như vậy NUỐT hoá đơn con rỗng ở cuối, nên pos-web luôn gửi.
	peopleCount := 0
	if raw := r.URL.Query().Get("people_count"); raw != "" {
		n, err := strconv.Atoi(raw)
		if err != nil || n < 1 || n > 500 {
			writeError(w, http.StatusUnprocessableEntity,
				"people_count must be an integer in [1, 500]")
			return
		}
		peopleCount = n
	}

	// Read shop settings for currency + rounding hints. Defaults
	// match backend's fallback when shop_order_settings has no row.
	currencyCode := s.settingValue("currency_code")
	if currencyCode == "" {
		currencyCode = "JPY"
	}
	roundingMode := s.settingValue("split_bill_rounding_mode")
	if roundingMode == "" {
		roundingMode = "auto"
	}
	roundingStep := "0.001"
	if currencyCode == "JPY" || currencyCode == "VND" {
		// Zero-decimal currencies — backend uses step=1 in
		// SplitByItemsCalculator's RoundingMode::step().
		roundingStep = "1"
	}

	// Aggregate existing claims from non-failed payments' metadata.
	// Workstation stores per-payment metadata as JSON in payments.metadata
	// (migration 014). Failed payments contribute nothing.
	itemClaims := loadByItemsClaims(s, id)

	// Build per-item response rows. Iterate order.Items to keep the
	// returned order stable + so voided items still appear with
	// quantity=0 (callers display them struck-through).
	itemRows := make([]map[string]any, 0, len(order.Items))
	for _, it := range order.Items {
		qty := it.Quantity
		if it.Status == service.ItemStatusVoided {
			qty = 0
		}
		claims := itemClaims[it.ID]
		var claimed int
		for _, c := range claims {
			claimed += c["units"].(int)
		}
		remaining := qty - claimed
		if remaining < 0 {
			remaining = 0
		}
		// W1: resolve non-empty display fields (name / product_name / sku_code)
		// so the split picker never shows a blank món row.
		name, productName, skuCode, _ := s.resolveItemDisplay(it)
		itemRows = append(itemRows, map[string]any{
			"item_id":         it.ID,
			"product_sku_id":  it.ProductSkuID,
			"name":            name,
			"product_name":    nilIfEmpty(productName),
			"sku_code":        nilIfEmpty(skuCode),
			"quantity":        qty,
			"units_claimed":   claimed,
			"units_remaining": remaining,
			"claims":          claims,
		})
	}

	resp := map[string]any{
		"order_id":         order.ID,
		"total_amount":     s.formatAmount(order.TotalAmount),
		"allocated_amount": s.formatAmount(order.PaidAmount),
		"remaining_amount": s.formatAmount(order.TotalAmount - order.PaidAmount),
		"rounding_mode":    roundingMode,
		"rounding_step":    roundingStep,
		"currency_code":    currencyCode,
		"items":            itemRows,
	}

	// Compute preview_bills only when the caller supplied candidate
	// allocations. Mirrors backend's "no allocations → preview-only" branch.
	if len(allocations) > 0 {
		bills, err := computeByItemsPreviewBills(order, allocations, s.formatAmount, s.deriveOrderMoney(order), peopleCount)
		if err != nil {
			writeError(w, http.StatusUnprocessableEntity, err.Error())
			return
		}
		resp["preview_bills"] = bills
	}

	writeJSON(w, http.StatusOK, map[string]any{"data": resp})
}

// loadByItemsClaims scans payments for this order whose metadata.split_mode
// is "by_items" and aggregates the per-item claim list. Each entry returns
// {payment_id, bill_index, units, status} matching the Cloud preview shape.
func loadByItemsClaims(s *Server, orderID string) map[string][]map[string]any {
	out := map[string][]map[string]any{}
	rows, err := s.db.Query(`
		SELECT id, COALESCE(metadata,''), COALESCE(status,'')
		FROM payments
		WHERE order_id = ?
		  AND COALESCE(status,'') NOT IN ('failed','voided')`,
		orderID)
	if err != nil {
		return out
	}
	defer rows.Close()
	for rows.Next() {
		var paymentID, metaRaw, status string
		if err := rows.Scan(&paymentID, &metaRaw, &status); err != nil || metaRaw == "" {
			continue
		}
		var meta struct {
			SplitMode       string                        `json:"split_mode"`
			BillIndex       int                           `json:"bill_index"`
			ItemAllocations []splitByItemsAllocationInput `json:"item_allocations"`
		}
		if json.Unmarshal([]byte(metaRaw), &meta) != nil {
			continue
		}
		if meta.SplitMode != "by_items" {
			continue
		}
		for _, a := range meta.ItemAllocations {
			out[a.ItemID] = append(out[a.ItemID], map[string]any{
				"payment_id": paymentID,
				"bill_index": meta.BillIndex,
				"units":      a.Units,
				"status":     status,
			})
		}
	}
	return out
}

// computeByItemsPreviewBills builds the per-bill breakdown for candidate
// allocations. Logic mirrors backend's SplitByItemsCalculator.compute():
//
//  1. Per bill: sum (item.unit_price + item.topping_subtotal) * units —
//     `topping_subtotal` is per unit, so it is NOT divided by the line qty.
//  2. Apply proportional share of order.discount_amount.
//  3. Tax + service = round(taxable_base * rate / 100). Rates pulled
//     from order's already-stored amounts (tax_amount/subtotal ratio)
//     so the LAN preview matches whatever Cloud will charge once the
//     payment lands. Avoids needing a separate ShopOrderSetting read.
//  4. Reconcile last non-empty bill so sum(bills.total) == order.total
//     exactly when allocations cover everything.
//
// formatAmount is passed in rather than reached for: this function has no
// Server, and the money shape it emits depends on the shop's currency (#1246).
// Taking it as a parameter puts that dependency in the signature instead of
// hiding it behind a package-level helper that happens to assume yen.
func computeByItemsPreviewBills(
	order *service.Order,
	allocations []splitByItemsAllocationInput,
	formatAmount func(int) string,
	money orderMoney,
	requestedPeople int,
) ([]map[string]any, error) {
	// Effective rates derived from the order itself (works without a
	// shop_order_settings row).
	//
	// #2075 — số tiền lấy từ SỔ `order_conditions` khi sổ có, rơi về ba cột khi
	// chưa. Đây là chỗ nguy hiểm nhất trong bốn chỗ đọc cột, vì nó SUY NGƯỢC tỉ
	// lệ: cột về 0 ⇒ `taxRate` = 0 ⇒ preview chia bill ra 0 thuế **ngay tại
	// quầy**, không lỗi nào, và khoảng lệch bằng đúng số thuế sẽ được hiển thị
	// thành dòng 端数調整 — sai lệch nguỵ trang thành làm tròn.
	subtotal := order.Subtotal
	var taxRate, serviceRate float64
	if subtotal > 0 {
		taxableBase := subtotal - money.Discount
		if taxableBase > 0 {
			taxRate = money.Tax * 100 / float64(taxableBase)
			serviceRate = float64(money.ServiceCharge) * 100 / float64(taxableBase)
		}
	}

	// Map item_id → *Item for O(1) lookup.
	itemByID := map[string]*service.Item{}
	for i := range order.Items {
		itemByID[order.Items[i].ID] = &order.Items[i]
	}

	// #2165 — số hoá đơn con là ĐẦU VÀO tường minh, như PHP
	// SplitByItemsCalculator::calculate(..., int $peopleCount, ...). Suy từ
	// max(bill_index)+1 chỉ còn là DỰ PHÒNG cho client cũ chưa gửi
	// `people_count` — cách suy đó nuốt hoá đơn con rỗng ở cuối (người thứ N
	// chưa được gán món biến mất khỏi màn chia bill).
	peopleCount := requestedPeople
	if peopleCount <= 0 {
		for _, a := range allocations {
			if a.BillIndex+1 > peopleCount {
				peopleCount = a.BillIndex + 1
			}
		}
		if peopleCount == 0 {
			peopleCount = 1
		}
	}

	type billAcc struct {
		subtotal int
	}
	bills := make([]billAcc, peopleCount)
	for _, a := range allocations {
		if a.BillIndex < 0 {
			return nil, fmt.Errorf("invalid bill_index %d in allocations", a.BillIndex)
		}
		// Mirror phép lọc của PHP: alloc trỏ ra ngoài people_count bị BỎ QUA
		// im lặng (SplitByItemsCalculator, $billIndex >= $people). Giữ cùng
		// ngữ nghĩa để hai engine ra cùng kết quả trên cùng đầu vào; nếu muốn
		// 422 thì phải đổi CẢ HAI bên cùng lúc.
		if a.BillIndex >= peopleCount {
			continue
		}
		it, ok := itemByID[a.ItemID]
		if !ok {
			return nil, fmt.Errorf("unknown item_id %s in allocations", a.ItemID)
		}
		if it.Status == service.ItemStatusVoided {
			return nil, fmt.Errorf("voided item %s cannot be allocated", a.ItemID)
		}
		if a.Units <= 0 || a.Units > it.Quantity {
			return nil, fmt.Errorf("invalid units %d for item %s (qty=%d)",
				a.Units, a.ItemID, it.Quantity)
		}
		// Per-unit price = unit_price + topping_subtotal.
		//
		// `ToppingSubtotal` is ALREADY per unit (the column comment, the pricer
		// and every writer agree: `subtotal = qty × (unit + topping)`). This
		// used to divide it by the line quantity, spreading one helping of
		// extras across all the units — a ¥1.000 bowl ×3 with a ¥100 extra
		// priced each unit at ¥1.033, so every guest but the last under-paid
		// and the last absorbed the whole ¥200 through the reconcile step
		// below. Cloud and pos-web carried the same division; all three are
		// fixed together and pinned by the topping case in
		// testdata/split_by_items_cases.json.
		perUnit := it.UnitPrice + it.ToppingSubtotal
		bills[a.BillIndex].subtotal += perUnit * a.Units
	}

	// Compute discount / tax / service per bill, then reconcile.
	out := make([]map[string]any, peopleCount)
	totalBill := 0
	lastNonEmpty := -1
	for i := 0; i < peopleCount; i++ {
		// Named `billSubtotal`, not `s`: the receiver is also `s`, and a
		// shadow here would silently send formatting through an int.
		billSubtotal := bills[i].subtotal
		isEmpty := billSubtotal == 0
		var discount, tax, service int
		if !isEmpty && subtotal > 0 {
			// Chia giảm giá theo tỉ lệ — LẤY TỪ SỔ, cùng nguồn với `taxRate`
			// ở trên. Dòng này từng đọc `order.DiscountAmount` (CỘT) trong khi
			// cùng hàm đã chuyển sang sổ: một hàm, hai nguồn.
			//
			// Khi sổ và cột lệch nhau — đúng trạng thái #2041 bước 3 tạo ra —
			// mẫu số của `taxRate` đã trừ giảm giá của SỔ còn số bị chia thì
			// chưa, nên phần dư dồn vào bill cuối và ra THUẾ ÂM ngay trên phiếu
			// khách nhìn thấy (đo được: bill 1 tax -170.00).
			//
			// #2130 — LÀM TRÒN, không CẮT CỤT. `int()` trong Go cắt về 0, nên
			// 600/2100 × 100 = 28,57 ra **28** trong khi PHP
			// (`SplitByItemsCalculator`, engine tham chiếu) ra **29**. Σ giảm
			// giá thành 99 ≠ 100, và bước đối soát cuối hàm dồn phần thiếu ấy
			// vào **thuế** của bill cuối ⇒ khách nhìn thấy dòng `tax = -1`
			// trên một đơn 0% thuế.
			//
			// `math.Round` là half-away-from-zero; ở đây `money.Discount` và
			// `billSubtotal` đều không âm nên nó chính là half-up.
			//
			// CHỈ đổi phép chia giảm giá. `tax`/`service` bên dưới vẫn `int()`
			// — sáu ca còn lại của fixture chung khớp với PHP ở dạng đó, và đổi
			// thêm là tự tạo lệch mới ở chỗ đang đúng.
			discount = int(math.Round(float64(money.Discount) * float64(billSubtotal) / float64(subtotal)))
			taxableBase := billSubtotal - discount
			if taxableBase < 0 {
				taxableBase = 0
			}
			tax = int(float64(taxableBase) * taxRate / 100)
			service = int(float64(taxableBase) * serviceRate / 100)
		}
		total := billSubtotal - discount + tax + service
		if total < 0 {
			total = 0
		}
		out[i] = map[string]any{
			"bill_index": i,
			"subtotal":   formatAmount(billSubtotal),
			"discount":   formatAmount(discount),
			"tax":        formatAmount(tax),
			"service":    formatAmount(service),
			"total":      formatAmount(total),
			"is_empty":   isEmpty,
		}
		totalBill += total
		if !isEmpty {
			lastNonEmpty = i
		}
	}

	// Reconciliation: when full allocation, force sum(bills.total) == order.total.
	if lastNonEmpty >= 0 {
		diff := order.TotalAmount - totalBill
		if diff != 0 {
			row := out[lastNonEmpty]
			oldTax := parseWholeUnits(row["tax"].(string))
			oldTotal := parseWholeUnits(row["total"].(string))
			row["tax"] = formatAmount(oldTax + diff)
			row["total"] = formatAmount(oldTotal + diff)
		}
	}

	return out, nil
}
