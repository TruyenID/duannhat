package handler

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// shapeOrderForResponse builds the customer-order payload AND rewrites
// any cached image URL in it to the local `/api/lan/images/{hash}`
// form. Pre-fix only the menu-catalog handlers ran the rewrite, so
// `GET /pos/orders/{id}` (and the create / addItems / checkout / void
// responses) still emitted raw Cloud URLs on `items[].product_sku.image_url`.
// pos-web's cart line component reads that field for the line thumbnail;
// when the URL pointed at MinIO + the tablet was offline, the image
// loader hit ECONNREFUSED → broken thumbnail → user sees placeholder.
// One centralized helper keeps every order endpoint consistent.
func (s *Server) shapeOrderForResponse(r *http.Request, o *service.Order) map[string]any {
	shape := s.customerOrderShape(o, localeFromRequest(r))
	if shape != nil {
		s.rewriteResponseImages(r, shape)
	}
	return shape
}

// customerOrderShape transforms the workstation `service.Order` into the
// shape Cloud's CustomerOrderResource emits and pos-web's `CustomerOrder`
// TypeScript interface consumes. Without this layer, pos-web crashes on
// missing fields (remaining_amount, items[].product_sku, customer
// summary, tables[], etc.) when reading a LAN-served order.
//
// Decimal-as-string: pos-web's type widens money to `number | string`, so
// we keep workstation's integer yen as-is — the React side coerces via
// `Number()`. The only field that MUST be a string is `remaining_amount`
// because pos-web asserts `string` directly (`order.remaining_amount`).
//
// Eager-load policy: we mirror Cloud's `findById` eager-load set the
// best we can from local SQLite —
//   - items[]                  always (we own them locally)
//   - items[].product_sku      stub from menu_items lookup
//   - items[].toppings         from order_item_toppings
//   - tables[]                 from order_tables join
//   - customer                 minimal summary from customers table
//   - payments[]               from payments table (workstation owns)
//   - coupon_id + snapshot     from order_coupons
//
// Anything Cloud could load but workstation has no local replica for
// (e.g. customer.addresses[]) gets emitted as null/empty so the TS type
// stays satisfied.
func (s *Server) customerOrderShape(o *service.Order, locale string) map[string]any {
	if o == nil {
		return nil
	}

	// Re-localize topping names to `locale` from the catalog. order_item_toppings
	// stores the add-time snapshot (base language), so without this the cart shows
	// toppings in the wrong language even though product / variant / options
	// already follow the operator's selection. Every order-response path funnels
	// through here (shapeOrderForResponse + the phase2/4 callers), so this covers
	// them all. In-memory only — never persisted.
	s.localizeToppingNames(o, locale)

	items := make([]map[string]any, 0, len(o.Items))
	for _, it := range o.Items {
		items = append(items, s.customerOrderItemShape(it, locale))
	}

	tables := s.loadOrderTables(o.ID)
	customer := s.loadOrderCustomer(o.CustomerID)
	payments := s.loadOrderPayments(o.ID, locale)
	coupon := s.loadActiveCoupon(o.ID)

	totalAmount := o.TotalAmount
	paidAmount := o.PaidAmount
	remaining := totalAmount - paidAmount
	if remaining < 0 {
		remaining = 0
	}

	return map[string]any{
		"id":                 o.ID,
		"order_code":         o.OrderCode,
		"order_type":         o.OrderType,
		"status":             o.Status,
		"subtotal":           o.Subtotal,
		"discount_amount":    o.DiscountAmount,
		"service_charge":     o.ServiceCharge,
		"tax_amount":         o.TaxAmount,
		"total_amount":       totalAmount,
		"paid_amount":        paidAmount,
		"total_tip":          o.TotalTip,
		"remaining_amount":   strconv.Itoa(remaining), // pos-web expects string
		"opened_at":          rfc3339OrNil(o.OpenedAt),
		"checkout_at":        rfc3339OrNilPtr(o.CheckoutAt),
		"closed_at":          rfc3339OrNilPtr(o.ClosedAt),
		"voided_at":          rfc3339OrNilPtr(o.VoidedAt),
		"void_reason":        nilIfEmpty(o.VoidReason),
		"payment_due_at":     nil,
		"seconds_until_due":  nil,
		"is_payment_overdue": false,
		"guest_count":        o.GuestCount,
		"is_tax_included":    o.IsTaxIncluded,
		// plan-045 — consumption-tax rounding snapshot + the order_conditions
		// ledger, mirroring Cloud's CustomerOrderResource. pos-web / kiosk read
		// the snapshot to show the rounding rule and `conditions[]` to reconstruct
		// the money story (tax +, discount −, refund −).
		"tax_rounding_mode":     taxRoundingModeOrDefault(o.TaxRoundingMode),
		"tax_rounding_decimals": intPtrOrNil(o.TaxRoundingDecimals),
		"conditions":            s.loadOrderConditions(o.ID),
		// plan-043 — per-rate consumption-tax breakdown (8%対象 / 10%対象),
		// mirroring Cloud's CustomerOrderResource. pos-web reads this to present
		// the cart subtotal + service charge as 税込 (総額表示) so the LAN order
		// matches the menu card; without it pos-web falls back to the net summary
		// while the line rows show gross. Same {rate,taxable,tax} helper the
		// kiosk bill uses.
		"tax_breakdown":            s.kioskTaxBreakdown(o),
		"note":                     nilIfEmpty(o.Note),
		"stock_out_transaction_id": nil,
		"created_by_id":            nilIfEmpty(o.CreatedByID),
		"customer_takeaway_name":   nilIfEmpty(o.CustomerTakeawayName),
		"customer_takeaway_phone":  nilIfEmpty(o.CustomerTakeawayPhone),
		"table_id":                 nilIfEmpty(o.TableID),
		"customer_id":              nilIfEmpty(o.CustomerID),
		"customer":                 customer,
		"coupon_id":                nilIfEmpty(coupon.ID),
		"coupon_code_snapshot":     nilIfEmpty(coupon.Code),
		// Coupon detail for the history view: how much it took off + when, plus
		// the coupon's own terms (name / type / value / cap) so the UI can show
		// what the coupon actually does, not just the code.
		"coupon_discount":         coupon.DiscountApplied,
		"coupon_applied_at":       nilIfEmpty(coupon.AppliedAt),
		"coupon_name":             nilIfEmpty(coupon.Name),
		"coupon_discount_type":    nilIfEmpty(coupon.DiscountType),
		"coupon_discount_value":   coupon.DiscountValue,
		"coupon_max_discount_cap": intPtrOrNil(coupon.MaxCap),
		"branch_id":               o.BranchID,
		"brand_id":                o.BrandID,
		"organization_id":         o.OrganizationID,
		"items":                   items,
		"payments":                payments,
		"tables":                  tables,
		"created_at":              rfc3339OrNil(o.CreatedAt),
		"updated_at":              rfc3339OrNil(o.UpdatedAt),
	}
}

