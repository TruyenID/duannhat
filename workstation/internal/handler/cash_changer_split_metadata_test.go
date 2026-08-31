package handler

import (
	"encoding/json"
	"net/http"
	"testing"
)

func intPointer(value int) *int { return &value }

func TestCanonicalCashChangerSplitMetadata_AcceptsOnlyCanonicalModes(t *testing.T) {
	tests := []struct {
		name   string
		amount int
		raw    string
	}{
		{
			name:   "even",
			amount: 1000,
			raw:    `{"split_mode":"even","bill_index":0,"total_bills":4}`,
		},
		{
			name:   "by_items",
			amount: 1600,
			raw: `{"split_mode":"by_items","bill_index":2,"total_bills":3,` +
				`"label":"Guest 3","item_allocations":[{"item_id":"line-9","units":2}]}`,
		},
		{
			name:   "by_amount",
			amount: 1250,
			raw: `{"split_mode":"by_amount","bill_index":1,"total_bills":2,` +
				`"label":"Guest 2","amount":1250}`,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got, err := canonicalCashChangerSplitMetadata(json.RawMessage(tt.raw), intPointer(tt.amount))
			if err != nil {
				t.Fatalf("canonicalCashChangerSplitMetadata: %v", err)
			}

			var wantValue, gotValue map[string]any
			if err := json.Unmarshal([]byte(tt.raw), &wantValue); err != nil {
				t.Fatal(err)
			}
			if err := json.Unmarshal([]byte(got), &gotValue); err != nil {
				t.Fatalf("canonical output is not JSON: %v — %q", err, got)
			}
			if !mapsEqualJSON(wantValue, gotValue) {
				t.Fatalf("canonical metadata = %s, want semantic value %s", got, tt.raw)
			}
		})
	}
}

func mapsEqualJSON(left, right map[string]any) bool {
	leftJSON, _ := json.Marshal(left)
	rightJSON, _ := json.Marshal(right)
	return string(leftJSON) == string(rightJSON)
}

func TestCanonicalCashChangerSplitMetadata_RejectsMalformedContextBeforeCashMoves(t *testing.T) {
	amount := 1000
	tests := []struct {
		name   string
		raw    string
		amount *int
	}{
		{name: "old equal vocabulary", raw: `{"split_mode":"equal","bill_index":0,"total_bills":2}`, amount: &amount},
		{name: "old people vocabulary", raw: `{"split_mode":"by_people","bill_index":0,"total_bills":2}`, amount: &amount},
		{name: "metadata without split amount", raw: `{"split_mode":"even","bill_index":0,"total_bills":2}`},
		{name: "negative index", raw: `{"split_mode":"even","bill_index":-1,"total_bills":2}`, amount: &amount},
		{name: "missing total", raw: `{"split_mode":"even","bill_index":0}`, amount: &amount},
		{name: "amount mismatch", raw: `{"split_mode":"by_amount","bill_index":0,"total_bills":2,"label":"Guest 1","amount":999}`, amount: &amount},
		{name: "client forges provenance", raw: `{"split_mode":"even","bill_index":0,"total_bills":2,"server_id":"forged"}`, amount: &amount},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got, err := canonicalCashChangerSplitMetadata(json.RawMessage(tt.raw), tt.amount); err == nil {
				t.Fatalf("accepted invalid metadata %s as %q", tt.raw, got)
			}
		})
	}
}

func TestCanonicalCashChangerSplitMetadata_RejectsSplitAmountWithoutContext(t *testing.T) {
	amount := 1000
	if got, err := canonicalCashChangerSplitMetadata(nil, &amount); err == nil {
		t.Fatalf("accepted split amount without audit context as %q", got)
	}
}

