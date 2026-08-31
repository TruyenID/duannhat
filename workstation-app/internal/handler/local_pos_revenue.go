package handler

import (
	"database/sql"
	"fmt"
	"log/slog"
	"net/http"
	"strconv"
	"strings"
	"time"
)

// Local POS revenue summary — aggregates closed orders + completed payments
// from local SQLite. Mirrors the Cloud /api/v1/pos/revenue/summary shape so
// pos-web treats LAN and Cloud interchangeably.
//
// Window contract:
//   - granularity = "day" (default) | "month" | "year"
//   - from / to are inclusive YYYY-MM-DD dates; if omitted, defaults match
//     Cloud (this month for day, last 12 months for month, last 5 years
//     for year).
//   - Hard cap window to 366 days / 36 months / 10 years.

const (
	revenueGranularityDay   = "day"
	revenueGranularityMonth = "month"
	revenueGranularityYear  = "year"
)

type revenueKPIs struct {
	Revenue        int64 `json:"revenue"`
	Orders         int64 `json:"orders"`
	Guests         int64 `json:"guests"`
	AvgPerGuest    int64 `json:"avg_per_guest"`
	CompareRevenue int64 `json:"compare_revenue"`
	DeltaPct       int   `json:"delta_pct"`
}

type revenueSeriesPoint struct {
	Period  string `json:"period"`
	Revenue int64  `json:"revenue"`
	Orders  int64  `json:"orders"`
	Guests  int64  `json:"guests"`
}

type revenueWeekdayPoint struct {
	Weekday      int   `json:"weekday"`
	AvgRevenue   int64 `json:"avg_revenue"`
	TotalRevenue int64 `json:"total_revenue"`
	SampleDays   int   `json:"sample_days"`
}

type revenuePaymentSplit struct {
	MethodID *string `json:"method_id"`
	Code     *string `json:"code"`
	Name     *string `json:"name"`
	Amount   int64   `json:"amount"`
	SharePct float64 `json:"share_pct"`
}

type revenueSummary struct {
	Granularity     string                `json:"granularity"`
	From            string                `json:"from"`
	To              string                `json:"to"`
	KPIs            revenueKPIs           `json:"kpis"`
	Series          []revenueSeriesPoint  `json:"series"`
	ByWeekday       []revenueWeekdayPoint `json:"by_weekday"`
	ByPaymentMethod []revenuePaymentSplit `json:"by_payment_method"`
	GeneratedAt     string                `json:"generated_at"`
	Source          string                `json:"source"`
}

// GET /api/v1/pos/revenue/summary
func (s *Server) handleLocalPosRevenueSummary(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	granularity := strings.ToLower(strings.TrimSpace(q.Get("granularity")))
	switch granularity {
	case revenueGranularityYear, revenueGranularityMonth, revenueGranularityDay:
		// keep
	default:
		granularity = revenueGranularityDay
	}

	from, to, err := parseRevenueWindow(granularity, q.Get("from"), q.Get("to"))
	if err != nil {
		writeError(w, http.StatusUnprocessableEntity, err.Error())
		return
	}

	// Same-length compare window immediately preceding [from, to].
	windowDays := int(to.Sub(from).Hours()/24) + 1
	prevTo := from.Add(-time.Second)
	prevFrom := prevTo.AddDate(0, 0, -(windowDays - 1)).Truncate(24 * time.Hour)

	kpis, err := s.revenueKPIs(r, from, to, prevFrom, prevTo)
	if err != nil {
		slog.Error("pos revenue kpis failed", "err", err)
		writeError(w, http.StatusInternalServerError, "failed to load revenue kpis")
		return
	}

	series, err := s.revenueSeries(r, granularity, from, to)
	if err != nil {
		slog.Error("pos revenue series failed", "err", err)
		writeError(w, http.StatusInternalServerError, "failed to load revenue series")
		return
	}

	var byWeekday []revenueWeekdayPoint
	if granularity == revenueGranularityDay {
		byWeekday, err = s.revenueByWeekday(r, from, to)
		if err != nil {
			slog.Error("pos revenue weekday failed", "err", err)
			writeError(w, http.StatusInternalServerError, "failed to load weekday split")
			return
		}
	} else {
		byWeekday = []revenueWeekdayPoint{}
	}

	byPayment, err := s.revenueByPaymentMethod(r, from, to)
	if err != nil {
		slog.Error("pos revenue payments failed", "err", err)
		writeError(w, http.StatusInternalServerError, "failed to load payment split")
		return
	}

	resp := revenueSummary{
		Granularity:     granularity,
		From:            from.Format("2006-01-02"),
		To:              to.Format("2006-01-02"),
		KPIs:            kpis,
		Series:          series,
		ByWeekday:       byWeekday,
		ByPaymentMethod: byPayment,
		GeneratedAt:     time.Now().UTC().Format(time.RFC3339),
		Source:          "workstation",
	}

	writeJSON(w, http.StatusOK, map[string]any{"data": resp})
}