func (s *Server) customerOrderItemShape(it service.Item, locale string) map[string]any {
	// W1: resolve the display name = menu_item_name ?: product.name (joined via
	// product_sku_id). The stored menu_item_name is usually empty for kiosk/pos
	// items, so without this the field serializes as "" and the client renders a
	// blank line. Reuse the same product_sku stub attached below to avoid a
	// second catalog lookup.
	var skuStub map[string]any
	if it.ProductSkuID != "" {
		skuStub = s.loadProductSkuStubLocalized(it.ProductSkuID, locale)
	}
	// Prefer the LOCALIZED catalog product name (from the stub) over the name
	// snapshotted at add time, so the cart/order line follows the operator's
	// currently selected language exactly like the menu does — not whatever
	// language was active when the item was added. The immutable snapshot in
	// order_items.name is untouched (bills/kitchen tickets still use it).
	displayName := strings.TrimSpace(it.MenuItemName)
	if skuStub != nil {
		if localized := strings.TrimSpace(stubProductName(skuStub)); localized != "" {
			displayName = localized
		}
	}
	if displayName == "" && skuStub != nil {
		displayName = skuStubName(skuStub)
	}
	if displayName == "" {
		displayName = strings.TrimSpace(it.SkuVariantName)
	}
	if displayName == "" {
		displayName = stubSkuCode(skuStub) // human SKU code beats "(unknown)"
	}
	if displayName == "" {
		displayName = "(unknown)" // W1 final guard — never serialize a blank name
	}

	out := map[string]any{
		"id":                it.ID,
		"customer_order_id": it.CustomerOrderID,
		"product_sku_id":    it.ProductSkuID,
		"menu_item_name":    displayName,
		"name":              displayName,
		"product_name":      nilIfEmpty(stubProductName(skuStub)),
		"sku_code":          nilIfEmpty(stubSkuCode(skuStub)),
		"quantity":          it.Quantity,
		"unit_price":        it.UnitPrice,
		"topping_subtotal":  it.ToppingSubtotal,
		"subtotal":          it.Subtotal,
		"status":            it.Status,
		// plan-043 — per-line consumption-tax snapshot (mirror of Cloud's
		// CustomerOrderItemResource). tax_type_id/tax_rate are null on an
		// unstamped/legacy line; tax_alcohol_escalated marks a 酒類-escalated line.
		"tax_type_id":           nilIfEmpty(it.TaxTypeID),
		"tax_rate":              floatPtrOrNil(it.TaxRate),
		"tax_amount":            it.TaxAmount,
		"tax_alcohol_escalated": it.TaxAlcoholEscalated,
		// plan-045 — refund fields (mirror of Cloud's CustomerOrderItemResource).
		// refund_of_item_id is null on a normal line; is_refund is the computed
		// discriminator; refunded_quantity is the accumulator on the ORIGINAL line.
		"refund_of_item_id": nilIfEmpty(it.RefundOfItemID),
		"refunded_quantity": it.RefundedQuantity,
		"is_refund":         it.IsRefund(),
		"note":              nilIfEmpty(it.Note),
		"served_at":         rfc3339OrNilPtr(it.ServedAt),
		"voided_at":         rfc3339OrNilPtr(it.VoidedAt),
		"void_reason":       nilIfEmpty(it.VoidReason),
		"created_at":        rfc3339OrNil(it.CreatedAt),
		"updated_at":        rfc3339OrNil(it.UpdatedAt),
	}

	if it.OriginalUnitPrice != nil {
		out["original_unit_price"] = *it.OriginalUnitPrice
	} else {
		out["original_unit_price"] = nil
	}
	if it.PromotionID != "" {
		out["applied_promotion_id"] = it.PromotionID
		out["applied_promotion_snapshot"] = map[string]any{
			"name": it.PromotionLabel,
		}
	} else {
		out["applied_promotion_id"] = nil
		out["applied_promotion_snapshot"] = nil
	}

	if len(it.Toppings) > 0 {
		toppings := make([]map[string]any, 0, len(it.Toppings))
		for _, t := range it.Toppings {
			toppings = append(toppings, map[string]any{
				"id":                    t.ID,
				"order_item_id":         t.OrderItemID,
				"topping_group_item_id": t.ToppingGroupItemID,
				"product_sku_id":        t.ProductSkuID,
				"name":                  t.Name,
				"modifier_type":         t.ModifierType,
				"topping_group_id":      nilIfEmpty(t.ToppingGroupID),
				"topping_group_name":    nilIfEmpty(t.ToppingGroupName),
				"quantity":              t.Quantity,
				"unit_price":            t.UnitPrice,
				"note":                  nilIfEmpty(t.Note),
			})
		}
		out["toppings"] = toppings
	}

	// product_sku stub — pos-web reads `product_sku.product.name` for the
	// cart line label, falling back to `product_sku.name`, then
	// menu_item_name. We synthesise enough from menu_items so a refresh
	// after offline-add doesn't render "—" placeholders. Reuses the stub
	// loaded above for the menu_item_name resolution.
	if skuStub != nil {
		out["product_sku"] = skuStub
	}

	return out
}

