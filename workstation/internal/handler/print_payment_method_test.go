package handler

import (
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// The receipt + red invoice must state how the order was paid. The order's own
// payment_method column is deprecated/empty, so the method is resolved from the
// payments table (by payment_method_id, then code) → the same name the cashier
// saw at pay time.
func TestPaymentMethodDisplay_ResolvesFromPaymentsTable(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")

	mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES ('pm-cash','cash','Cash'),('pm-card','card','Card')`)
	mustExec(t, db, `INSERT INTO orders (id, order_number, status) VALUES ('o-pm', 1, 'closed')`)

	// Paid ¥2000 by card, carrying a payment_method_id (the modern shape).
	mustExec(t, db, `INSERT INTO payments (id, order_id, payment_method, payment_method_id, amount, status, idempotency_key, created_at)
		VALUES ('pay-1','o-pm','card','pm-card',2000,'confirmed','k1','2026-07-19T10:00:00Z')`)

	if got := srv.paymentMethodDisplay("o-pm", 2000, "en"); got != "Card" {
		t.Errorf("exact-amount match: want Card, got %q", got)
	}
	if got := srv.paymentMethodDisplay("o-pm", 0, "en"); got != "Card" {
		t.Errorf("whole-order: want Card, got %q", got)
	}
}

// A legacy payment with only the code (no payment_method_id) still resolves via
// the code → payment_methods.code join.
func TestPaymentMethodDisplay_ResolvesByCode(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")
	mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES ('pm-cash','cash','Cash')`)
	mustExec(t, db, `INSERT INTO orders (id, order_number, status) VALUES ('o-c', 1, 'closed')`)
	mustExec(t, db, `INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, created_at)
		VALUES ('pay-c','o-c','cash',1500,'confirmed','kc','2026-07-19T10:00:00Z')`)

	if got := srv.paymentMethodDisplay("o-c", 1500, "en"); got != "Cash" {
		t.Errorf("by-code: want Cash, got %q", got)
	}
}

// When no payment_methods row exists (offline / legacy), a well-known code
// falls back to a localized label so the bill still names the method.
func TestPaymentMethodDisplay_LocalizedFallback(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")
	mustExec(t, db, `INSERT INTO orders (id, order_number, status) VALUES ('o-f', 1, 'closed')`)
	mustExec(t, db, `INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, created_at)
		VALUES ('pay-f','o-f','cash',1000,'confirmed','kf','2026-07-19T10:00:00Z')`)

	cases := map[string]string{"ja": "現金", "en": "Cash", "vi": "Tien mat"}
	for locale, want := range cases {
		if got := srv.paymentMethodDisplay("o-f", 1000, locale); got != want {
			t.Errorf("[%s] fallback: want %q, got %q", locale, want, got)
		}
	}
}

