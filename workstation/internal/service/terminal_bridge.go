package service

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	stdsync "sync"
	"time"
)

// TerminalBridge drives a Verifone P400 card terminal (SBPS 対面) via the VescaJS
// FullFeatured-WS SDK. pos-web is a browser and cannot open ws:// to the P400
// (mixed-content), so the workstation is the bridge: its Wails webview runs the
// VescaJS bridge and talks to this service over localOnly HTTP. See
// docs/guide/pos-card-terminal-p400-vesca.md.
//
// Relay model (no Wails events): pos-web POSTs a charge → this service queues a
// command → the workstation frontend polls NextCommand, runs VescaJS against the
// P400, and reports back via Complete/Fail. One transaction at a time on the one
// machine (N POS serialize; ErrMachineBusy on a concurrent charge).
// How long a session may sit in each phase with nobody driving it.
//
// These are NOT guesses about how long a card transaction takes — nobody knows
// that (VescaJS has per-step timeouts of 3–5s and no total budget, and the shop
// owner's answer to "how long is a swipe" was "không biết khi nào quét thì hết").
// Timing out a transaction on a guessed duration is the wrong question: guess
// short and every miss produces an `unknown` that costs a human a walk to the
// P400 to read its slip.
//
// What we CAN observe is whether anyone is still driving. The Wails frontend
// polls NextCommand every 1.2s, so silence means the driver is gone. Both
// thresholds must exceed 38.4s — the bridge's own worst-case poll gap, from
// `POLL_MS * 2**5` at its backoff cap (frontend/src/providers/terminal-bridge.tsx)
// — or a live bridge that hit a few network blips gets its session killed.
const (
	// A queued command a live bridge has not picked up. Measured from creation:
	// any polling bridge claims it within one poll gap, so 60s of not-claimed
	// means nobody is home. Safe to settle hard — the P400 was never touched.
	terminalQueuedGrace = 60 * time.Second

	// A processing session whose driver stopped reporting. Measured from the
	// last poll that named THIS session (see TerminalBridge.NextCommand): a
	// webview reload keeps polling but drives nothing, and that must not look
	// like liveness.
	terminalDriverSilence = 90 * time.Second

	// Absolute ceiling on one charge, whatever the driver claims (owner ruling,
	// 2026-08-06). Liveness alone cannot catch a bridge that keeps polling while
	// VescaJS or the P400 itself is wedged — from Go's side that is
	// indistinguishable from a customer taking their time. 15 minutes is far past
	// any real swipe, so hitting it means something is broken, not slow.
	terminalProcessingCap = 15 * time.Minute

	// How many finished sessions stay addressable. A late Complete must still
	// find its session or the capture is recorded nowhere; one machine runs one
	// transaction at a time, so 20 is hours of history. What it cannot cover is
	// a process restart — sessions live in RAM (tracked separately).
	terminalHistoryLimit = 20
)

type TerminalBridge struct {
	recorder CardPaymentRecorder

	// now is the clock, injectable so expiry is testable without sleeping.
	now func() time.Time

	mu      stdsync.Mutex
	active  *terminalSession
	history []*terminalSession // most recent last, capped at terminalHistoryLimit
	seq     int
}

// VescaService is the VescaJS payment service (CurrentService).
type VescaService string

const (
	ServiceCredit VescaService = "Credit"
	ServiceEMoney VescaService = "ElectronicMoney"
	ServiceQRCode VescaService = "QRCode"
)

// CardPaymentRecorder persists an approved terminal card payment (create pending
// + confirm + sync UP). Implemented in the handler layer, reusing the existing
// card pending→confirm path.
type CardPaymentRecorder interface {
	RecordCardPayment(ctx context.Context, p CardPayment) (paymentID string, err error)
}

// CardPayment is an approved P400 charge to record.
type CardPayment struct {
	OrderID          string
	Amount           int
	Service          VescaService
	TerminalTxnID    string // for idempotency (from the terminal result)
	TerminalResponse string // raw OutputCompleteEvent JSON, for audit/reprint
}

type terminalStatus string

const (
	statusQueued     terminalStatus = "queued"     // waiting for the frontend to pick up
	statusProcessing terminalStatus = "processing" // frontend driving the P400
	statusApproved   terminalStatus = "approved"   // done, payment recorded
	statusDeclined   terminalStatus = "declined"   // done, terminal error/decline
	statusCanceled   terminalStatus = "canceled"   // done, canceled

	// statusUnknown: the driver stopped reporting while the P400 may already have
	// captured the card. NOT `declined` — that would be a lie with money behind
	// it, the same reason SweepStaleReservations marks a crashed print
	// `needs_attention` instead of `failed`. Staff must read the terminal before
	// charging again; a late Complete for this session still records normally.
	statusUnknown terminalStatus = "unknown"
)