// loadProductSkuStub resolves the cart-line stub with the BASE (untranslated)
// product name. Kept for the kiosk path; the pos-web order path calls
// loadProductSkuStubLocalized so the cart line follows the operator's language.
func (s *Server) loadProductSkuStub(skuID string) map[string]any {
	return s.loadProductSkuStubLocalized(skuID, "")
}

// localizeOrderForPrint rewrites each item's product name, SKU variant name and
// topping names into `locale` (the print request's Accept-Language), using the
// same localized catalog lookup the menu/cart use. Printed tickets then follow
// the operator's selected language instead of the name snapshotted at add time
// (which is why JA prints came out as ASCII-folded Vietnamese). Mutates the
// in-memory order only — names are never persisted from here. A blank/unknown
// locale, a missing sku, or a missing translation leaves the snapshot untouched.
func (s *Server) localizeOrderForPrint(o *service.Order, locale string) {
	if o == nil || strings.TrimSpace(locale) == "" {
		return
	}
	// Cache stubs by sku so an order with repeated skus/toppings hits the DB once.
	cache := map[string]map[string]any{}
	stub := func(skuID string) map[string]any {
		if skuID == "" {
			return nil
		}
		if v, ok := cache[skuID]; ok {
			return v
		}
		v := s.loadProductSkuStubLocalized(skuID, locale)
		cache[skuID] = v
		return v
	}
	for i := range o.Items {
		it := &o.Items[i]
		if st := stub(it.ProductSkuID); st != nil {
			if pn := stubProductName(st); pn != "" {
				it.MenuItemName = pn
			}
			if vn, _ := st["name"].(string); vn != "" {
				it.SkuVariantName = vn
			}
		}
		for j := range it.Toppings {
			t := &it.Toppings[j]
			if st := stub(t.ProductSkuID); st != nil {
				if pn := stubProductName(st); pn != "" {
					t.Name = pn
				}
			}
		}
	}
}

