package handler

import "testing"

// The customer tapped a card. The slip must say so — not name the payment
// processor the shop happens to route that card through.
//
// This is the shape that made the existing `stripe` entry in
// paymentMethodCodeLabel dead code for as long as it existed: that entry lives
// in the LAST-RESORT ladder, reached only when no synced `payment_methods` row
// matches. Cloud ships a `stripe` row (PaymentMethodSeeder) named "Stripe" in
// all three locales, so any shop with a synced catalogue always has one, the
// synced name wins, and the label is never consulted.
//
// So the fixture here deliberately DOES insert the row. Dropping it would make
// this test pass against the old code too, which is exactly the version of this
// test that would have let the bug ship.
func TestPaymentMethodDisplay_StripeIsNamedByTender(t *testing.T) {
	for _, tc := range []struct{ locale, want string }{
		{"ja", "カード"},
		{"en", "Card"},
		{"vi", "The"}, // ASCII-folded for the Shift_JIS print catalog
	} {
		t.Run(tc.locale, func(t *testing.T) {
			srv, db := newServerWithAuth(t, "http://cloud.invalid")

			mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES ('pm-stripe','stripe','Stripe')`)
			mustExec(t, db, `INSERT INTO orders (id, order_number, status) VALUES ('o-st', 1, 'closed')`)
			mustExec(t, db, `INSERT INTO payments (id, order_id, payment_method, payment_method_id, amount, status, idempotency_key, created_at)
				VALUES ('pay-st','o-st','stripe','pm-stripe',3500,'confirmed','kst','2026-08-17T10:00:00Z')`)

			// Both ladders: the exact-amount lookup (a single payer) and the
			// whole-order lookup (amount 0 — reprints and split invoices). The
			// bug reached paper through the first and would have survived a test
			// that only covered the second.
			if got := srv.paymentMethodDisplay("o-st", 3500, tc.locale); got != tc.want {
				t.Errorf("exact-amount: want %q, got %q", tc.want, got)
			}
			if got := srv.paymentMethodDisplay("o-st", 0, tc.locale); got != tc.want {
				t.Errorf("whole-order: want %q, got %q", tc.want, got)
			}
		})
	}
}

// Resolution by CODE only — a payment row that carries no payment_method_id.
// Same answer; the gateway check has to sit on both branches because the two
// take different exits out of resolvePaymentMethodName.
func TestPaymentMethodDisplay_StripeByCodeAlone(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")

	mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES ('pm-stripe','stripe','Stripe')`)
	mustExec(t, db, `INSERT INTO orders (id, order_number, status) VALUES ('o-sc', 1, 'closed')`)
	mustExec(t, db, `INSERT INTO payments (id, order_id, payment_method, amount, status, idempotency_key, created_at)
		VALUES ('pay-sc','o-sc','stripe',1200,'confirmed','ksc','2026-08-17T10:00:00Z')`)

	if got := srv.paymentMethodDisplay("o-sc", 1200, "en"); got != "Card" {
		t.Errorf("by-code: want Card, got %q", got)
	}
}

// The exception must stay an exception. Every other method keeps printing the
// name the SHOP configured — that is the documented rule, and widening the
// override to a code→type rewrite would silently rename tenders shops chose.
func TestPaymentMethodDisplay_ShopConfiguredNamesSurvive(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://cloud.invalid")

	mustExec(t, db, `INSERT INTO payment_methods (id, code, name) VALUES
		('pm-cash','cash','Tien mat quay 1'),
		('pm-card','card','The ngan hang'),
		('pm-wallet','e_wallet','Vi MoMo')`)
	mustExec(t, db, `INSERT INTO orders (id, order_number, status) VALUES ('o-keep', 1, 'closed')`)

	for _, tc := range []struct {
		id, code, name string
		amount         int
	}{
		{"pm-cash", "cash", "Tien mat quay 1", 100},
		{"pm-card", "card", "The ngan hang", 200},
		{"pm-wallet", "e_wallet", "Vi MoMo", 300},
	} {
		mustExec(t, db, `INSERT INTO payments (id, order_id, payment_method, payment_method_id, amount, status, idempotency_key, created_at)
			VALUES (?, 'o-keep', ?, ?, ?, 'confirmed', ?, '2026-08-17T10:00:00Z')`,
			"pay-"+tc.code, tc.code, tc.id, tc.amount, "k-"+tc.code)

		if got := srv.paymentMethodDisplay("o-keep", tc.amount, "ja"); got != tc.name {
			t.Errorf("%s: want shop-configured %q, got %q", tc.code, tc.name, got)
		}
	}
}
