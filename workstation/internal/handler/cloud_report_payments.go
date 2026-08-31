package handler

import (
	"encoding/json"
	"sort"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// cloudReportPayment is a Cloud-only payment projection used by revenue
// reports. It never becomes a payments row: customer-web money did not enter
// the drawer, so materialising it there would corrupt expected_cash.
type cloudReportPayment struct {
	ID       string
	OrderID  string
	FamilyID string
	Code     string
	Label    string
	Amount   int
	PaidAt   string
}

type cloudSummaryOrder struct {
	id, familyID, blob string
}

// cloudReportPayments returns only summary entries which have no matching
// local payments.id / payments.cloud_id in the same logical order family.
// Therefore a workstation payment echoed back by Cloud is never counted twice,
// while Stripe / PayPay customer-web entries (which have no local row) remain.
//
// The orders query is batched and summaries are decoded in memory. Doing one
// cloudPaymentSummaryEntries lookup per order would turn the daily report into
// an N+1 query on exactly the busiest table in the workstation.
func (s *Server) cloudReportPayments(extraWhere string, args ...any) ([]cloudReportPayment, error) {
	query := `
		SELECT id, COALESCE(cloud_id, ''), COALESCE(cloud_payment_summary, '')
		FROM orders
		WHERE COALESCE(cloud_payment_summary, '') NOT IN ('', '[]')`
	if strings.TrimSpace(extraWhere) != "" {
		query += " AND (" + extraWhere + ")"
	}

	rows, err := s.db.Query(query, args...)
	if err != nil {
		return nil, err
	}
	// A sync-up followed by pull-down can leave a local row and a Cloud-keyed
	// sibling. Both represent one order; keep one (prefer the fullest blob).
	byFamily := map[string]cloudSummaryOrder{}
	for rows.Next() {
		var id, cloudID, blob string
		if err := rows.Scan(&id, &cloudID, &blob); err != nil {
			return nil, err
		}
		familyID := id
		if cloudID != "" {
			familyID = cloudID
		}
		cur, exists := byFamily[familyID]
		if !exists {
			cur = cloudSummaryOrder{id: id, familyID: familyID, blob: blob}
		} else {
			if len(blob) > len(cur.blob) {
				cur.blob = blob
			}
			// The Cloud-keyed row carries the pulled items/conditions. Summary
			// selection is independent: a longer blob on its local sibling may
			// still be the newest projection.
			if id == familyID {
				cur.id = id
			}
		}
		byFamily[familyID] = cur
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()
	if len(byFamily) == 0 {
		return []cloudReportPayment{}, nil
	}

	// The dedupe set is family-scoped. Payment UUIDs are globally unique today,
	// but scoping prevents a malformed duplicate id on another order from hiding
	// real revenue.
	seenLocal := map[string]map[string]bool{}
	familyIDs := make([]string, 0, len(byFamily))
	for familyID := range byFamily {
		familyIDs = append(familyIDs, familyID)
	}
	sort.Strings(familyIDs)
	familyMarks, familyArgs := stringPlaceholders(familyIDs)
	localArgs := append(append([]any{}, familyArgs...), familyArgs...)
	localRows, err := s.db.Query(`
		SELECT o.id, COALESCE(o.cloud_id, ''), p.id, COALESCE(p.cloud_id, '')
		FROM payments p
		JOIN orders o ON o.id = p.order_id
		WHERE o.id IN (`+familyMarks+`) OR o.cloud_id IN (`+familyMarks+`)`, localArgs...)
	if err != nil {
		return nil, err
	}
	for localRows.Next() {
		var orderID, cloudOrderID, paymentID, cloudPaymentID string
		if err := localRows.Scan(&orderID, &cloudOrderID, &paymentID, &cloudPaymentID); err != nil {
			localRows.Close()
			return nil, err
		}
		familyID := orderID
		if cloudOrderID != "" {
			familyID = cloudOrderID
		}
		if seenLocal[familyID] == nil {
			seenLocal[familyID] = map[string]bool{}
		}
		seenLocal[familyID][paymentID] = true
		if cloudPaymentID != "" {
			seenLocal[familyID][cloudPaymentID] = true
		}
	}
	if err := localRows.Err(); err != nil {
		localRows.Close()
		return nil, err
	}
	localRows.Close()

	// Load the local catalog once, not once per payment. The Cloud-provided name
	// remains the fallback for gateway-only methods not yet in the replica.
	labelsByID := map[string]string{}
	labelsByCode := map[string]string{}
	methodRows, err := s.db.Query(`SELECT id, code, COALESCE(name, '') FROM payment_methods`)
	if err == nil {
		for methodRows.Next() {
			var id, code, name string
			if methodRows.Scan(&id, &code, &name) == nil && strings.TrimSpace(name) != "" {
				labelsByID[id] = strings.TrimSpace(name)
				labelsByCode[strings.ToLower(strings.TrimSpace(code))] = strings.TrimSpace(name)
			}
		}
		methodRows.Close()
	}

	orders := make([]cloudSummaryOrder, 0, len(byFamily))
	for _, order := range byFamily {
		orders = append(orders, order)
	}
	sort.Slice(orders, func(i, j int) bool { return orders[i].familyID < orders[j].familyID })

	out := make([]cloudReportPayment, 0)
	locale := s.printLabelLocale()
	for _, order := range orders {
		var entries []service.CloudPaymentSummaryEntry
		if err := json.Unmarshal([]byte(order.blob), &entries); err != nil {
			// A damaged summary must not erase the other orders in a whole report.
			continue
		}
		seenCloudIDs := map[string]bool{}
		for _, entry := range entries {
			parts := service.PaymentReportParts(entry)
			if len(parts) == 0 {
				continue
			}
			code := strings.ToLower(strings.TrimSpace(entry.PaymentMethodCode))
			label := labelsByID[entry.PaymentMethodID]
			if label == "" {
				label = labelsByCode[code]
			}
			if label == "" {
				label = strings.TrimSpace(entry.PaymentMethodName)
			}
			if label == "" {
				label = paymentMethodCodeLabel(code, locale)
			}
			if code == "" && label == "" {
				continue
			}
			for _, part := range parts {
				if seenCloudIDs[part.ID] || seenLocal[order.familyID][part.ID] {
					continue
				}
				seenCloudIDs[part.ID] = true
				out = append(out, cloudReportPayment{
					ID:       part.ID,
					OrderID:  order.id,
					FamilyID: order.familyID,
					Code:     code,
					Label:    label,
					Amount:   part.Amount,
					PaidAt:   part.PaidAt,
				})
			}
		}
	}
	return out, nil
}

func (s *Server) cloudReportPaymentsForSession(sessionID string) ([]cloudReportPayment, error) {
	return s.cloudReportPayments(`cloud_till_session_id = ?`, sessionID)
}

func (s *Server) cloudReportPaymentsInWindow(from, to time.Time) ([]cloudReportPayment, error) {
	where := ""
	args := []any{}
	if branchID := s.workstationBranchID(); branchID != "" {
		where = "branch_id = ?"
		args = append(args, branchID)
	}
	payments, err := s.cloudReportPayments(where, args...)
	if err != nil {
		return nil, err
	}
	from = from.UTC()
	to = to.UTC()
	out := payments[:0]
	for _, payment := range payments {
		paidAt := parseTime(payment.PaidAt)
		if paidAt.IsZero() || paidAt.Before(from) || paidAt.After(to) {
			continue
		}
		out = append(out, payment)
	}
	return out, nil
}

// shiftReportScope returns the order representatives used by every sales
// section plus the Cloud-only payment-method entries. Local payments retain
// attribution-first semantics; Cloud-only orders use the order attribution
// already assigned by Cloud. One representative per family prevents a
// sync-up/pull-down sibling pair from duplicating sales, tax or item counts.
func (s *Server) shiftReportScope(sessionID, lower, upper string) ([]string, []cloudReportPayment, error) {
	localRows, err := s.db.Query(`
		SELECT DISTINCT order_id FROM payments WHERE `+paidPaymentsPredicate(""),
		sessionID, lower, upper,
	)
	if err != nil {
		return nil, nil, err
	}
	localOrderIDs := []string{}
	for localRows.Next() {
		var id string
		if err := localRows.Scan(&id); err != nil {
			localRows.Close()
			return nil, nil, err
		}
		localOrderIDs = append(localOrderIDs, id)
	}
	if err := localRows.Err(); err != nil {
		localRows.Close()
		return nil, nil, err
	}
	localRows.Close()

	cloudPayments, err := s.cloudReportPaymentsForSession(sessionID)
	if err != nil {
		return nil, nil, err
	}

	// Resolve all order family ids in one query. A local paid row wins as the
	// representative because its order_items/conditions are the records the
	// workstation created; otherwise the pulled Cloud row represents the family.
	familyByID := map[string]string{}
	candidateIDs := append([]string{}, localOrderIDs...)
	for _, payment := range cloudPayments {
		candidateIDs = append(candidateIDs, payment.OrderID)
	}
	marks, candidateArgs := stringPlaceholders(candidateIDs)
	if marks == "" {
		return []string{}, cloudPayments, nil
	}
	rows, err := s.db.Query(`SELECT id, COALESCE(cloud_id, '') FROM orders WHERE id IN (`+marks+`)`, candidateArgs...)
	if err != nil {
		return nil, nil, err
	}
	for rows.Next() {
		var id, cloudID string
		if err := rows.Scan(&id, &cloudID); err != nil {
			rows.Close()
			return nil, nil, err
		}
		familyByID[id] = id
		if cloudID != "" {
			familyByID[id] = cloudID
		}
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, nil, err
	}
	rows.Close()

	byFamily := map[string]string{}
	for _, id := range localOrderIDs {
		familyID := familyByID[id]
		if familyID == "" {
			familyID = id
		}
		if _, exists := byFamily[familyID]; !exists {
			byFamily[familyID] = id
		}
	}
	for _, payment := range cloudPayments {
		if _, exists := byFamily[payment.FamilyID]; !exists {
			byFamily[payment.FamilyID] = payment.OrderID
		}
	}

	orderIDs := make([]string, 0, len(byFamily))
	for _, id := range byFamily {
		orderIDs = append(orderIDs, id)
	}
	sort.Strings(orderIDs)
	return orderIDs, cloudPayments, nil
}

func stringPlaceholders(values []string) (string, []any) {
	if len(values) == 0 {
		return "", nil
	}
	marks := make([]string, len(values))
	args := make([]any, len(values))
	for i, value := range values {
		marks[i] = "?"
		args[i] = value
	}
	return strings.Join(marks, ","), args
}

func (s *Server) shiftPaymentLines(sessionID, lower, upper string, cloud []cloudReportPayment) ([]service.ShiftPaymentLine, error) {
	byCode := map[string]*service.ShiftPaymentLine{}
	rows, err := s.db.Query(`
		SELECT p.payment_method AS code,
		       COALESCE(NULLIF(pm.name, ''), p.payment_method) AS label,
		       COUNT(*) AS cnt,
		       COALESCE(SUM(p.amount - COALESCE(p.refunded_amount, 0)), 0) AS amt
		FROM payments p
		LEFT JOIN payment_methods pm ON pm.code = p.payment_method
		WHERE `+paidPaymentsPredicate("p")+`
		GROUP BY code, label`, sessionID, lower, upper)
	if err != nil {
		return nil, err
	}
	for rows.Next() {
		var line service.ShiftPaymentLine
		if err := rows.Scan(&line.Code, &line.Label, &line.Count, &line.Amount); err != nil {
			rows.Close()
			return nil, err
		}
		code := strings.ToLower(strings.TrimSpace(line.Code))
		line.Code = code
		copy := line
		byCode[code] = &copy
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()

	for _, payment := range cloud {
		key := payment.Code
		if key == "" {
			key = "cloud:" + payment.Label
		}
		line := byCode[key]
		if line == nil {
			line = &service.ShiftPaymentLine{Code: payment.Code, Label: payment.Label}
			byCode[key] = line
		}
		line.Count++
		line.Amount += payment.Amount
	}

	out := make([]service.ShiftPaymentLine, 0, len(byCode))
	for _, line := range byCode {
		out = append(out, *line)
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].Amount != out[j].Amount {
			return out[i].Amount > out[j].Amount
		}
		return out[i].Code+"\x00"+out[i].Label < out[j].Code+"\x00"+out[j].Label
	})
	return out, nil
}