// localizeToppingNames rewrites each topping's Name into `locale` from the
// catalog. A topping is itself a product SKU, so its localized name lives in
// pos_product_skus / pos_products (name_ja / name_en / name_vi) — the SAME
// source the menu localizes from. order_item_toppings only stores the base-
// language snapshot captured at add time, so without this the cart/list shows
// toppings in the wrong language while product name + SKU variant + options
// already follow the operator's selection. Mutates the in-memory order only;
// names are never persisted from here. A blank locale, a missing SKU, or a
// missing translation leaves the snapshot untouched (so an orphaned topping SKU
// still renders its stored name). Mirrors the topping half of
// localizeOrderForPrint so the API + printed tickets agree.
func (s *Server) localizeToppingNames(o *service.Order, locale string) {
	if o == nil || strings.TrimSpace(locale) == "" {
		return
	}
	// Cache by SKU so an order with repeated toppings hits the DB once.
	cache := map[string]string{}
	resolve := func(skuID string) string {
		if skuID == "" {
			return ""
		}
		if v, ok := cache[skuID]; ok {
			return v
		}
		v := ""
		if st := s.loadProductSkuStubLocalized(skuID, locale); st != nil {
			v = strings.TrimSpace(stubProductName(st))
		}
		cache[skuID] = v
		return v
	}
	for i := range o.Items {
		for j := range o.Items[i].Toppings {
			t := &o.Items[i].Toppings[j]
			if pn := resolve(t.ProductSkuID); pn != "" {
				t.Name = pn
			}
		}
	}
}

