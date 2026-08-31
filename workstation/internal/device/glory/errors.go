package glory

import "fmt"

// Error is a Glory adapter failure response: an HTTP 4xx/503 whose JSON body
// carries {title, detail}. Bad URL paths return 404 with an HTML body instead —
// those surface as an Error with an empty Title and the raw body in Detail.
type Error struct {
	HTTPStatus int
	Title      string // e.g. "empty","full","busy","error","ifError","notReady",
	// "needPullOut","recovery","setError","systemError","impossible",
	// "processing","billRejectFull","notEnough","forbidden","notFound","parameter"
	Detail string
}

func (e *Error) Error() string {
	return fmt.Sprintf("glory: %d %s: %s", e.HTTPStatus, e.Title, e.Detail)
}

// Title predicates for the state machine / UX. Grouped by how the operator
// must react (see design doc §3.4 / §5).

// IsForbidden is a 403 — the caller's IP is not in the adapter allowlist.
func (e *Error) IsForbidden() bool { return e.Title == "forbidden" }

// IsNotFound is a 404 — no such transaction in progress.
func (e *Error) IsNotFound() bool { return e.Title == "notFound" }

// IsBusy means the adapter is doing something else (local operation / another
// transaction in progress) and the call should be retried later.
func (e *Error) IsBusy() bool {
	switch e.Title {
	case "busy", "processing", "impossible":
		return true
	default:
		return false
	}
}

// IsChangeShortage — "empty": the recycler cannot dispense change. The caller
// MUST cancel the transaction, refill change, then retry.
func (e *Error) IsChangeShortage() bool { return e.Title == "empty" }

// IsNotEnoughDeposit — "notEnough": FixDeposit called while deposit < total.
func (e *Error) IsNotEnoughDeposit() bool { return e.Title == "notEnough" }

// NeedsOperator means a human must act on the machine (jam, full bin, unlocked
// unit, pull out cash) before the call can succeed. During a live transaction
// these are recoverable: the transaction resumes once the machine is cleared.
func (e *Error) NeedsOperator() bool {
	switch e.Title {
	case "error", "full", "billRejectFull", "needPullOut", "setError",
		"recovery", "systemError":
		return true
	default:
		return false
	}
}

// IsConnectivity means the adapter cannot reach the recycler (serial cable /
// power / not ready).
func (e *Error) IsConnectivity() bool {
	switch e.Title {
	case "ifError", "notReady":
		return true
	default:
		return false
	}
}

// asError type-asserts err to *Error, returning nil when it is not one.
func asError(err error) *Error {
	if ge, ok := err.(*Error); ok {
		return ge
	}
	return nil
}
