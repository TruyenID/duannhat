package handler

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log/slog"
	"strings"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// orderShapeBatch holds every relation required to shape one list page. All
// maps are keyed by order/customer ID and are built with a fixed number of SQL
// statements, independent of whether the page contains 1 or 100 orders.
type orderShapeBatch struct {
	tables     map[string][]map[string]any
	customers  map[string]any
	payments   map[string][]map[string]any
	coupons    map[string]couponDetail
	conditions map[string][]map[string]any
}

type paymentMethodCatalog struct {
	byID   map[string]string
	byCode map[string]string
}

func (c paymentMethodCatalog) resolve(id, code, locale string) string {
	if name := strings.TrimSpace(c.byID[id]); name != "" {
		return name
	}
	if name := strings.TrimSpace(c.byCode[code]); name != "" {
		return name
	}
	if name := strings.TrimSpace(c.byCode[strings.ToLower(code)]); name != "" {
		return name
	}
	return paymentMethodCodeLabel(code, locale)
}

func (s *Server) loadOrderShapeBatch(orders []service.Order, locale string) *orderShapeBatch {
	b := &orderShapeBatch{
		tables:     make(map[string][]map[string]any, len(orders)),
		customers:  make(map[string]any),
		payments:   make(map[string][]map[string]any, len(orders)),
		coupons:    make(map[string]couponDetail, len(orders)),
		conditions: make(map[string][]map[string]any, len(orders)),
	}
	if len(orders) == 0 {
		return b
	}

	orderIDs := make([]string, 0, len(orders))
	customerIDs := make([]string, 0, len(orders))
	seenCustomers := map[string]bool{}
	for i := range orders {
		id := orders[i].ID
		orderIDs = append(orderIDs, id)
		b.tables[id] = []map[string]any{}
		b.payments[id] = []map[string]any{}
		b.conditions[id] = []map[string]any{}
		if customerID := orders[i].CustomerID; customerID != "" && !seenCustomers[customerID] {
			seenCustomers[customerID] = true
			customerIDs = append(customerIDs, customerID)
		}
	}
	ph, args := inPlaceholders(orderIDs)

	// Tables: one join for the whole page.
	if rows, err := s.db.Query(`
		SELECT ot.order_id, t.id, COALESCE(t.name, ''),
		       COALESCE(t.status, 'free'), COALESCE(t.qr_token, '')
		FROM order_tables ot
		JOIN tables t ON t.id = ot.table_id
		WHERE ot.order_id IN (`+ph+`)
		ORDER BY ot.order_id, ot.sort_order`, args...); err == nil {
		for rows.Next() {
			var orderID, id, label, status, qr string
			if rows.Scan(&orderID, &id, &label, &status, &qr) == nil {
				b.tables[orderID] = append(b.tables[orderID], map[string]any{
					"id": id, "code": label, "name": label,
					"status": status, "qr_token": nilIfEmpty(qr),
				})
			}
		}
		rows.Close()
	} else {
		slog.Warn("order shape batch tables", "err", err)
	}

	// Customers: dedupe shared customers before querying.
	if len(customerIDs) > 0 {
		customerPH, customerArgs := inPlaceholders(customerIDs)
		if rows, err := s.db.Query(`
			SELECT id, first_name, last_name, phone
			FROM customers WHERE id IN (`+customerPH+`)`, customerArgs...); err == nil {
			for rows.Next() {
				var id string
				var first, last, phone sql.NullString
				if rows.Scan(&id, &first, &last, &phone) == nil {
					b.customers[id] = map[string]any{
						"id": id, "first_name": nullableStringValue(first),
						"last_name": nullableStringValue(last), "phone": nullableStringValue(phone),
					}
				}
			}
			rows.Close()
		} else {
			slog.Warn("order shape batch customers", "err", err)
		}
	}

	catalog := s.loadPaymentMethodCatalog()
	if rows, err := s.db.Query(`
		SELECT p.order_id, p.id, COALESCE(p.cloud_id, ''), p.payment_method,
		       COALESCE(p.payment_method_id, ''), p.amount,
		       `+paymentRefundedSumSQL("p")+`, p.status, COALESCE(p.refunded_at, ''),
		       `+paymentLastRefundAtSQL("p")+`,
		       p.tendered_amount, p.change_amount, COALESCE(p.paid_at, ''), p.created_at
		FROM payments p
		WHERE p.order_id IN (`+ph+`) AND p.`+sqlOnlyOriginalPayments+`
		ORDER BY p.order_id, p.created_at`, args...); err == nil {
		for rows.Next() {
			var orderID, id, cloudID, method, methodID, status, refundedAt, lastRefundAt, paidAt, createdAt string
			var amount, refunded int
			var tendered, change sql.NullInt64
			if rows.Scan(&orderID, &id, &cloudID, &method, &methodID, &amount, &refunded,
				&status, &refundedAt, &lastRefundAt, &tendered, &change, &paidAt, &createdAt) != nil {
				continue
			}
			b.payments[orderID] = append(b.payments[orderID], shapePaymentHistoryRow(
				orderID, id, cloudID, method, methodID, catalog.resolve(methodID, method, locale),
				amount, refunded, status, refundedAt, lastRefundAt, tendered, change, paidAt, createdAt,
			))
		}
		rows.Close()
	} else {
		slog.Warn("order shape batch payments", "err", err)
	}

	cloudEntries := s.loadCloudPaymentSummariesForOrders(orders)
	for i := range orders {
		id := orders[i].ID
		b.payments[id] = mergeCloudPaymentEntriesForHistory(
			id, b.payments[id], cloudEntries[id], locale, catalog.resolve,
		)
	}

	if rows, err := s.db.Query(`
		SELECT oc.order_id, oc.coupon_id, oc.coupon_code,
		       COALESCE(oc.discount_applied, 0), COALESCE(oc.applied_at, ''),
		       COALESCE(c.name, ''), COALESCE(c.discount_type, ''), COALESCE(c.discount_value, 0),
		       c.discount_value_x100, c.max_discount_cap
		FROM order_coupons oc
		LEFT JOIN coupons c ON c.id = oc.coupon_id
		WHERE oc.order_id IN (`+ph+`) AND oc.released_at IS NULL
		ORDER BY oc.order_id, oc.applied_at DESC`, args...); err == nil {
		seen := map[string]bool{}
		for rows.Next() {
			var orderID string
			var cp couponDetail
			var valueX100, maxCap sql.NullInt64
			if rows.Scan(&orderID, &cp.ID, &cp.Code, &cp.DiscountApplied, &cp.AppliedAt,
				&cp.Name, &cp.DiscountType, &cp.DiscountValue, &valueX100, &maxCap) != nil || seen[orderID] {
				continue
			}
			seen[orderID] = true
			if valueX100.Valid {
				v := int(valueX100.Int64)
				cp.DiscountValueX100 = &v
			}
			if maxCap.Valid {
				v := int(maxCap.Int64)
				cp.MaxCap = &v
			}
			b.coupons[orderID] = cp
		}
		rows.Close()
	} else {
		slog.Warn("order shape batch coupons", "err", err)
	}

	if rows, err := s.db.Query(`
		SELECT CASE WHEN c.conditionable_type = 'order'
		            THEN c.conditionable_id ELSE oi.customer_order_id END AS order_id,
		       c.id, c.conditionable_type, c.conditionable_id, c.type,
		       COALESCE(c.source, ''), c.label, c.rate, c.amount, c.taxable_base,
		       c.currency_code, COALESCE(c.meta, ''), COALESCE(c.created_at, '')
		FROM order_conditions c
		LEFT JOIN order_items oi
		  ON c.conditionable_type = 'order_item' AND oi.id = c.conditionable_id
		WHERE (c.conditionable_type = 'order' AND c.conditionable_id IN (`+ph+`))
		   OR (c.conditionable_type = 'order_item' AND oi.customer_order_id IN (`+ph+`))
		ORDER BY order_id, c.created_at, c.id`, append(append([]any{}, args...), args...)...); err == nil {
		for rows.Next() {
			var orderID, id, ctype, cid, typ, source, label, currency, meta, createdAt string
			var rate, taxableBase sql.NullFloat64
			var amount float64
			if rows.Scan(&orderID, &id, &ctype, &cid, &typ, &source, &label, &rate,
				&amount, &taxableBase, &currency, &meta, &createdAt) != nil {
				continue
			}
			row := map[string]any{
				"id": id, "conditionable_type": ctype, "conditionable_id": cid,
				"type": typ, "source": nilIfEmpty(source), "label": label,
				"rate": nullFloatOrNil(rate), "amount": amount,
				"taxable_base":  nullFloatOrNil(taxableBase),
				"currency_code": currency, "meta": rawJSONOrNil(meta),
			}
			if createdAt != "" {
				row["created_at"] = createdAt
			}
			b.conditions[orderID] = append(b.conditions[orderID], row)
		}
		rows.Close()
	} else {
		slog.Warn("order shape batch conditions", "err", err)
	}
	return b
}