// loadProductSkuStubLocalized is loadProductSkuStub with the product name
// resolved in `locale` (name_ja / name_en / name_vi, falling back to the base
// name) — the same COALESCE the menu uses (productNameExpr). An unknown/empty
// locale resolves to the base name, so kiosk stays byte-identical.
func (s *Server) loadProductSkuStubLocalized(skuID, locale string) map[string]any {
	// PRIMARY source — pos_product_skus + pos_products (plan-022
	// schema). This is where pos-web's menu loaded the SKU from, so
	// the image_url + variant name + product name already round-trip
	// correctly. menu_items is the legacy `/workstation/menu` cache
	// (older sync path) and is missing image columns entirely.
	//
	// Cart-line shape pos-web reads on every order render:
	//
	//   item.product_sku?.image_url       ← cart line thumbnail
	//   item.product_sku?.product?.name   ← row title (product)
	//   item.product_sku?.name            ← variant suffix
	//   item.product_sku?.product?.image_url  ← cart fallback when sku.image_url is null
	//
	// We populate all of them. image_url falls back to
	// pos_products.image_url when the SKU has no per-variant gallery.
	var (
		variantName  sql.NullString
		productID    string
		productName  string
		sellingPrice int
		skuImageURL  sql.NullString
		productImage sql.NullString
		galleryFirst sql.NullString
		skuCodeVal   sql.NullString
	)
	// LEFT JOIN to pos_product_galleries pulls the first gallery row
	// (lowest sort_order, NULLs last — matches loadProductGallery's
	// ordering in local_pos_menus.go). pos-web's MenuCatalog renders
	// `mp.product.gallery[0]` on the tile, so the cart-line thumbnail
	// must read the same source or the customer sees image A on the
	// menu card and image B in the sidebar after add-to-cart.
	// pos_products.image_url is supposed to mirror Cloud's
	// `galleryFirst` URL (migration 021 comment), but Cloud's sync can
	// drift — gallery rows update independently of the canonical
	// image_url field. Reading the gallery row directly removes that
	// drift entirely.
	err := s.db.QueryRow(fmt.Sprintf(`
		SELECT %s, ps.product_id, %s, ps.selling_price,
		       ps.image_url, p.image_url,
		       (SELECT url FROM pos_product_galleries
		          WHERE product_id = p.id
		          ORDER BY (sort_order IS NULL), sort_order, id
		          LIMIT 1),
		       ps.sku
		FROM pos_product_skus ps
		JOIN pos_products p ON p.id = ps.product_id
		WHERE ps.id = ?`, localizedNameExpr("ps", "name", locale), productNameExpr(locale)), skuID).Scan(
		&variantName, &productID, &productName, &sellingPrice,
		&skuImageURL, &productImage, &galleryFirst, &skuCodeVal,
	)
	if err != nil {
		// FALLBACK — legacy menu_items (handy / kiosk path). Still
		// useful for orders created off the legacy sync.
		var legacyName, legacyProductID string
		var legacyPrice float64
		err2 := s.db.QueryRow(`
			SELECT COALESCE(name, ''), COALESCE(sku_id, ''), price
			FROM menu_items WHERE sku_id = ? LIMIT 1`, skuID).Scan(&legacyName, &legacyProductID, &legacyPrice)
		if err2 != nil {
			return nil
		}
		return map[string]any{
			"id":             skuID,
			"product_sku_id": skuID,
			"product_id":     legacyProductID,
			"name":           nilIfEmpty(legacyName),
			"sku_code":       nil, // legacy menu_items has no sku column
			"selling_price":  legacyPrice,
			"is_active":      true,
			"image_url":      nil,
			"product": map[string]any{
				"id":        legacyProductID,
				"name":      legacyName,
				"image_url": nil,
			},
		}
	}

	// Cart-line image source priority — designed so the customer NEVER
	// sees image-A on the menu tile and image-B in the checkout
	// sidebar. pos-web's MenuCatalog renders `mp.product.gallery[0]`
	// from pos_product_galleries; the cart line MUST surface that same
	// row.
	//
	// Why gallery wins over ps.image_url (the old priority):
	// pos_product_skus.image_url snapshots Cloud's `productSku.galleryFirst`
	// — a SEPARATE per-SKU gallery on Cloud that workstation has no
	// table for (pos_product_galleries is product-scoped only,
	// migration 021). So ps.image_url can point at one Cloud asset
	// while pos_product_galleries[0] points at another, producing the
	// "menu shows A, cart shows B" drift the user reported.
	//
	// Final priority:
	//   1. pos_product_galleries[0] — same row catalog renders
	//   2. ps.image_url             — per-SKU snapshot (Cloud's productSku.galleryFirst)
	//   3. p.image_url              — legacy single-URL field
	skuImg := ""
	switch {
	case galleryFirst.Valid && galleryFirst.String != "":
		skuImg = galleryFirst.String
	case skuImageURL.Valid && skuImageURL.String != "":
		skuImg = skuImageURL.String
	case productImage.Valid:
		skuImg = productImage.String
	}
	// product.image_url is the cart's secondary fallback. Pin it to
	// the gallery's first row too — pos-web's order-cart reads it via
	// `item.product_sku?.product?.image_url` when ps.image_url is
	// missing, and the gallery row is the source of truth for the
	// "primary product photo" everywhere else in pos-web.
	prodImg := ""
	switch {
	case galleryFirst.Valid && galleryFirst.String != "":
		prodImg = galleryFirst.String
	case productImage.Valid:
		prodImg = productImage.String
	}

	return map[string]any{
		"id":             skuID,
		"product_sku_id": skuID,
		"product_id":     productID,
		"name":           nullableStringValue(variantName),
		"sku_code":       nullableStringValue(skuCodeVal),
		"selling_price":  sellingPrice,
		"is_active":      true,
		"image_url":      nilIfEmpty(skuImg),
		"product": map[string]any{
			"id":        productID,
			"name":      productName,
			"image_url": nilIfEmpty(prodImg),
		},
	}
}

