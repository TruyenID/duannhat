package service

import (
	"database/sql"

	"github.com/dxs-platform/workstation-app/internal/store"
)

// TillDebtSummary captures the two debt aggregates plan-038 Q9 surfaces
// on the cashier's shift report / Z-report so the manager has both the
// revenue view (counts debt as confirmed) AND the cash view (excludes
// debt from the till's physical drawer expectation).
type TillDebtSummary struct {
	// TotalIssued is the sum of confirmed on_account payments created
	// during the shift window. Counted as revenue but NOT as cash.
	TotalIssued int

	// TotalSettled is the sum of payments created during the shift window
	// whose metadata.settles_payment_id references an on_account
	// payment — i.e. customer paid back a prior debt during this shift.
	// Counted as cash inflow even though the matching revenue was
	// recognised earlier.
	TotalSettled int
}

// ComputeTillDebtSummary aggregates debts for a single till session by
// joining payments → payment_methods on type='on_account'. Caller passes
// the session's openedAt + (optional) closedAt to bound the window.
//
// Plan-038 T10.11. Used by the shift summary slip + cloud Z-report
// extension (cloud computes its own version against its mirror;
// workstation surfaces the local one for the LAN-only path).
func ComputeTillDebtSummary(db *store.DB, openedAt string, closedAt sql.NullString) (TillDebtSummary, error) {
	if db == nil {
		return TillDebtSummary{}, nil
	}

	out := TillDebtSummary{}

	upper := "datetime('now')"
	args := []any{openedAt}
	if closedAt.Valid && closedAt.String != "" {
		upper = "?"
		args = append(args, closedAt.String)
	}

	// Total issued: confirmed on_account payments inside the window.
	if err := db.QueryRow(
		`SELECT COALESCE(SUM(p.amount), 0)
		   FROM payments p
		   LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
		  WHERE pm.type = 'on_account'
		    AND p.status IN ('confirmed','succeeded')
		    AND p.created_at >= ?
		    AND p.created_at <= `+upper, args...,
	).Scan(&out.TotalIssued); err != nil {
		return out, err
	}

	// Total settled: payments inside the window with a
	// metadata.settles_payment_id link.
	if err := db.QueryRow(
		`SELECT COALESCE(SUM(p.amount), 0)
		   FROM payments p
		  WHERE p.status IN ('confirmed','succeeded')
		    AND p.created_at >= ?
		    AND p.created_at <= `+upper+`
		    AND json_extract(COALESCE(p.metadata, '{}'), '$.settles_payment_id') IS NOT NULL`,
		args...,
	).Scan(&out.TotalSettled); err != nil {
		return out, err
	}

	return out, nil
}
