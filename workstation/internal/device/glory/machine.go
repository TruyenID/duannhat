package glory

import "context"

// Machine is the minimal transport the transaction state machine (Collector)
// depends on. Method A (*Client, HTTP over LAN) implements it today; method B
// (direct serial to RT/RAD-R08 — see design doc §0b) will implement the SAME
// interface later, so the Collector and ledger mapping never change.
type Machine interface {
	// StartTransaction begins a deposit and returns the transaction id.
	StartTransaction(ctx context.Context, r StartRequest) (string, error)
	// GetTransaction returns the current transaction state (poll ≥1s).
	GetTransaction(ctx context.Context, id string) (Transaction, error)
	// FixDeposit locks the deposit so the machine dispenses change
	// (only when started with ShowFixDepositButton=false).
	FixDeposit(ctx context.Context) error
	// Cancel aborts the in-progress transaction and returns the deposited cash.
	Cancel(ctx context.Context) error
}
