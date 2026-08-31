package printjob

import "strings"

// ScopeCount is one scope's tally of a single document kind (#1875).
//
// `Count` is the highest copy number ISSUED, not the number of rows: a failed
// print still burnt its number (P-12), so the next sheet is Count+1 and that is
// precisely what a "this will print as BAN IN #N" warning must say. Counting
// rows instead would promise a number the reservation will not hand out.
type ScopeCount struct {
	// PaymentID is "" for the whole-order scope.
	PaymentID     string
	Count         int
	LastPrintedAt string
	LastStatus    Status
}

// CountsForOrder reports how many copies of one kind have been issued on an
// order family, split by scope: the order-scope tally plus one per payer.
//
// The order FAMILY, not one id — a merged table carries several linked order
// rows and asking about only the one the caller named would under-report.
//
// Reads local SQLite and nothing else, so it answers correctly with the internet
// down. That is the whole reason the flag is derived here rather than stored in
// Cloud: a POS that cannot reach Cloud must still be able to tell a cashier this
// order already has a red invoice on paper.
func (j *Journal) CountsForOrder(kind Kind, orderIDs []string) (order ScopeCount, byPayment []ScopeCount, err error) {
	ids := make([]any, 0, len(orderIDs))
	for _, id := range orderIDs {
		if id = strings.TrimSpace(id); id != "" {
			ids = append(ids, id)
		}
	}
	if len(ids) == 0 || kind == "" {
		return ScopeCount{}, nil, nil
	}

	args := append([]any{string(kind)}, ids...)
	rows, err := j.db.Query(`
		SELECT COALESCE(payment_id, ''), reprint_no, printed_at, status
		FROM print_jobs
		WHERE kind = ? AND order_id IN (`+
		strings.TrimSuffix(strings.Repeat("?,", len(ids)), ",")+`)
		ORDER BY printed_at ASC, id ASC`, args...)
	if err != nil {
		return ScopeCount{}, nil, err
	}
	defer rows.Close()

	// Aggregated in Go rather than SQL on purpose: one order carries a handful of
	// rows, and "highest number, plus the status of the LATEST attempt" is two
	// different aggregates over the same set — awkward in one portable query,
	// trivial here, and readable by whoever has to change it next.
	seen := map[string]*ScopeCount{}
	orderList := []string{}
	for rows.Next() {
		var (
			paymentID, printedAt, status string
			reprintNo                    int
		)
		if err := rows.Scan(&paymentID, &reprintNo, &printedAt, &status); err != nil {
			return ScopeCount{}, nil, err
		}
		sc, ok := seen[paymentID]
		if !ok {
			sc = &ScopeCount{PaymentID: paymentID}
			seen[paymentID] = sc
			orderList = append(orderList, paymentID)
		}
		if reprintNo > sc.Count {
			sc.Count = reprintNo
		}
		// Rows arrive oldest-first, so the last one wins for "latest attempt".
		sc.LastPrintedAt = printedAt
		sc.LastStatus = Status(status)
	}
	if err := rows.Err(); err != nil {
		return ScopeCount{}, nil, err
	}

	for _, key := range orderList {
		if key == "" {
			order = *seen[key]
			continue
		}
		byPayment = append(byPayment, *seen[key])
	}
	return order, byPayment, nil
}
