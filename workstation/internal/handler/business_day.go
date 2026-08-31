package handler

import (
	"fmt"
	"time"
)

// Business-day helpers for the LOCAL replica (#1091).
//
// THE BUG THIS FIXES. Every timestamp in the local SQLite is stored as RFC3339
// **UTC** (`time.Now().UTC()`), while `time.Now()` here is the shop PC's LOCAL
// wall clock. So comparing a local date string against `date(opened_at)` — which
// SQLite evaluates on the stored UTC text — is off by the shop's UTC offset:
// nine hours in Tokyo, seven in Vietnam. For those hours the register's own
// dashboard and daily report showed the WRONG day's orders.
//
// The fix is not to reformat the date but to compare INSTANTS: convert the
// shop's local day into its UTC half-open range [start, end) and filter on that.
// Half-open, so an order at exactly local midnight belongs to one day only.

// businessDayRangeUTC converts a shop-local calendar date (YYYY-MM-DD) into the
// half-open UTC instant range covering it, formatted the way rows are stored.
//
// An empty `date` means "the shop's today".
func (s *Server) businessDayRangeUTC(date string) (startUTC, endUTC string, err error) {
	return businessDayRangeIn(s.shopLocation(), date)
}

// businessDayRangeIn is the pure core: no DB, no ambient clock beyond `now`,
// so the conversion can be tested against explicit zones instead of whatever
// TZ the host happens to run in.
func businessDayRangeIn(loc *time.Location, date string) (startUTC, endUTC string, err error) {
	if date == "" {
		date = time.Now().In(loc).Format("2006-01-02")
	}

	localStart, err := time.ParseInLocation("2006-01-02", date, loc)
	if err != nil {
		return "", "", fmt.Errorf("business day: %q is not a YYYY-MM-DD date: %w", date, err)
	}

	// AddDate rather than +24h: it stays correct across a DST transition, where
	// a local day can be 23 or 25 hours long.
	localEnd := localStart.AddDate(0, 0, 1)

	return localStart.UTC().Format(time.RFC3339), localEnd.UTC().Format(time.RFC3339), nil
}

// shopToday is the shop's current calendar date — the value to show a cashier
// and to use as the key of a daily counter (whose reset must land on the shop's
// midnight, not UTC's).
func (s *Server) shopToday() string {
	return time.Now().In(s.shopLocation()).Format("2006-01-02")
}