func TestCashChangerCollect_PersistsSplitContextBeforeStartingMachine(t *testing.T) {
	s := splitBillFixture(t)
	s.cashChanger.SetSessionStore(s)

	rr := collectWithBody(t, s, `{"order_id":"o1","amount":1000,"metadata":{`+
		`"split_mode":"even","bill_index":0,"total_bills":4}}`)
	if rr.Code != http.StatusAccepted {
		t.Fatalf("code = %d, want 202 — body %s", rr.Code, rr.Body.String())
	}

	var stored string
	if err := s.db.QueryRow(
		`SELECT payment_metadata FROM cash_changer_sessions ORDER BY started_at DESC LIMIT 1`,
	).Scan(&stored); err != nil {
		t.Fatalf("read durable cash session: %v", err)
	}
	if stored != `{"split_mode":"even","bill_index":0,"total_bills":4}` {
		t.Fatalf("stored metadata = %q, want canonical even split context", stored)
	}
}

func TestCashChangerCollect_RejectsBadSplitContextBeforeStartingMachine(t *testing.T) {
	tests := []struct {
		name string
		body string
	}{
		{name: "old vocabulary", body: `{"order_id":"o1","amount":1000,"metadata":{` +
			`"split_mode":"equal","bill_index":0,"total_bills":4}}`},
		{name: "missing context", body: `{"order_id":"o1","amount":1000}`},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			s := splitBillFixture(t)
			s.cashChanger.SetSessionStore(s)

			rr := collectWithBody(t, s, tt.body)
			if rr.Code != http.StatusUnprocessableEntity {
				t.Fatalf("code = %d, want 422 — body %s", rr.Code, rr.Body.String())
			}

			var sessions int
			if err := s.db.QueryRow(`SELECT COUNT(*) FROM cash_changer_sessions`).Scan(&sessions); err != nil {
				t.Fatal(err)
			}
			if sessions != 0 {
				t.Fatalf("bad metadata created %d machine sessions; validation ran after cash started", sessions)
			}
		})
	}
}

func TestCashChangerSessionStore_RoundTripsSplitAndNonSplitContext(t *testing.T) {
	s := newRecorderServer(t)
	splitMetadata := `{"split_mode":"even","bill_index":0,"total_bills":2}`
	if err := s.BeginSession("session-split", "order-split", 1000, splitMetadata); err != nil {
		t.Fatalf("BeginSession split: %v", err)
	}
	if err := s.BeginSession("session-normal", "order-normal", 4000, ""); err != nil {
		t.Fatalf("BeginSession normal: %v", err)
	}

	rows, err := s.UnresolvedSessions()
	if err != nil {
		t.Fatalf("UnresolvedSessions: %v", err)
	}
	if len(rows) != 2 {
		t.Fatalf("unresolved sessions = %+v, want two rows", rows)
	}
	byID := map[string]string{}
	for _, row := range rows {
		byID[row.SessionID] = row.PaymentMetadata
	}
	if byID["session-split"] != splitMetadata || byID["session-normal"] != "" {
		t.Fatalf("round-tripped metadata = %#v, want exact split context and empty normal context", byID)
	}
}

// #2942 — `DisallowUnknownFields` là thứ CHẶN THẬT đường giả mạo provenance từ
// POS, không phải thứ tự gán trong recorder (xem
// TestRecordCashPayment_ForgedProvenanceNeverBeatsTheMachine). Gỡ nó ra để
// "linh hoạt hơn" sẽ mở lại đúng đường đó, nên ghim bằng một bài riêng.
func TestCanonicalCashChangerSplitMetadata_RejectsProvenanceFieldsFromClient(t *testing.T) {
	amount := 500

	for _, field := range []string{"capture_source", "glory_transaction_id", "server_id"} {
		raw := []byte(`{"split_mode":"even","bill_index":0,"total_bills":2,"` + field + `":"x"}`)
		if _, err := canonicalCashChangerSplitMetadata(raw, &amount); err == nil {
			t.Fatalf("POS gửi %q mà vẫn được nhận — provenance phải do máy trạm sở hữu", field)
		}
	}
}
