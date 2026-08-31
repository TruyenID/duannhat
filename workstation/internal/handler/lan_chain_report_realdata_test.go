package handler

import (
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// Replays the operator's ACTUAL chain snapshots (exported from the running
// workstation) through buildChainReport, so any report defect is reproduced
// from production data rather than a fixture that might flatter the code.
func TestBuildChainReport_RealWorldChain(t *testing.T) {
	s := newLANPrintTestServer(t)
	exec := func(q string, args ...any) {
		t.Helper()
		if _, err := s.db.Exec(q, args...); err != nil {
			t.Fatalf("seed: %v", err)
		}
	}
	exec(`INSERT INTO tills (id, branch_id, code) VALUES ('till-1','b1','0001')`)
	const chain = "real-chain"
	add := func(id string, seq int, kind, snap string) {
		exec(`INSERT INTO till_sessions
			(id, session_code, status, business_date, default_currency_code,
			 opening_float_amount, opened_at, closed_at, counted_cash, cash_variance,
			 till_id, branch_id, chain_id, chain_sequence, settlement_kind, settlement_snapshot)
			VALUES (?,?, 'settled','2026-07-22','JPY',0,
			 '2026-07-22T10:40:00Z','2026-07-22T10:46:00Z',0,0,'till-1','b1',?,?,?,?)`,
			id, "WS-"+id, chain, seq, kind, snap)
	}
	add("s1", 1, "handover", `{"cash":{"counted":2955,"expected":2955,"paid_in":0,"paid_out":0,"sales":2455,"variance":0},"cash_events":{"paid_in_count":0,"paid_out_count":0},"counts":{"guest_count":0,"item_count":1},"denominations":[{"quantity":1,"subtotal":2000,"value":2000},{"quantity":1,"subtotal":500,"value":500},{"quantity":4,"subtotal":400,"value":100},{"quantity":1,"subtotal":50,"value":50},{"quantity":1,"subtotal":5,"value":5}],"discounts":[],"opening_float":500,"orders":{"paid_count":1,"paid_total":2455},"payments":[{"amount":2455,"code":"cash","count":1,"label":"cash"}],"revenue":{"discount":0,"gross":2455,"net":2231,"tax":224},"tax_breakdown":[{"rate":10,"tax":213,"taxable":2125}],"tenders":[{"category":"cash","expected_amount":2455,"parent":null,"tender_key":"cash"},{"category":"card","expected_amount":0,"parent":null,"tender_key":"credit"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"rakuten_pay"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"paypay"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"d_barai"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"au_pay"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"merpay"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"ginko_pay"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"wechat_pay"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"alipay"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"unionpay"},{"category":"emoney","expected_amount":null,"parent":null,"tender_key":"id"},{"category":"emoney","expected_amount":null,"parent":null,"tender_key":"ic"},{"category":"emoney","expected_amount":null,"parent":null,"tender_key":"edy"},{"category":"emoney","expected_amount":null,"parent":null,"tender_key":"waon"},{"category":"emoney","expected_amount":null,"parent":null,"tender_key":"nanaco"},{"category":"emoney","expected_amount":null,"parent":null,"tender_key":"quicpay"}],"voids":{"paid_amount":0,"paid_count":0,"unpaid_amount":0,"unpaid_count":0}}`)
	add("s2", 2, "final", `{"cash":{"counted":6862,"expected":6862,"paid_in":0,"paid_out":0,"sales":3907,"variance":0},"cash_events":{"paid_in_count":0,"paid_out_count":0},"counts":{"guest_count":0,"item_count":1},"denominations":[{"quantity":1,"subtotal":5000,"value":5000},{"quantity":1,"subtotal":1000,"value":1000},{"quantity":1,"subtotal":500,"value":500},{"quantity":3,"subtotal":300,"value":100},{"quantity":1,"subtotal":50,"value":50},{"quantity":1,"subtotal":10,"value":10},{"quantity":2,"subtotal":2,"value":1}],"discounts":[],"opening_float":2955,"orders":{"paid_count":1,"paid_total":3907},"payments":[{"amount":3907,"code":"cash","count":1,"label":"cash"}],"revenue":{"discount":0,"gross":3907,"net":3552,"tax":355},"tax_breakdown":[{"rate":10,"tax":338,"taxable":3383}],"tenders":[{"category":"cash","expected_amount":3907,"parent":null,"tender_key":"cash"},{"category":"card","expected_amount":0,"parent":null,"tender_key":"credit"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"rakuten_pay"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"paypay"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"d_barai"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"au_pay"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"merpay"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"ginko_pay"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"wechat_pay"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"alipay"},{"category":"qr","expected_amount":null,"parent":null,"tender_key":"unionpay"},{"category":"emoney","expected_amount":null,"parent":null,"tender_key":"id"},{"category":"emoney","expected_amount":null,"parent":null,"tender_key":"ic"},{"category":"emoney","expected_amount":null,"parent":null,"tender_key":"edy"},{"category":"emoney","expected_amount":null,"parent":null,"tender_key":"waon"},{"category":"emoney","expected_amount":null,"parent":null,"tender_key":"nanaco"},{"category":"emoney","expected_amount":null,"parent":null,"tender_key":"quicpay"}],"voids":{"paid_amount":0,"paid_count":0,"unpaid_amount":0,"unpaid_count":0}}`)

	info, err := s.buildChainReport(chain)
	if err != nil {
		t.Fatalf("buildChainReport: %v", err)
	}
	t.Logf("shifts=%d counted=%d denominations=%d payments=%d",
		info.ShiftCount, info.CountedCash, len(info.Denominations), len(info.Payments))

	if info.ShiftCount != 2 {
		t.Fatalf("ShiftCount = %d, want 2", info.ShiftCount)
	}
	// 金種 must reach the slip — the denomination mix of the FINAL count.
	if len(info.Denominations) == 0 {
		t.Error("no denomination rows despite the snapshots carrying them")
	}
	// The drawer is one physical till: the chain reports the LAST count, not the
	// sum of every shift's count.
	if info.CountedCash != 6862 {
		t.Errorf("CountedCash = %d, want 6862 (the final count)", info.CountedCash)
	}
	// 支払方法 must be the METHODS actually used, not every configured tender
	// type. This shop has 17 tender types; two cash payments were taken.
	if len(info.Payments) > 3 {
		t.Errorf("Payments = %d rows — that is the tender-TYPE list, not the methods used", len(info.Payments))
	}
	for _, p := range info.Payments {
		if p.Count == 0 {
			t.Errorf("payment row %q has no count — the column cannot render", p.Code)
		}
	}

	// Render the ACTUAL slip and prove the 金種 block reaches paper.
	info.ShowDenominations = true
	info.ShowDrawerCheck = true
	info.ShowPaymentMethods = true
	out := service.FormatChainReport(service.PrintJobConfig{PaperWidth: 42}, *info)
	text := string(out)
	// The slip is Shift_JIS encoded, so assert on the ASCII figures rather than
	// the CJK heading: every denomination row must reach paper with its count.
	for _, want := range []string{"5,000", "1,000", "500", "100"} {
		if !containsAny(text, want) {
			t.Errorf("printed chain slip is missing denomination row %q\n%s",
				want, tailLines(text, 24))
		}
	}
}

func tailLines(s string, n int) string {
	lines := []string{}
	cur := ""
	for _, r := range s {
		if r == '\n' {
			lines = append(lines, cur)
			cur = ""
			continue
		}
		cur += string(r)
	}
	if cur != "" {
		lines = append(lines, cur)
	}
	if len(lines) > n {
		lines = lines[len(lines)-n:]
	}
	out := ""
	for _, l := range lines {
		out += l + "\n"
	}
	return out
}

func containsAny(hay, needle string) bool {
	for i := 0; i+len(needle) <= len(hay); i++ {
		if hay[i:i+len(needle)] == needle {
			return true
		}
	}
	return false
}