type terminalSession struct {
	id      string
	orderID string
	amount  int
	service VescaService
	request map[string]any // VescaJS AuthorizeSales

	mu        stdsync.Mutex
	status    terminalStatus
	paymentID string
	errMsg    string
	cancelReq bool // a cancel was requested; NextCommand hands the frontend a Cancel

	startedAt time.Time // when Charge queued it
	// drivenAt is the last moment a poll named THIS session, i.e. the last proof
	// somebody is actually driving it. Zero until the command is picked up.
	drivenAt time.Time
	// endedAt/expired record how a session finished, for the snapshot and audit.
	endedAt time.Time
	expired bool
}

// TerminalCommand is what the workstation frontend polls to drive the P400.
type TerminalCommand struct {
	SessionID string         `json:"session_id"`
	Cancel    bool           `json:"cancel"`
	Request   map[string]any `json:"request,omitempty"` // VescaJS AuthorizeSales
}

// TerminalSnapshot is the pollable state for pos-web.
type TerminalSnapshot struct {
	SessionID string
	OrderID   string
	Status    string
	PaymentID string
	Amount    int
	Error     string
	StartedAt time.Time
	EndedAt   time.Time // zero while still running
	Expired   bool      // settled by the bridge, not by a terminal result
}

// ErrTerminalBusy is returned by Charge when a transaction is already running.
var ErrTerminalBusy = errors.New("card terminal: a transaction is already in progress")

// NewTerminalBridge wires the bridge. recorder is normally the *Server.
func NewTerminalBridge(recorder CardPaymentRecorder) *TerminalBridge {
	return &TerminalBridge{recorder: recorder, now: time.Now}
}

func (b *TerminalBridge) clock() time.Time {
	if b.now != nil {
		return b.now()
	}
	return time.Now()
}

// expireLocked settles the active session when nobody is driving it any more.
// The CALLER MUST HOLD b.mu — the lock order across this type is b.mu then
// sess.mu (Charge takes both), and inverting it deadlocks.
//
// Called from every path that observes the bridge (Charge, NextCommand,
// session, ActiveSnapshot). A timer goroutine would be the obvious alternative
// and is worse here: Complete runs the recorder OUTSIDE any lock, so a timer
// firing in that window would race the approved write and could flip a settled
// payment to unknown. There is also nothing for a timer to catch that traffic
// does not: nobody reads a session except through one of those four paths.
func (b *TerminalBridge) expireLocked() {
	sess := b.active
	if sess == nil {
		return
	}
	now := b.clock()

	sess.mu.Lock()
	defer sess.mu.Unlock()
	switch sess.status {
	case statusQueued:
		// A live bridge claims a queued command within one poll gap. Still
		// unclaimed past the grace window means no bridge is running at all.
		// Safe to settle hard: the P400 was never contacted.
		if now.Sub(sess.startedAt) <= terminalQueuedGrace {
			return
		}
		sess.status = statusCanceled
		sess.errMsg = "no card-terminal bridge picked up the command — is the workstation window open?"
		sess.endedAt = sess.startedAt.Add(terminalQueuedGrace)
	case statusProcessing:
		// Two independent ways to give up, whichever comes first.
		silentAt := sess.drivenAt.Add(terminalDriverSilence)
		cappedAt := sess.startedAt.Add(terminalProcessingCap)
		deadline, reason := silentAt, "the card terminal stopped reporting"
		if cappedAt.Before(deadline) {
			deadline, reason = cappedAt, "the card transaction exceeded its 15-minute limit"
		}
		if !now.After(deadline) {
			return
		}
		sess.status = statusUnknown
		sess.errMsg = reason + " — check the P400 screen/slip before charging again"
		sess.endedAt = deadline
	default:
		return
	}
	sess.expired = true

	// endedAt is the deadline, not `now`: expiry is detected lazily, so the
	// moment somebody happened to look is not the moment the session died.
	slog.Warn("card terminal session expired",
		"session", sess.id, "order", sess.orderID, "amount", sess.amount,
		"status", string(sess.status), "expired_at", sess.endedAt)
}

