// Package glory is the client for a Glory つり銭機 (cash recycler) reached via
// the YRT-R08-MN adapter's "簡単インターフェース" — HTTP/JSON over the LAN
// (port 80, no TLS, IP-allowlist). See docs/guide/cash-changer-glory-adapter.md.
//
// Connection method A (this package): HTTP/JSON through the YRT-R08-MN box.
// Method B (direct serial to RT/RAD-R08) is intentionally NOT implemented yet;
// when it is, it implements the same Machine interface (machine.go) so the
// transaction state machine (Collector) and ledger mapping stay unchanged.
package glory

// Status is the transaction lifecycle status returned by GetTransaction
// (取引ステータス). See §3.3 of the design doc.
type Status string

const (
	// StatusBeginDeposit — 入金中: the machine is accepting cash.
	StatusBeginDeposit Status = "beginDeposit"
	// StatusDispenseChange — 出金中: the machine is dispensing change.
	StatusDispenseChange Status = "dispenseChange"
	// StatusWaitPullOut — つり銭抜き取り待ち: change dispensed, awaiting pickup.
	StatusWaitPullOut Status = "waitPullOut"
	// StatusFinish — 取引完了: completed normally (terminal).
	StatusFinish Status = "finish"
	// StatusCancel — 取引キャンセル: cancelled, deposited cash returned (terminal).
	StatusCancel Status = "cancel"
	// StatusAbort — 取引強制終了: power/OS crash mid-transaction (terminal).
	StatusAbort Status = "abort"
	// StatusTimeout — 取引タイムアウト: deposit not completed in time; cash KEPT (terminal).
	StatusTimeout Status = "timeout"
	// StatusFailure — 取引エラー終了: machine error while dispensing/awaiting pickup (terminal).
	StatusFailure Status = "failure"
)

// IsTerminal reports whether the transaction has reached an end state and will
// not transition further.
func (s Status) IsTerminal() bool {
	switch s {
	case StatusFinish, StatusCancel, StatusAbort, StatusTimeout, StatusFailure:
		return true
	default:
		return false
	}
}

// StartRequest is the body of 取引開始 API (POST /api/v1/transactions).
type StartRequest struct {
	// Total is the amount due, tax-included, in JPY minor==major units
	// (1..9,999,999). Required.
	Total int `json:"total"`
	// ShowFixDepositButton toggles the physical confirm button on the adapter
	// screen. We send false so the software drives the commit via FixDeposit
	// (sequence 5.2); true relies on the operator pressing the on-screen button
	// (sequence 5.1) and FixDeposit must NOT be called.
	ShowFixDepositButton bool `json:"showFixDepositButton"`
	// Timeout is the deposit-completion timeout in seconds (0 = no timeout,
	// max 86400). On timeout the machine auto-cancels but KEEPS the cash.
	Timeout int `json:"timeout"`
}

// Transaction is the 取引取得 API response (GET /api/v1/transactions/{id}).
type Transaction struct {
	TransactionID     string `json:"transactionId"`
	TransactionStatus Status `json:"transactionStatus"`
	Total             int    `json:"total"`
	Deposit           int    `json:"deposit"`       // cash inserted so far
	Change            int    `json:"change"`        // change to dispense (set once fixDeposit=true)
	DispensedCash     int    `json:"dispensedCash"` // change actually dispensed (finish) / deposit returned (cancel)
	FixDeposit        bool   `json:"fixDeposit"`    // true once deposit is locked
	SeqNo             int64  `json:"seqNo"`         // adapter UNIX-ms; larger = newer
	StartDate         string `json:"startDate"`     // ISO-8601
	EndDate           string `json:"endDate"`       // ISO-8601
}

// startResponse is the 取引開始 success body.
type startResponse struct {
	TransactionID string `json:"transactionId"`
}

// StatusInfo is the 状態取得 API response (GET /api/v1/machine/status).
// setInfo bit-flag meanings differ per model (RT/RAD-R08/-300/-380) — decode
// at the monitoring layer, not here.
type StatusInfo struct {
	Bill       Component         `json:"bill"`
	Coin       Component         `json:"coin"`
	CashStatus map[string]string `json:"cashStatus"` // per-denom: empty|nearEmpty|enough|nearFull|full (+ billReject/cassete/overflow)
	SeqNo      int64             `json:"seqNo"`
}

// Component is a bill/coin unit's error code + device-state bit flags.
type Component struct {
	ErrorCode int `json:"errorCode"` // non-zero = error state
	SetInfo   int `json:"setInfo"`   // bit flags (doors/covers/lock), model-specific
}

// Inventory is the 在高取得 API response (GET /api/v1/machine/cash).
// Denominations with a zero count are OMITTED from the JSON — always default
// missing keys to 0.
type Inventory struct {
	CashCount struct {
		Cash  map[string]int `json:"cash"`  // per-denom count in the recycler
		Stock map[string]int `json:"stock"` // non-dispensable stock (cassette etc.)
		Wrap  map[string]int `json:"wrap"`  // wrapped-coin unit
	} `json:"cashCount"`
	CashErrorStatus struct {
		Cash     map[string]bool `json:"cash"`     // per-denom 在高不確定 (inventory-uncertain)
		Cassette bool            `json:"cassette"` //
	} `json:"cashErrorStatus"`
	BillRejectCount int   `json:"billRejectCount"` // >0 = reject bin holds bills to collect
	SeqNo           int64 `json:"seqNo"`
}
