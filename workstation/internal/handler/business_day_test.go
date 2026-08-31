package handler

import (
	"testing"
	"time"
)

// #1091 — the shop's day, expressed as a UTC instant range.
//
// Local timestamps in this replica are stored as RFC3339 UTC while the shop PC's
// clock is local, so a date-string comparison was off by the shop's UTC offset —
// nine hours in Tokyo. For those hours the register showed the wrong day's
// orders. These tests pin the conversion that replaced it.

func TestBusinessDayRangeUTC_ShopDayIsNotTheUTCDay(t *testing.T) {
	tokyo := mustLoadZone(t, "Asia/Tokyo")

	startUTC, endUTC, err := businessDayRangeIn(tokyo, "2026-07-26")
	if err != nil {
		t.Fatalf("range: %v", err)
	}

	// Tokyo's 26th is 2026-07-25T15:00Z → 2026-07-26T15:00Z. A naive
	// date(opened_at) = '2026-07-26' would instead have covered
	// 2026-07-26T00:00Z → 23:59Z, i.e. nine hours of the WRONG day at each end.
	if startUTC != "2026-07-25T15:00:00Z" {
		t.Errorf("start = %s, want 2026-07-25T15:00:00Z", startUTC)
	}
	if endUTC != "2026-07-26T15:00:00Z" {
		t.Errorf("end = %s, want 2026-07-26T15:00:00Z", endUTC)
	}
}

func TestBusinessDayRangeUTC_IsHalfOpen(t *testing.T) {
	tokyo := mustLoadZone(t, "Asia/Tokyo")

	_, endOf26, _ := businessDayRangeIn(tokyo, "2026-07-26")
	startOf27, _, _ := businessDayRangeIn(tokyo, "2026-07-27")

	// The 26th ends exactly where the 27th begins, so an order at local midnight
	// belongs to one day only — never both, never neither.
	if endOf26 != startOf27 {
		t.Errorf("boundary mismatch: 26th ends %s, 27th starts %s", endOf26, startOf27)
	}
}

func TestBusinessDayRangeUTC_EmptyDateMeansShopToday(t *testing.T) {
	tokyo := mustLoadZone(t, "Asia/Tokyo")

	start, end, err := businessDayRangeIn(tokyo, "")
	if err != nil {
		t.Fatalf("range: %v", err)
	}

	startAt, _ := time.Parse(time.RFC3339, start)
	endAt, _ := time.Parse(time.RFC3339, end)
	now := time.Now().UTC()

	if !now.After(startAt) || !now.Before(endAt) {
		t.Errorf("now (%s) is outside the shop's today [%s, %s)", now.Format(time.RFC3339), start, end)
	}
	// A shop day is 24h except across a DST transition; Tokyo has none.
	if d := endAt.Sub(startAt); d != 24*time.Hour {
		t.Errorf("shop day length = %s, want 24h for a non-DST zone", d)
	}
}

func TestBusinessDayRangeUTC_SurvivesADSTTransition(t *testing.T) {
	newYork := mustLoadZone(t, "America/New_York")

	// 2026-03-08 is the US spring-forward day: 23 local hours, not 24. Using
	// "+24h" instead of AddDate would have overshot into the next day.
	start, end, err := businessDayRangeIn(newYork, "2026-03-08")
	if err != nil {
		t.Fatalf("range: %v", err)
	}
	startAt, _ := time.Parse(time.RFC3339, start)
	endAt, _ := time.Parse(time.RFC3339, end)

	if d := endAt.Sub(startAt); d != 23*time.Hour {
		t.Errorf("spring-forward day length = %s, want 23h", d)
	}
}

func TestBusinessDayRangeUTC_RejectsAMalformedDate(t *testing.T) {
	tokyo := mustLoadZone(t, "Asia/Tokyo")

	if _, _, err := businessDayRangeIn(tokyo, "26/07/2026"); err == nil {
		t.Error("expected an error for a non-ISO date rather than a silently wrong range")
	}
}

func mustLoadZone(t *testing.T, name string) *time.Location {
	t.Helper()
	loc, err := time.LoadLocation(name)
	if err != nil {
		t.Fatalf("load %s: %v", name, err)
	}

	return loc
}