// A split-paid order (two methods) lists every distinct method on the whole-
// order (red invoice) view.
func TestPaymentMethodDisplay_SplitLikeMultipleMethods(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")
	mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES ('pm-cash','cash','Cash'),('pm-card','card','Card')`)
	mustExec(t, db, `INSERT INTO orders (id, order_number, status) VALUES ('o-s', 1, 'closed')`)
	mustExec(t, db, `INSERT INTO payments (id, order_id, payment_method, payment_method_id, amount, status, idempotency_key, created_at) VALUES
		('s1','o-s','cash','pm-cash',1000,'confirmed','ks1','2026-07-19T10:00:00Z'),
		('s2','o-s','card','pm-card',1000,'confirmed','ks2','2026-07-19T10:05:00Z')`)

	got := srv.paymentMethodDisplay("o-s", 0, "en") // whole order → both
	if !strings.Contains(got, "Cash") || !strings.Contains(got, "Card") {
		t.Errorf("split: want both Cash and Card, got %q", got)
	}
}

// #1282 — an order paid ONLINE (customer-web / Stripe / PayPay) is confirmed in
// Cloud, so the workstation holds NO local payments row: it only learns the
// order closed on the next pull-DOWN and auto-prints from there. The method must
// still be named, from the summary Cloud mirrors onto the order header — and
// through the LOCAL payment_methods replica, so the slip keeps the print locale.
func TestPaymentMethodDisplay_FallsBackToCloudSummary(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")

	mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES ('pm-card','card','Card')`)
	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount, cloud_payment_summary)
		VALUES ('o-cloud', 1, 'closed', 3000,
			'[{"id":"cp1","payment_method_id":"pm-card","payment_method_code":"card","payment_method_name":"Cloud Card","amount":3000}]')`)

	if got := srv.paymentMethodDisplay("o-cloud", 3000, "en"); got != "Card" {
		t.Errorf("cloud summary via local replica: want Card, got %q", got)
	}
	if got := srv.paymentMethodDisplay("o-cloud", 0, "en"); got != "Card" {
		t.Errorf("whole-order: want Card, got %q", got)
	}
}

// A locally-recorded payment always WINS over the Cloud summary: it is the row
// the cashier created, and it is the one the till reconciles.
func TestPaymentMethodDisplay_LocalPaymentBeatsCloudSummary(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")

	mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES ('pm-cash','cash','Cash'),('pm-card','card','Card')`)
	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount, cloud_payment_summary)
		VALUES ('o-both', 1, 'closed', 1000,
			'[{"id":"cp1","payment_method_id":"pm-card","payment_method_code":"card","payment_method_name":"Card","amount":1000}]')`)
	mustExec(t, db, `INSERT INTO payments (id, order_id, payment_method, payment_method_id, amount, status, idempotency_key, created_at)
		VALUES ('pay-b','o-both','cash','pm-cash',1000,'confirmed','kb','2026-07-19T10:00:00Z')`)

	if got := srv.paymentMethodDisplay("o-both", 1000, "en"); got != "Cash" {
		t.Errorf("local payment must win: want Cash, got %q", got)
	}
}

// The slip's own amount picks its entry out of a split-paid online order; the
// whole-order view (red invoice) lists every method.
func TestPaymentMethodDisplay_CloudSummaryTargetsSlipAmount(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")

	mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES ('pm-card','card','Card'),('pm-qr','qr','QR')`)
	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount, cloud_payment_summary)
		VALUES ('o-split', 1, 'closed', 3000,
			'[{"id":"c1","payment_method_id":"pm-card","payment_method_code":"card","payment_method_name":"Card","amount":1000},
			  {"id":"c2","payment_method_id":"pm-qr","payment_method_code":"qr","payment_method_name":"QR","amount":2000}]')`)

	if got := srv.paymentMethodDisplay("o-split", 2000, "en"); got != "QR" {
		t.Errorf("amount-targeted slip: want QR, got %q", got)
	}
	got := srv.paymentMethodDisplay("o-split", 0, "en")
	if !strings.Contains(got, "Card") || !strings.Contains(got, "QR") {
		t.Errorf("whole order: want both Card and QR, got %q", got)
	}
}