// Charge starts a card transaction for `amount` JPY on the P400. Returns the
// session id pos-web polls, or ErrTerminalBusy.
func (b *TerminalBridge) Charge(orderID string, amount int, svc VescaService) (string, error) {
	if svc == "" {
		svc = ServiceCredit
	}
	b.mu.Lock()
	defer b.mu.Unlock()

	// Before deciding the machine is busy: a session nobody is driving is not
	// busy, it is abandoned. Without this the shop stays 409 until a restart.
	b.expireLocked()

	if b.active != nil {
		b.active.mu.Lock()
		done := isTerminalDone(b.active.status)
		b.active.mu.Unlock()
		if !done {
			return "", ErrTerminalBusy
		}
		b.retireLocked(b.active)
	}

	b.seq++
	sess := &terminalSession{
		id:        newSessionID(),
		orderID:   orderID,
		amount:    amount,
		service:   svc,
		status:    statusQueued,
		startedAt: b.clock(),
		request: map[string]any{
			"AuthorizeSales": map[string]any{
				"SequenceNumber": b.seq,
				"CurrentService": string(svc),
				"Amount":         amount,
			},
		},
	}
	b.active = sess
	return sess.id, nil
}

// retireLocked moves a finished session into the bounded history so a late
// Complete can still find it. Caller must hold b.mu.
//
// This is what keeps an approval from evaporating: once a new Charge replaces
// b.active, the old id used to resolve to nothing, Complete answered "unknown
// session", and the frontend's postResult swallowed the error — a captured card
// with no payment row anywhere. Expiry makes that replacement routine, so the
// history is not optional decoration, it is the other half of the fix.
func (b *TerminalBridge) retireLocked(sess *terminalSession) {
	for _, s := range b.history {
		if s == sess {
			return
		}
	}
	b.history = append(b.history, sess)
	if len(b.history) > terminalHistoryLimit {
		b.history = b.history[len(b.history)-terminalHistoryLimit:]
	}
}

// NextCommand is polled by the workstation frontend. It returns the pending
// command (the AuthorizeSales to run, or a Cancel) and moves a queued session to
// processing. ok is false when there is nothing to do.
// driving is the session id the caller is currently running on the P400, or ""
// when it is idle. It is the liveness signal: a webview that reloaded mid
// transaction keeps polling but drives nothing, and must NOT keep the orphaned
// session alive. Only a poll that names the session refreshes its clock.
func (b *TerminalBridge) NextCommand(driving string) (TerminalCommand, bool) {
	b.mu.Lock()
	b.expireLocked()
	sess := b.active
	now := b.clock()
	b.mu.Unlock()
	if sess == nil {
		return TerminalCommand{}, false
	}

	sess.mu.Lock()
	defer sess.mu.Unlock()
	if driving != "" && driving == sess.id {
		sess.drivenAt = now
	}
	if isTerminalDone(sess.status) {
		return TerminalCommand{}, false
	}
	if sess.cancelReq {
		return TerminalCommand{SessionID: sess.id, Cancel: true}, true
	}
	if sess.status == statusQueued {
		sess.status = statusProcessing
		sess.drivenAt = now
		return TerminalCommand{SessionID: sess.id, Request: sess.request}, true
	}
	return TerminalCommand{}, false
}

// Abandon force-settles the in-flight session as `unknown` without waiting for
// the frontend. Cancel cannot do this job: it only asks the frontend to stop, so
// when the frontend IS the dead part it can never complete — which left the only
// escape as restarting the process while a customer waits.
//
// The outcome is `unknown`, never `canceled`: whoever presses this does not know
// whether the card was captured, and the record must say exactly that.
func (b *TerminalBridge) Abandon() (TerminalSnapshot, bool) {
	b.mu.Lock()
	defer b.mu.Unlock()
	b.expireLocked()
	sess := b.active
	if sess == nil {
		return TerminalSnapshot{}, false
	}

	sess.mu.Lock()
	defer sess.mu.Unlock()
	if isTerminalDone(sess.status) {
		return TerminalSnapshot{}, false
	}
	sess.status = statusUnknown
	sess.errMsg = "abandoned by staff — check the P400 screen/slip before charging again"
	sess.endedAt = b.clock()
	slog.Warn("card terminal session abandoned by staff",
		"session", sess.id, "order", sess.orderID, "amount", sess.amount)
	return snapshotLocked(sess), true
}

// ActiveSnapshot reports the session currently holding the machine, so a 409 can
// say WHAT is blocking instead of just that something is. ok is false when idle.
func (b *TerminalBridge) ActiveSnapshot() (TerminalSnapshot, bool) {
	b.mu.Lock()
	defer b.mu.Unlock()
	b.expireLocked()
	sess := b.active
	if sess == nil {
		return TerminalSnapshot{}, false
	}
	sess.mu.Lock()
	defer sess.mu.Unlock()
	if isTerminalDone(sess.status) {
		return TerminalSnapshot{}, false
	}
	return snapshotLocked(sess), true
}

