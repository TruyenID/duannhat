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

func TestPaymentMethodCodeLabel(t *testing.T) {
	if got := paymentMethodCodeLabel("transfer", "vi"); got != "Chuyen khoan" {
		t.Errorf("transfer/vi: got %q", got)
	}
	if got := paymentMethodCodeLabel("e_wallet", "ja"); got != "電子マネー" {
		t.Errorf("e_wallet/ja: got %q", got)
	}
	// Unknown code → returned verbatim (never blank when a code exists).
	if got := paymentMethodCodeLabel("paypay", "en"); got != "paypay" {
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
