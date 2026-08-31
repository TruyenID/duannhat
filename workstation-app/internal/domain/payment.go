package domain

import (
	"encoding/json"
	"time"
)

type PaymentStatus string

const (
	PaymentStatusPending   PaymentStatus = "pending"
	PaymentStatusConfirmed PaymentStatus = "confirmed"
	PaymentStatusFailed    PaymentStatus = "failed"
)

type PaymentMethod string

const (
	PaymentMethodCash   PaymentMethod = "cash"
	PaymentMethodCard   PaymentMethod = "card"
	PaymentMethodQR     PaymentMethod = "qr"
	PaymentMethodEmoney PaymentMethod = "emoney"
)

// Payment is a workstation-owned entity that syncs UP to Cloud. Full
// parity with Cloud's OrderPayment shape so pos-web's PaymentDialog
// round-trips every cashier-facing field — tendered/change for cash
// drawer math, paid_at for auto-confirm receipts, expires_at for
// terminal-flow timeouts, till_session_id for cashier shift attribution.
//
// `synced_at` is NULL until the sync_queue worker successfully pushes it.
type Payment struct {
	ID               string        `json:"id"`
	CloudID          string        `json:"cloud_id,omitempty"`
	OrderID          string        `json:"order_id"`
	PaymentMethodID  string        `json:"payment_method_id,omitempty"`
	PaymentMethod    PaymentMethod `json:"payment_method"`
	Amount           int           `json:"amount"`
	TipAmount        int           `json:"tip_amount"`
	TenderedAmount   *int          `json:"tendered_amount,omitempty"`
	ChangeAmount     *int          `json:"change_amount,omitempty"`
	ReferenceNo      string        `json:"reference_no,omitempty"`
	Note             string        `json:"note,omitempty"`
	Status           PaymentStatus `json:"status"`
	IdempotencyKey   string        `json:"-"`
	TerminalResponse string        `json:"terminal_response,omitempty"`
	// Metadata mirrors backend order_payments.metadata (raw JSON). For split
	// bills it carries split_count / amount_per_person so the workstation can
	// derive split state for receipt printing without a Cloud round-trip.
	Metadata string     `json:"metadata,omitempty"`
	PaidAt   *time.Time `json:"paid_at,omitempty"`
	// CapturedAt is the device wall-clock instant the payment was recorded
	// (#817 Phase B). It is the cashier-shift attribution key forwarded to
	// Cloud so a late-syncing straggler is credited to the shift it was
	// captured in, not the shift open when it lands. Always device-clock UTC.
	CapturedAt    *time.Time `json:"captured_at,omitempty"`
	ExpiresAt     *time.Time `json:"expires_at,omitempty"`
	TillSessionID string     `json:"till_session_id,omitempty"`
	// Plan-047 — immutable policy identity frozen at capture for offline replay.
	PaymentOptionID       string     `json:"payment_option_id,omitempty"`
	PolicyRevision        int        `json:"policy_revision,omitempty"`
	ConnectionID          string     `json:"connection_id,omitempty"`
	ConnectionOptionID    string     `json:"connection_option_id,omitempty"`
	AttemptIdempotencyKey string     `json:"attempt_idempotency_key,omitempty"`
	CreatedAt             time.Time  `json:"created_at"`
	UpdatedAt             time.Time  `json:"updated_at"`
	SyncedAt              *time.Time `json:"synced_at,omitempty"`
}

// CreatePaymentInput mirrors Cloud's `payments.store` body
// (OrderPaymentController). Pos-web sends payment_method_id (UUID); the
// legacy `payment_method` enum string is kept as a fallback so older
// callers (kiosk, godx-handy) still work.
type CreatePaymentInput struct {
	OrderID             string        `json:"order_id"`
	PaymentMethodID     string        `json:"payment_method_id,omitempty"`
	PaymentOptionID     string        `json:"payment_option_id,omitempty"`
	PaymentMethod       PaymentMethod `json:"payment_method,omitempty"`
	Amount              int           `json:"amount"`
	TipAmount           int           `json:"tip_amount,omitempty"`
	TenderedAmount      *int          `json:"tendered_amount,omitempty"`
	ReferenceNo         string        `json:"reference_no,omitempty"`
	Note                string        `json:"note,omitempty"`
	IdempotencyKey      string        `json:"idempotency_key"`
	TerminalResponse    string        `json:"terminal_response,omitempty"`
	ExpectedTotalAmount *int          `json:"expected_total_amount,omitempty"`
	// Metadata is opaque JSON forwarded to Cloud's order_payments.metadata.
	// json.RawMessage decodes ANY JSON shape (object, string, number, null)
	// into the raw bytes — pos-web sends a nested object for split-bill
	// payments (e.g. `{"split_mode":"equal","bill_index":0,"total_bills":2}`)
	// while kiosk historically sent it as a string. Pre-fix the field was
	// `string` so pos-web's POST 400'd with
	//   `json: cannot unmarshal object into Go struct field
	//    CreatePaymentInput.metadata of type string`
	// (the "Khách 1 Thu" error in the screenshot). Downstream code uses
	// MetadataString() to read the raw JSON text it persists into
	// payments.metadata (TEXT column) + forwards to Cloud unchanged.
	Metadata json.RawMessage `json:"metadata,omitempty"`
}

// MetadataString returns the metadata as the canonical JSON text the
// rest of workstation expects (DB column TEXT, sync UP body's
// `metadata` field). Two input shapes are normalised:
//
//  1. JSON object (`{"split_mode": "equal", ...}`) — pos-web's
//     split-bill flow. Stored verbatim as raw bytes.
//  2. JSON string holding a JSON-encoded inner payload
//     (`"{\"split_mode\":\"equal\",...}"`) — historical kiosk + test
//     fixtures. Decoded once to recover the inner text so DB storage
//     stays a single-level object, not a double-encoded string.
//
// Empty / null → empty string. Any other JSON value (number, array,
// bool) round-trips as the raw bytes so we don't drop information.
func (i *CreatePaymentInput) MetadataString() string {
	if len(i.Metadata) == 0 || string(i.Metadata) == "null" {
		return ""
	}
	// JSON string ("\"...\"") → unquote to the inner text. This keeps
	// the legacy "metadata is the JSON string of the payload" callers
	// (kiosk fixtures, prior pos-web builds) writing single-encoded
	// rows into payments.metadata instead of `"{\"key\":\"val\"}"`.
	if i.Metadata[0] == '"' {
		var inner string
		if json.Unmarshal(i.Metadata, &inner) == nil {
			return inner
		}
	}
	return string(i.Metadata)
}