// A method Cloud knows but the local replica does not (a gateway-only tender, or
// a replica that hasn't pulled yet) falls back to the name Cloud sent — better a
// Cloud-locale name than no line at all.
func TestPaymentMethodDisplay_CloudSummaryNameWhenReplicaMisses(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")

	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount, cloud_payment_summary)
		VALUES ('o-unknown', 1, 'closed', 1200,
			'[{"id":"c1","payment_method_id":"pm-gone","payment_method_code":"","payment_method_name":"PayPay","amount":1200}]')`)

	if got := srv.paymentMethodDisplay("o-unknown", 1200, "en"); got != "PayPay" {
		t.Errorf("cloud-supplied name: want PayPay, got %q", got)
	}
}

// Last resort. A paid order whose method cannot be named at all — a legacy order
// synced before the summary column existed — prints a neutral label rather than
// dropping the line, which is exactly what hid this gap. An UNPAID order still
// prints nothing.
func TestPaymentMethodDisplay_NeutralLabelForUnnameablePaidOrder(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")

	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount) VALUES ('o-legacy', 1, 'closed', 900)`)
	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount) VALUES ('o-open', 2, 'open', 0)`)

	cases := map[string]string{"ja": "オンライン決済", "en": "Online", "vi": "Thanh toan online"}
	for locale, want := range cases {
		if got := srv.paymentMethodDisplay("o-legacy", 900, locale); got != want {
			t.Errorf("[%s] paid-but-unnameable: want %q, got %q", locale, want, got)
		}
	}

	if got := srv.paymentMethodDisplay("o-open", 0, "en"); got != "" {
		t.Errorf("unpaid order must print no payment line, got %q", got)
	}
}

// The neutral label must never overwrite money taken at THIS till. A local
// settled payment that simply can't be named (blank code, replica not pulled)
// keeps the pre-#1282 silence — calling counter cash "online" on the customer's
// receipt would be worse than printing no line.
func TestPaymentMethodDisplay_NoOnlineLabelForUnnameableLocalPayment(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")

	mustExec(t, db, `INSERT INTO orders (id, order_number, status, paid_amount) VALUES ('o-blank', 1, 'closed', 800)`)
	mustExec(t, db, `INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, created_at)
		VALUES ('pay-blank','o-blank','',800,'confirmed','kbl','2026-07-30T10:00:00Z')`)

	if got := srv.paymentMethodDisplay("o-blank", 800, "ja"); got != "" {
		t.Errorf("local-but-unnameable payment must stay silent, got %q", got)
	}
}

func TestPaymentMethodCodeLabel(t *testing.T) {
	if got := paymentMethodCodeLabel("transfer", "vi"); got != "Chuyen khoan" {
		t.Errorf("transfer/vi: got %q", got)
	}
	if got := paymentMethodCodeLabel("e_wallet", "ja"); got != "電子マネー" {
		t.Errorf("e_wallet/ja: got %q", got)
	}
	if got := paymentMethodCodeLabel("paypay", "en"); got != "PayPay" {
		t.Errorf("paypay/en: got %q", got)
	}
	// Stripe prints as the CARD word, not the processor's name (chủ dự án
	// 2026-08-17). Asserted on both codes and in all three locales because the
	// point is that the reader never learns which gateway was involved — one
	// locale left saying "Stripe" would leak exactly that on that shop's paper.
	//
	// `payment_methods.name` is NULL for every row Cloud ships today, so this
	// fallback IS the printed word rather than a rarely-taken branch — measured
	// 2026-08-17, all seven codes.
	for _, code := range []string{"stripe", "stripe_card"} {
		if got := paymentMethodCodeLabel(code, "ja"); got != "カード" {
			t.Errorf("%s/ja: got %q, want カード", code, got)
		}
		if got := paymentMethodCodeLabel(code, "en"); got != "Card" {
			t.Errorf("%s/en: got %q, want Card", code, got)
		}
		if got := paymentMethodCodeLabel(code, "vi"); got != "The" {
			t.Errorf("%s/vi: got %q, want The", code, got)
		}
	}
	// Unknown code → returned verbatim (never blank when a code exists).
	if got := paymentMethodCodeLabel("brand_new_wallet", "en"); got != "brand_new_wallet" {
		t.Errorf("unknown: got %q", got)
	}
	if got := paymentMethodCodeLabel("", "en"); got != "" {
		t.Errorf("empty code: got %q", got)
	}
}

// End-to-end at the formatter: with the resolved method on the slip, BOTH the
// paid receipt and the red invoice print the localized label AND the method
// value. (Shift_JIS decode; Vietnamese folds to ASCII.)
func TestPaymentMethodPrintsOnBothTickets(t *testing.T) {
	order := &service.Order{
		ID:          "o1",
		OrderCode:   "ORD-1",
		TableNumber: "A-01",
		PaidAmount:  2000,
		TotalAmount: 2000,
	}
	cases := []struct {
		locale, label, method string
	}{
		{"ja", "支払方法", "現金"},
		{"en", "Method", "Cash"},
		{"vi", "Phuong thuc", "Tien mat"},
	}
	for _, c := range cases {
		cfg := service.PrintJobConfig{Locale: c.locale, PaperWidth: 48}
		slip := service.PaymentSlipInfo{PaymentMethod: c.method, AmountPaid: 2000}

		paid := decodeSJIS(service.FormatPaidTicket(order, nil, 0, cfg, slip))
		red := decodeSJIS(service.FormatRedInvoiceTicket(order, nil, cfg, slip))
		for name, out := range map[string]string{"receipt": paid, "red-invoice": red} {
			if !strings.Contains(out, c.label) {
				t.Errorf("[%s %s] missing label %q", c.locale, name, c.label)
			}
			if !strings.Contains(out, c.method) {
				t.Errorf("[%s %s] missing method %q", c.locale, name, c.method)
			}
		}
	}
}