func (s *Server) loadPaymentMethodCatalog() paymentMethodCatalog {
	catalog := paymentMethodCatalog{byID: map[string]string{}, byCode: map[string]string{}}
	rows, err := s.db.Query(`SELECT id, code, name FROM payment_methods ORDER BY is_active DESC`)
	if err != nil {
		return catalog
	}
	defer rows.Close()
	for rows.Next() {
		var id, code, name string
		if rows.Scan(&id, &code, &name) != nil {
			continue
		}
		catalog.byID[id] = name
		if _, exists := catalog.byCode[code]; !exists {
			catalog.byCode[code] = name
			catalog.byCode[strings.ToLower(code)] = name
		}
	}
	return catalog
}

func (s *Server) loadCloudPaymentSummariesForOrders(orders []service.Order) map[string][]service.CloudPaymentSummaryEntry {
	out := make(map[string][]service.CloudPaymentSummaryEntry, len(orders))
	keys := make([]string, 0, len(orders)*2)
	seen := map[string]bool{}
	for i := range orders {
		for _, key := range []string{orders[i].ID, orders[i].CloudID} {
			if key != "" && !seen[key] {
				seen[key] = true
				keys = append(keys, key)
			}
		}
	}
	if len(keys) == 0 {
		return out
	}
	ph, args := inPlaceholders(keys)
	type summaryRow struct{ id, cloudID, blob string }
	var summaries []summaryRow
	rows, err := s.db.Query(`SELECT id, COALESCE(cloud_id, ''), COALESCE(cloud_payment_summary, '')
		FROM orders WHERE id IN (`+ph+`) OR cloud_id IN (`+ph+`)`, append(append([]any{}, args...), args...)...)
	if err != nil {
		return out
	}
	for rows.Next() {
		var row summaryRow
		if rows.Scan(&row.id, &row.cloudID, &row.blob) == nil {
			summaries = append(summaries, row)
		}
	}
	rows.Close()
	for i := range orders {
		best := ""
		for _, row := range summaries {
			linked := row.id == orders[i].ID || row.cloudID == orders[i].ID
			if orders[i].CloudID != "" {
				linked = linked || row.id == orders[i].CloudID || row.cloudID == orders[i].CloudID
			}
			if linked && len(row.blob) > len(best) {
				best = row.blob
			}
		}
		if best != "" && best != "[]" {
			var entries []service.CloudPaymentSummaryEntry
			if json.Unmarshal([]byte(best), &entries) == nil {
				out[orders[i].ID] = entries
			}
		}
	}
	return out
}