func parseRevenueWindow(granularity, rawFrom, rawTo string) (time.Time, time.Time, error) {
	loc := time.Local
	now := time.Now().In(loc)

	parseDate := func(s string) (time.Time, error) {
		s = strings.TrimSpace(s)
		if s == "" {
			return time.Time{}, nil
		}
		t, err := time.ParseInLocation("2006-01-02", s, loc)
		if err != nil {
			return time.Time{}, fmt.Errorf("invalid date %q (expected YYYY-MM-DD)", s)
		}
		return t, nil
	}

	fromT, err := parseDate(rawFrom)
	if err != nil {
		return time.Time{}, time.Time{}, err
	}
	toT, err := parseDate(rawTo)
	if err != nil {
		return time.Time{}, time.Time{}, err
	}

	if granularity == revenueGranularityYear {
		// Default: trailing 5 years ending this year.
		if toT.IsZero() {
			toT = time.Date(now.Year(), 12, 31, 23, 59, 59, 0, loc)
		} else {
			toT = time.Date(toT.Year(), 12, 31, 23, 59, 59, 0, loc)
		}
		if fromT.IsZero() {
			fromT = time.Date(toT.Year()-4, 1, 1, 0, 0, 0, 0, loc)
		} else {
			fromT = time.Date(fromT.Year(), 1, 1, 0, 0, 0, 0, loc)
		}
	} else if granularity == revenueGranularityMonth {
		if toT.IsZero() {
			toT = time.Date(now.Year(), now.Month()+1, 0, 23, 59, 59, 0, loc)
		} else {
			toT = time.Date(toT.Year(), toT.Month()+1, 0, 23, 59, 59, 0, loc)
		}
		if fromT.IsZero() {
			fromT = time.Date(toT.Year(), toT.Month()-11, 1, 0, 0, 0, 0, loc)
		} else {
			fromT = time.Date(fromT.Year(), fromT.Month(), 1, 0, 0, 0, 0, loc)
		}
	} else {
		if toT.IsZero() {
			toT = time.Date(now.Year(), now.Month(), now.Day(), 23, 59, 59, 0, loc)
		} else {
			toT = time.Date(toT.Year(), toT.Month(), toT.Day(), 23, 59, 59, 0, loc)
		}
		if fromT.IsZero() {
			fromT = time.Date(now.Year(), now.Month(), 1, 0, 0, 0, 0, loc)
		} else {
			fromT = time.Date(fromT.Year(), fromT.Month(), fromT.Day(), 0, 0, 0, 0, loc)
		}
	}

	if toT.Before(fromT) {
		return time.Time{}, time.Time{}, fmt.Errorf("'to' must be on or after 'from'")
	}

	maxDays := 366
	switch granularity {
	case revenueGranularityMonth:
		maxDays = 36 * 31
	case revenueGranularityYear:
		// Cap year-granularity window at 10 years.
		maxDays = 10 * 366
	}
	if int(toT.Sub(fromT).Hours()/24) > maxDays {
		fromT = toT.AddDate(0, 0, -maxDays)
	}

	return fromT, toT, nil
}

func (s *Server) revenueKPIs(r *http.Request, from, to, prevFrom, prevTo time.Time) (revenueKPIs, error) {
	current, err := s.revenueTotals(r, from, to)
	if err != nil {
		return revenueKPIs{}, err
	}
	previous, err := s.revenueTotals(r, prevFrom, prevTo)
	if err != nil {
		return revenueKPIs{}, err
	}

	var avg int64
	if current.Guests > 0 {
		avg = int64(float64(current.Revenue) / float64(current.Guests))
	}

	return revenueKPIs{
		Revenue:        current.Revenue,
		Orders:         current.Orders,
		Guests:         current.Guests,
		AvgPerGuest:    avg,
		CompareRevenue: previous.Revenue,
		DeltaPct:       deltaPct(float64(current.Revenue), float64(previous.Revenue)),
	}, nil
}

