package service

import (
	"encoding/json"
	"testing"
)

// Regression: Laravel's `decimal:N` Eloquent cast serialises money /
// quantity fields to quoted strings (`"1000.00"`). Pre-fix, every
// puller that declared the receiving field as plain `float64` silently
// crashed on production payloads — the row decode failed, the puller
// returned an error, pullSlowPos logged a warning, and the replica
// table stayed empty forever. The empty guard then prevented the next
// successful pull from running.
//
// flexFloat is the lenient decoder that accepts both number form
// (legacy / int cast) and quoted-string form (decimal cast). Lock down
// each accepted shape so a future refactor can't quietly regress the
// contract — and shop manager's denomination edits don't vanish into
// the void again.
func TestFlexFloat_DecodesNumberForm(t *testing.T) {
	var v flexFloat
	if err := json.Unmarshal([]byte(`1000`), &v); err != nil {
		t.Fatalf("number form should decode: %v", err)
	}
	if v.Float() != 1000 {
		t.Errorf("want 1000, got %v", v.Float())
	}
}

func TestFlexFloat_DecodesLaravelDecimalString(t *testing.T) {
	// This is the exact format Laravel's decimal:2 cast emits in JSON.
	// Without flexFloat, this string crashed the pull.
	var v flexFloat
	if err := json.Unmarshal([]byte(`"1000.00"`), &v); err != nil {
		t.Fatalf("Laravel decimal string MUST decode: %v", err)
	}
	if v.Float() != 1000 {
		t.Errorf("want 1000, got %v", v.Float())
	}
}

func TestFlexFloat_DecodesFractionalDecimal(t *testing.T) {
	var v flexFloat
	if err := json.Unmarshal([]byte(`"123.45"`), &v); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if v.Float() != 123.45 {
		t.Errorf("want 123.45, got %v", v.Float())
	}
}

func TestFlexFloat_DecodesIntegerString(t *testing.T) {
	// Some Cloud endpoints emit unquoted integers via int casts;
	// others quote them via decimal cast. flexFloat handles both.
	var v flexFloat
	if err := json.Unmarshal([]byte(`"500"`), &v); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if v.Float() != 500 {
		t.Errorf("want 500, got %v", v.Float())
	}
}

func TestFlexFloat_DecodesNull(t *testing.T) {
	var v flexFloat = 999 // pre-fill so we can detect zeroing
	if err := json.Unmarshal([]byte(`null`), &v); err != nil {
		t.Fatalf("null should decode to zero: %v", err)
	}
	if v.Float() != 0 {
		t.Errorf("null should zero out, got %v", v.Float())
	}
}

func TestFlexFloat_DecodesEmptyString(t *testing.T) {
	// Defensive: some upstreams emit "" for unset decimal columns.
	var v flexFloat = 999
	if err := json.Unmarshal([]byte(`""`), &v); err != nil {
		t.Fatalf("empty string should decode to zero: %v", err)
	}
	if v.Float() != 0 {
		t.Errorf("empty string should zero out, got %v", v.Float())
	}
}

func TestFlexFloat_RejectsGarbageString(t *testing.T) {
	// A genuinely malformed value still errors so we don't silently
	// stamp zeros on top of real data corruption.
	var v flexFloat
	if err := json.Unmarshal([]byte(`"not-a-number"`), &v); err == nil {
		t.Errorf("garbage string should error, got nil (v=%v)", v.Float())
	}
}

func TestFlexFloat_RejectsBoolean(t *testing.T) {
	// Catch type drift early — if Cloud accidentally emits true/false
	// instead of a number, we don't want to silently treat it as 1/0.
	var v flexFloat
	if err := json.Unmarshal([]byte(`true`), &v); err == nil {
		t.Errorf("boolean should error, got nil (v=%v)", v.Float())
	}
}

// End-to-end: an entire row struct using flexFloat must decode the
// Laravel-shape payload the production endpoint actually emits.
func TestFlexFloat_DenominationRowDecodes(t *testing.T) {
	payload := `{
		"id": "den-1",
		"currency_code": "JPY",
		"value": "1000.00",
		"kind": "note",
		"label": "1000円札",
		"sort_order": 5,
		"is_active": true
	}`
	var row struct {
		ID           string    `json:"id"`
		CurrencyCode string    `json:"currency_code"`
		Value        flexFloat `json:"value"`
		Kind         string    `json:"kind"`
		Label        string    `json:"label"`
		SortOrder    int       `json:"sort_order"`
		IsActive     bool      `json:"is_active"`
	}
	if err := json.Unmarshal([]byte(payload), &row); err != nil {
		t.Fatalf("production-shape payload MUST decode: %v", err)
	}
	if row.Value.Float() != 1000 {
		t.Errorf("Value want 1000, got %v", row.Value.Float())
	}
	if row.CurrencyCode != "JPY" || row.Kind != "note" || !row.IsActive {
		t.Errorf("row fields wrong: %+v", row)
	}
}