func shapePaymentHistoryRow(
	orderID, id, cloudID, method, methodID, methodName string,
	amount, refunded int, status, refundedAt, lastRefundAt string,
	tendered, change sql.NullInt64, paidAt, createdAt string,
) map[string]any {
	if amount > 0 && refunded >= amount && (status == "succeeded" || status == "confirmed") {
		status = "refunded"
		if refundedAt == "" {
			refundedAt = lastRefundAt
		}
	}
	row := map[string]any{
		"id": id, "customer_order_id": orderID, "payment_method": method,
		"payment_method_name": methodName, "amount": amount,
		"refunded_amount": refunded, "status": status, "created_at": createdAt,
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
	return row
}

func (s *Server) loadOrderSkuStubs(o *service.Order, locale string) map[string]map[string]any {
	if o == nil || len(o.Items) == 0 {
		return map[string]map[string]any{}
	}
	ids := make([]string, 0, len(o.Items)*2)
	seen := map[string]bool{}
	for i := range o.Items {
		for _, id := range []string{o.Items[i].ProductSkuID} {
			if id != "" && !seen[id] {
				seen[id] = true
				ids = append(ids, id)
			}
		}
		for j := range o.Items[i].Toppings {
			id := o.Items[i].Toppings[j].ProductSkuID
			if id != "" && !seen[id] {
				seen[id] = true
				ids = append(ids, id)
			}
		}
	}
	return s.loadProductSkuStubsLocalized(ids, locale)
}

func (s *Server) loadProductSkuStubsLocalized(ids []string, locale string) map[string]map[string]any {
	out := make(map[string]map[string]any, len(ids))
	if len(ids) == 0 {
		return out
	}
	ph, args := inPlaceholders(ids)
	rows, err := s.db.Query(fmt.Sprintf(`
		SELECT ps.id, %s, ps.product_id, %s, ps.selling_price,
		       ps.image_url, p.image_url,
		       (SELECT url FROM pos_product_galleries
		          WHERE product_id = p.id
		          ORDER BY (sort_order IS NULL), sort_order, id LIMIT 1),
		       ps.sku
		FROM pos_product_skus ps
		JOIN pos_products p ON p.id = ps.product_id
		WHERE ps.id IN (`+ph+`)`, localizedNameExpr("ps", "name", locale), productNameExpr(locale)), args...)
	if err == nil {
		for rows.Next() {
			var id, productID, productName string
			var variantName, skuImage, productImage, galleryFirst, skuCode sql.NullString
			var sellingPrice int
			if rows.Scan(&id, &variantName, &productID, &productName, &sellingPrice,
				&skuImage, &productImage, &galleryFirst, &skuCode) != nil {
				continue
			}
			skuImg := firstNonEmptyNull(galleryFirst, skuImage, productImage)
			prodImg := firstNonEmptyNull(galleryFirst, productImage)
			out[id] = map[string]any{
				"id": id, "product_sku_id": id, "product_id": productID,
				"name": nullableStringValue(variantName), "sku_code": nullableStringValue(skuCode),
				"selling_price": sellingPrice, "is_active": true, "image_url": nilIfEmpty(skuImg),
				"product": map[string]any{"id": productID, "name": productName, "image_url": nilIfEmpty(prodImg)},
			}
		}
		rows.Close()
	}

	missing := make([]string, 0)
	for _, id := range ids {
		if out[id] == nil {
			missing = append(missing, id)
		}
	}
	if len(missing) == 0 {
		return out
	}
	fallbackPH, fallbackArgs := inPlaceholders(missing)
	fallbackRows, err := s.db.Query(`SELECT COALESCE(sku_id, ''), COALESCE(name, ''), price
		FROM menu_items WHERE sku_id IN (`+fallbackPH+`)`, fallbackArgs...)
	if err != nil {
		return out
	}
	defer fallbackRows.Close()
	for fallbackRows.Next() {
		var id, name string
		var price float64
		if fallbackRows.Scan(&id, &name, &price) != nil || out[id] != nil {
			continue
		}
		out[id] = map[string]any{
			"id": id, "product_sku_id": id, "product_id": id,
			"name": nilIfEmpty(name), "sku_code": nil, "selling_price": price,
			"is_active": true, "image_url": nil,
			"product": map[string]any{"id": id, "name": name, "image_url": nil},
		}
	}
	return out
}

func firstNonEmptyNull(values ...sql.NullString) string {
	for _, value := range values {
		if value.Valid && value.String != "" {
			return value.String
		}
	}
	return ""
}
