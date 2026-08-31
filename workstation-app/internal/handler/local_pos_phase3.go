package handler

import (
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"strconv"
	"strings"

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
			out[i] = formatYen(0)
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
			"total_amount":       formatYen(order.TotalAmount),
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
			amounts[i] = formatYen(base + remainder)
			roundingNote = fmt.Sprintf(
				"First person pays extra %s due to rounding",
				formatYen(remainder),
			)
		} else {
			amounts[i] = formatYen(base)
		}
	}

	return map[string]any{
		"total_amount":       formatYen(order.TotalAmount),
		"remaining_amount":   formatYen(remaining),
		"split_count":        splitCount,
		"per_person_amount":  amounts[0],
		"per_person_amounts": amounts,
		"rounding_note":      roundingNote,
	}, http.StatusOK, nil
}

// formatYen renders integer-yen as the decimal-string shape backend
// uses ("1000.00"). Workstation tracks amounts as integer yen so the
// fractional part is always ".00"; clients (pos-web) coerce back via
// Number() when arithmetic is needed.
func formatYen(yen int) string {
	return strconv.Itoa(yen) + ".00"
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
		"total_amount":     formatYen(order.TotalAmount),
		"allocated_amount": formatYen(order.PaidAmount),
		"remaining_amount": formatYen(order.TotalAmount - order.PaidAmount),
		"rounding_mode":    roundingMode,
		"rounding_step":    roundingStep,
		"currency_code":    currencyCode,
		"items":            itemRows,
	}

	// Compute preview_bills only when the caller supplied candidate
	// allocations. Mirrors backend's "no allocations → preview-only" branch.
	if len(allocations) > 0 {
		bills, err := computeByItemsPreviewBills(order, allocations)
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
//  1. Per bill: sum (item.unit_price + item.topping_subtotal/qty) * units.
//  2. Apply proportional share of order.discount_amount.
//  3. Tax + service = round(taxable_base * rate / 100). Rates pulled
//     from order's already-stored amounts (tax_amount/subtotal ratio)
//     so the LAN preview matches whatever Cloud will charge once the
//     payment lands. Avoids needing a separate ShopOrderSetting read.
//  4. Reconcile last non-empty bill so sum(bills.total) == order.total
//     exactly when allocations cover everything.
func computeByItemsPreviewBills(
	order *service.Order,
	allocations []splitByItemsAllocationInput,
) ([]map[string]any, error) {
	// Effective rates derived from the order itself (works without a
	// shop_order_settings row).
	subtotal := order.Subtotal
	var taxRate, serviceRate float64
	if subtotal > 0 {
		taxableBase := subtotal - order.DiscountAmount
		if taxableBase > 0 {
			taxRate = float64(order.TaxAmount) * 100 / float64(taxableBase)
			serviceRate = float64(order.ServiceCharge) * 100 / float64(taxableBase)
		}
	}

	// Map item_id → *Item for O(1) lookup.
	itemByID := map[string]*service.Item{}
	for i := range order.Items {
		itemByID[order.Items[i].ID] = &order.Items[i]
	}

	// Determine the bill count from the allocations.
	peopleCount := 0
	for _, a := range allocations {
		if a.BillIndex+1 > peopleCount {
			peopleCount = a.BillIndex + 1
		}
	}
	if peopleCount == 0 {
		peopleCount = 1
	}

	type billAcc struct {
		subtotal int
	}
	bills := make([]billAcc, peopleCount)
	for _, a := range allocations {
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
		// Per-unit price = unit_price + topping_subtotal/qty
		perUnit := it.UnitPrice
		if it.Quantity > 0 {
			perUnit += it.ToppingSubtotal / it.Quantity
		}
		bills[a.BillIndex].subtotal += perUnit * a.Units
	}

	// Compute discount / tax / service per bill, then reconcile.
	out := make([]map[string]any, peopleCount)
	totalBill := 0
	lastNonEmpty := -1
	for i := 0; i < peopleCount; i++ {
		s := bills[i].subtotal
		isEmpty := s == 0
		var discount, tax, service int
		if !isEmpty && subtotal > 0 {
			// Proportional discount.
			discount = int(float64(order.DiscountAmount) * float64(s) / float64(subtotal))
			taxableBase := s - discount
			if taxableBase < 0 {
				taxableBase = 0
			}
			tax = int(float64(taxableBase) * taxRate / 100)
			service = int(float64(taxableBase) * serviceRate / 100)
		}
		total := s - discount + tax + service
		if total < 0 {
			total = 0
		}
		out[i] = map[string]any{
			"bill_index": i,
			"subtotal":   formatYen(s),
			"discount":   formatYen(discount),
			"tax":        formatYen(tax),
			"service":    formatYen(service),
			"total":      formatYen(total),
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
			oldTax, _ := strconv.Atoi(strings.TrimSuffix(row["tax"].(string), ".00"))
			oldTotal, _ := strconv.Atoi(strings.TrimSuffix(row["total"].(string), ".00"))
			row["tax"] = formatYen(oldTax + diff)
			row["total"] = formatYen(oldTotal + diff)
		}
	}

	return out, nil
}