// loadOrderTables returns the table summaries pos-web renders in
// `order.tables[]`. SQL note: the workstation `tables` replica has no
// `code` column — sync_pull writes Cloud's table code into the local
// `name` column (see internal/service/sync_pull.go:552). Querying
// `t.code` here silently failed and returned an empty array, which
// made every "gán bàn" UX render "Chưa có bàn" even after a
// successful MergeTable/InitOrder. We now read `t.name` once and use
// it for both `code` (what pos-web's TableSummary primary identifier)
// and `name` (the fallback `tbl.name ?? tbl.code` chain in order-cart).
func (s *Server) loadOrderTables(orderID string) []map[string]any {
	rows, err := s.db.Query(`
		SELECT t.id,
		       COALESCE(t.name, '') AS table_label,
		       COALESCE(t.status, 'free') AS status,
		       COALESCE(t.qr_token, '') AS qr_token
		FROM order_tables ot
		JOIN tables t ON t.id = ot.table_id
		WHERE ot.order_id = ?
		ORDER BY ot.sort_order`, orderID)
	if err != nil {
		return []map[string]any{}
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id, label, status, qr string
		if err := rows.Scan(&id, &label, &status, &qr); err != nil {
			continue
		}
		out = append(out, map[string]any{
			"id":       id,
			"code":     label,
			"name":     label,
			"status":   status,
			"qr_token": nilIfEmpty(qr),
		})
	}
	return out
}