type revenueTotals struct {
	Revenue int64
	Orders  int64
	Guests  int64
}

func (s *Server) revenueTotals(r *http.Request, from, to time.Time) (revenueTotals, error) {
	branchID := s.workstationBranchID()
	args := []any{from.UTC().Format(time.RFC3339), to.UTC().Format(time.RFC3339)}
	whereBranch := ""
	if branchID != "" {
		whereBranch = " AND branch_id = ?"
		args = append(args, branchID)
	}

	row := s.db.QueryRow(`
		SELECT COALESCE(SUM(total_amount), 0) AS revenue,
		       COUNT(*) AS orders,
		       COALESCE(SUM(COALESCE(guest_count, 0)), 0) AS guests
		FROM orders
		WHERE status = 'closed'
		  AND created_at BETWEEN ? AND ?`+whereBranch, args...)

	var t revenueTotals
	if err := row.Scan(&t.Revenue, &t.Orders, &t.Guests); err != nil {
		if err == sql.ErrNoRows {
			return revenueTotals{}, nil
		}
		return revenueTotals{}, err
	}
	return t, nil
}

func (s *Server) revenueSeries(r *http.Request, granularity string, from, to time.Time) ([]revenueSeriesPoint, error) {
	branchID := s.workstationBranchID()
	periodExpr := "strftime('%Y-%m-%d', created_at)"
	switch granularity {
	case revenueGranularityMonth:
		periodExpr = "strftime('%Y-%m', created_at)"
	case revenueGranularityYear:
		periodExpr = "strftime('%Y', created_at)"
	}

	args := []any{from.UTC().Format(time.RFC3339), to.UTC().Format(time.RFC3339)}
	whereBranch := ""
	if branchID != "" {
		whereBranch = " AND branch_id = ?"
		args = append(args, branchID)
	}

	rows, err := s.db.Query(`
		SELECT `+periodExpr+` AS period,
		       COALESCE(SUM(total_amount), 0) AS revenue,
		       COUNT(*) AS orders,
		       COALESCE(SUM(COALESCE(guest_count, 0)), 0) AS guests
		FROM orders
		WHERE status = 'closed'
		  AND created_at BETWEEN ? AND ?`+whereBranch+`
		GROUP BY period
		ORDER BY period`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	bucket := map[string]revenueSeriesPoint{}
	for rows.Next() {
		var p revenueSeriesPoint
		if err := rows.Scan(&p.Period, &p.Revenue, &p.Orders, &p.Guests); err != nil {
			return nil, err
		}
		bucket[p.Period] = p
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	// Backfill empty buckets so the chart shows the full window.
	out := []revenueSeriesPoint{}
	switch granularity {
	case revenueGranularityYear:
		cur := time.Date(from.Year(), 1, 1, 0, 0, 0, 0, from.Location())
		end := time.Date(to.Year(), 1, 1, 0, 0, 0, 0, to.Location())
		for !cur.After(end) {
			key := cur.Format("2006")
			if p, ok := bucket[key]; ok {
				out = append(out, p)
			} else {
				out = append(out, revenueSeriesPoint{Period: key})
			}
			cur = cur.AddDate(1, 0, 0)
		}
	case revenueGranularityMonth:
		cur := time.Date(from.Year(), from.Month(), 1, 0, 0, 0, 0, from.Location())
		end := time.Date(to.Year(), to.Month(), 1, 0, 0, 0, 0, to.Location())
		for !cur.After(end) {
			key := cur.Format("2006-01")
			if p, ok := bucket[key]; ok {
				out = append(out, p)
			} else {
				out = append(out, revenueSeriesPoint{Period: key})
			}
			cur = cur.AddDate(0, 1, 0)
		}
	default:
		cur := time.Date(from.Year(), from.Month(), from.Day(), 0, 0, 0, 0, from.Location())
		end := time.Date(to.Year(), to.Month(), to.Day(), 0, 0, 0, 0, to.Location())
		for !cur.After(end) {
			key := cur.Format("2006-01-02")
			if p, ok := bucket[key]; ok {
				out = append(out, p)
			} else {
				out = append(out, revenueSeriesPoint{Period: key})
			}
			cur = cur.AddDate(0, 0, 1)
		}
	}
	return out, nil
}

func (s *Server) revenueByWeekday(r *http.Request, from, to time.Time) ([]revenueWeekdayPoint, error) {
	branchID := s.workstationBranchID()
	args := []any{from.UTC().Format(time.RFC3339), to.UTC().Format(time.RFC3339)}
	whereBranch := ""
	if branchID != "" {
		whereBranch = " AND branch_id = ?"
		args = append(args, branchID)
	}

	// Monday=0..Sunday=6 (matches Cloud + JS i18n).
	rows, err := s.db.Query(`
		SELECT ((CAST(strftime('%w', created_at) AS INTEGER) + 6) % 7) AS weekday,
		       strftime('%Y-%m-%d', created_at) AS day,
		       COALESCE(SUM(total_amount), 0) AS revenue
		FROM orders
		WHERE status = 'closed'
		  AND created_at BETWEEN ? AND ?`+whereBranch+`
		GROUP BY weekday, day`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	type bucket struct {
		total int64
		days  int
	}
	agg := map[int]*bucket{}
	for rows.Next() {
		var w int
		var day string
		var rev int64
		if err := rows.Scan(&w, &day, &rev); err != nil {
			return nil, err
		}
		b, ok := agg[w]
		if !ok {
			b = &bucket{}
			agg[w] = b
		}
		b.total += rev
		b.days++
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	out := make([]revenueWeekdayPoint, 0, 7)
	for w := 0; w < 7; w++ {
		b := agg[w]
		if b == nil {
			out = append(out, revenueWeekdayPoint{Weekday: w})
			continue
		}
		var avg int64
		if b.days > 0 {
			avg = int64(float64(b.total) / float64(b.days))
		}
		out = append(out, revenueWeekdayPoint{
			Weekday:      w,
			AvgRevenue:   avg,
			TotalRevenue: b.total,
			SampleDays:   b.days,
		})
	}
	return out, nil
}

func (s *Server) revenueByPaymentMethod(r *http.Request, from, to time.Time) ([]revenuePaymentSplit, error) {
	rows, err := s.db.Query(`
		SELECT
		    p.payment_method AS code,
		    COALESCE(pm.name, p.payment_method) AS name,
		    COALESCE(SUM(p.amount - COALESCE(p.refunded_amount, 0)), 0) AS amount
		FROM payments p
		LEFT JOIN payment_methods pm ON pm.code = p.payment_method
		WHERE p.status IN ('paid', 'completed', 'success')
		  AND p.created_at BETWEEN ? AND ?
		GROUP BY p.payment_method, pm.name
		ORDER BY amount DESC`,
		from.UTC().Format(time.RFC3339), to.UTC().Format(time.RFC3339),
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	type tmp struct {
		code   sql.NullString
		name   sql.NullString
		amount int64
	}
	var all []tmp
	var total int64
	for rows.Next() {
		var t tmp
		if err := rows.Scan(&t.code, &t.name, &t.amount); err != nil {
			return nil, err
		}
		all = append(all, t)
		total += t.amount
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	out := make([]revenuePaymentSplit, 0, len(all))
	for _, t := range all {
		var code, name *string
		if t.code.Valid {
			v := t.code.String
			code = &v
		}
		if t.name.Valid {
			v := t.name.String
			name = &v
		}
		share := 0.0
		if total > 0 {
			share = round1(float64(t.amount) * 100.0 / float64(total))
		}
		out = append(out, revenuePaymentSplit{
			MethodID: nil, // workstation tracks method by code only
			Code:     code,
			Name:     name,
			Amount:   t.amount,
			SharePct: share,
		})
	}
	return out, nil
}

func deltaPct(current, previous float64) int {
	if previous == 0 {
		if current > 0 {
			return 100
		}
		return 0
	}
	return int(roundHalfAway((current - previous) / previous * 100))
}

func roundHalfAway(v float64) float64 {
	if v < 0 {
		return -roundHalfAway(-v)
	}
	// Avoid math import — small helper.
	intPart := int64(v)
	if v-float64(intPart) >= 0.5 {
		return float64(intPart + 1)
	}
	return float64(intPart)
}

func round1(v float64) float64 {
	s := strconv.FormatFloat(v, 'f', 1, 64)
	f, _ := strconv.ParseFloat(s, 64)
	return f
}