// Complete is called by the frontend with an APPROVED terminal result. It
// records the card payment and marks the session approved.
func (b *TerminalBridge) Complete(ctx context.Context, sessionID string, terminalData map[string]any) error {
	sess := b.session(sessionID)
	if sess == nil {
		return errors.New("card terminal: unknown session")
	}

	txnID, _ := terminalData["SlipNumber"].(string)
	if txnID == "" {
		if v, ok := terminalData["TransactionId"].(string); ok {
			txnID = v
		} else {
			txnID = sess.id // fall back to the session id for idempotency
		}
	}
	raw, _ := json.Marshal(terminalData)

	pid, err := b.recorder.RecordCardPayment(ctx, CardPayment{
		OrderID:          sess.orderID,
		Amount:           sess.amount,
		Service:          sess.service,
		TerminalTxnID:    txnID,
		TerminalResponse: string(raw),
	})
	if err != nil {
		// MONEY-CRITICAL: the terminal captured funds but recording failed —
		// surface with the terminal txn id so staff can reconcile.
		return fmt.Errorf("card captured (terminal txn %s) but recording failed: %w", txnID, err)
	}

	sess.mu.Lock()
	late := isTerminalDone(sess.status) // the bridge had already given up on it
	sess.status = statusApproved
	sess.paymentID = pid
	sess.endedAt = b.clock()
	sess.mu.Unlock()

	if late {
		// The capture landed after the session was settled as unknown/abandoned,
		// so the order may have been taken again by other means in the meantime
		// (cash, another card) — recording is still right, staying quiet is not.
		slog.Warn("card terminal: late capture recorded on a settled session — verify the order was not paid twice",
			"session", sess.id, "order", sess.orderID, "amount", sess.amount,
			"payment", pid, "terminal_txn", txnID)
	}
	return nil
}

// Fail is called by the frontend when the terminal declined or errored. No
// payment is recorded.
func (b *TerminalBridge) Fail(sessionID, reason string) error {
	sess := b.session(sessionID)
	if sess == nil {
		return errors.New("card terminal: unknown session")
	}
	sess.mu.Lock()
	if sess.cancelReq {
		sess.status = statusCanceled
	} else {
		sess.status = statusDeclined
	}
	sess.errMsg = reason
	sess.mu.Unlock()
	return nil
}

// Cancel requests the in-flight transaction be canceled. The frontend picks up a
// Cancel command on its next NextCommand poll.
func (b *TerminalBridge) Cancel(sessionID string) error {
	sess := b.session(sessionID)
	if sess == nil {
		return errors.New("card terminal: unknown session")
	}
	sess.mu.Lock()
	defer sess.mu.Unlock()
	if isTerminalDone(sess.status) {
		return errors.New("card terminal: transaction already finished")
	}
	sess.cancelReq = true

	// Still queued means no bridge has picked the command up, so the P400 was
	// never told anything and there is nothing to ask it to stop. Settle here
	// instead of waiting for a frontend acknowledgement that may never come:
	// the old behaviour returned 200 while leaving the machine "busy" forever,
	// so even a cashier holding the right session id could not free it.
	//
	// Safe against a racing pickup: NextCommand only promotes a session while
	// holding this same sess.mu and only when the status is still queued.
	if sess.status == statusQueued {
		sess.status = statusCanceled
		sess.endedAt = b.clock()
	}
	return nil
}

// Snapshot returns the state of a session for pos-web polling.
func (b *TerminalBridge) Snapshot(sessionID string) (TerminalSnapshot, bool) {
	sess := b.session(sessionID)
	if sess == nil {
		return TerminalSnapshot{}, false
	}
	sess.mu.Lock()
	defer sess.mu.Unlock()
	return snapshotLocked(sess), true
}

func (b *TerminalBridge) session(id string) *terminalSession {
	b.mu.Lock()
	defer b.mu.Unlock()
	b.expireLocked()
	if b.active != nil && b.active.id == id {
		return b.active
	}
	// Finished sessions stay addressable so a result arriving after the machine
	// moved on is still recorded rather than dropped on the floor.
	for i := len(b.history) - 1; i >= 0; i-- {
		if b.history[i].id == id {
			return b.history[i]
		}
	}
	return nil
}

// snapshotLocked builds the pollable view. Caller must hold sess.mu.
func snapshotLocked(sess *terminalSession) TerminalSnapshot {
	return TerminalSnapshot{
		SessionID: sess.id,
		OrderID:   sess.orderID,
		Status:    string(sess.status),
		PaymentID: sess.paymentID,
		Amount:    sess.amount,
		Error:     sess.errMsg,
		StartedAt: sess.startedAt,
		EndedAt:   sess.endedAt,
		Expired:   sess.expired,
	}
}

func isTerminalDone(s terminalStatus) bool {
	switch s {
	case statusApproved, statusDeclined, statusCanceled, statusUnknown:
		return true
	default:
		return false
	}
}