func (s *Server) loadOrderCustomer(customerID string) any {
	if customerID == "" {
		return nil
	}
	var first, last, phone sql.NullString
	err := s.db.QueryRow(`
		SELECT first_name, last_name, phone
		FROM customers WHERE id = ? LIMIT 1`, customerID).Scan(&first, &last, &phone)
	if err != nil {
		return nil
	}
	return map[string]any{
		"id":         customerID,
		"first_name": nullableStringValue(first),
		"last_name":  nullableStringValue(last),
		"phone":      nullableStringValue(phone),
	}
}

func (s *Server) loadOrderPayments(orderID, locale string) []map[string]any {
	rows, err := s.db.Query(`
		SELECT id, COALESCE(cloud_id, ''), payment_method,
		       COALESCE(payment_method_id, ''), amount,
		       COALESCE(refunded_amount, 0), status, COALESCE(refunded_at, ''),
		       tendered_amount, change_amount, COALESCE(paid_at, ''), created_at
		FROM payments WHERE order_id = ? ORDER BY created_at`, orderID)
	if err != nil {
		return []map[string]any{}
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id, cloudID, method, methodID, status, refundedAt, paidAt, createdAt string
		var amount, refunded int
		var tendered, change sql.NullInt64
		if err := rows.Scan(&id, &cloudID, &method, &methodID, &amount, &refunded,
			&status, &refundedAt, &tendered, &change, &paidAt, &createdAt); err != nil {
			continue
		}
		row := map[string]any{
			"id":                id,
			"customer_order_id": orderID,
			"payment_method":    method,
			// Human name resolved from payment_methods (by id, then code) with a
			// localized fallback — the same helper the receipt/red-invoice use —
			// so the history's payment list reads "Cash", not the raw code.
			"payment_method_name": s.resolvePaymentMethodName(methodID, method, locale),
			"amount":              amount,
			"refunded_amount":     refunded,
			"status":              status,
			"created_at":          createdAt,
		}
		if methodID != "" {
			row["payment_method_id"] = methodID
		}
		if tendered.Valid {
			row["tendered_amount"] = tendered.Int64
		}
		if change.Valid {
			row["change_amount"] = change.Int64
		}
		if paidAt != "" {
			row["paid_at"] = paidAt
		}
		if cloudID != "" {
			row["cloud_id"] = cloudID
		}
		if refundedAt != "" {
			row["refunded_at"] = refundedAt
		}
		out = append(out, row)
	}
	return out
}

// couponDetail is the applied coupon's story for the history view: what was
// applied (order_coupons: code / discount / time) PLUS the coupon's terms
// (coupons replica: name / type / value / cap), so the UI can show "WELCOME10 ·
// Welcome 10% off · 10% (tối đa ¥50,000)". The coupons LEFT JOIN is best-effort
// — a since-deleted coupon still shows the code + amount from order_coupons.
type couponDetail struct {
	ID, Code, Name, DiscountType   string
	DiscountApplied, DiscountValue int
	MaxCap                         *int
	AppliedAt                      string
}

func (s *Server) loadActiveCoupon(orderID string) couponDetail {
	var cp couponDetail
	var maxCap sql.NullInt64
	_ = s.db.QueryRow(`
		SELECT oc.coupon_id, oc.coupon_code, COALESCE(oc.discount_applied, 0), COALESCE(oc.applied_at, ''),
		       COALESCE(c.name, ''), COALESCE(c.discount_type, ''), COALESCE(c.discount_value, 0), c.max_discount_cap
		FROM order_coupons oc
		LEFT JOIN coupons c ON c.id = oc.coupon_id
		WHERE oc.order_id = ? AND oc.released_at IS NULL
		ORDER BY oc.applied_at DESC LIMIT 1`, orderID).Scan(
		&cp.ID, &cp.Code, &cp.DiscountApplied, &cp.AppliedAt,
		&cp.Name, &cp.DiscountType, &cp.DiscountValue, &maxCap)
	if maxCap.Valid {
		v := int(maxCap.Int64)
		cp.MaxCap = &v
	}
	return cp
}

// loadOrderConditions returns the order_conditions ledger rows attached to the
// order (conditionable_type='order') AND to any of its items
// (conditionable_type='order_item'), mirroring Cloud's CustomerOrderResource
// `conditions[]`. amount is SIGNED (tax +, discount −, refund −); meta is emitted
// as a raw JSON object (null when empty). Ordered by created_at so tax/discount
// (regenerated) precede appended refund events deterministically.
func (s *Server) loadOrderConditions(orderID string) []map[string]any {
	rows, err := s.db.Query(`
		SELECT c.id, c.conditionable_type, c.conditionable_id, c.type,
		       COALESCE(c.source, ''), c.label, c.rate, c.amount,
		       c.currency_code, COALESCE(c.meta, ''), COALESCE(c.created_at, '')
		FROM order_conditions c
		WHERE (c.conditionable_type = 'order' AND c.conditionable_id = ?)
		   OR (c.conditionable_type = 'order_item'
		       AND c.conditionable_id IN (SELECT id FROM order_items WHERE customer_order_id = ?))
		ORDER BY c.created_at, c.id`, orderID, orderID)
	if err != nil {
		return []map[string]any{}
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id, ctype, cid, typ, source, label, currency, meta, createdAt string
		var rate sql.NullFloat64
		var amount float64
		if err := rows.Scan(&id, &ctype, &cid, &typ, &source, &label, &rate, &amount, &currency, &meta, &createdAt); err != nil {
			continue
		}
		row := map[string]any{
			"id":                 id,
			"conditionable_type": ctype,
			"conditionable_id":   cid,
			"type":               typ,
			"source":             nilIfEmpty(source),
			"label":              label,
			"rate":               nullFloatOrNil(rate),
			"amount":             amount,
			"currency_code":      currency,
			"meta":               rawJSONOrNil(meta),
		}
		if createdAt != "" {
			row["created_at"] = createdAt
		}
		out = append(out, row)
	}
	return out
}

// taxRoundingModeOrDefault normalizes the stored mode to one of the rev-B
// directions (round/ceil/floor) for the LAN API shape, accepting the legacy
// aliases (half_up/round_up/round_down) and defaulting a blank to round — so
// pos-web / kds / customer clients always read a canonical value.
func taxRoundingModeOrDefault(mode string) string {
	switch mode {
	case "ceil", "round_up":
		return "ceil"
	case "floor", "round_down":
		return "floor"
	default:
		return "round"
	}
}

// intPtrOrNil serialises a *int as its value or JSON null.
func intPtrOrNil(v *int) any {
	if v == nil {
		return nil
	}
	return *v
}

// nullFloatOrNil serialises a sql.NullFloat64 as its value or JSON null.
func nullFloatOrNil(f sql.NullFloat64) any {
	if !f.Valid {
		return nil
	}
	return f.Float64
}

// rawJSONOrNil decodes a stored JSON string into a value pos-web can read as an
// object; a blank / invalid payload becomes null.
func rawJSONOrNil(s string) any {
	if strings.TrimSpace(s) == "" {
		return nil
	}
	var v any
	if err := json.Unmarshal([]byte(s), &v); err != nil {
		return nil
	}
	return v
}

// ─── time + null helpers ────────────────────────────────────────────────────

func rfc3339OrNil(t time.Time) any {
	if t.IsZero() {
		return nil
	}
	return t.UTC().Format(time.RFC3339)
}

func rfc3339OrNilPtr(t *time.Time) any {
	if t == nil || t.IsZero() {
		return nil
	}
	return t.UTC().Format(time.RFC3339)
}

func nilIfEmpty(s string) any {
	if s == "" {
		return nil
	}
	return s
}

// floatPtrOrNil serialises a *float64 as its value or JSON null (plan-043 —
// tax_rate is nil on an unstamped/legacy line, distinct from an explicit 0%).
func floatPtrOrNil(f *float64) any {
	if f == nil {
		return nil
	}
	return *f
}
