package service

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	stdsync "sync"
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
type TerminalBridge struct {
	recorder CardPaymentRecorder

	mu     stdsync.Mutex
	active *terminalSession
	seq    int
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
}

// ErrTerminalBusy is returned by Charge when a transaction is already running.
var ErrTerminalBusy = errors.New("card terminal: a transaction is already in progress")

// NewTerminalBridge wires the bridge. recorder is normally the *Server.
func NewTerminalBridge(recorder CardPaymentRecorder) *TerminalBridge {
	return &TerminalBridge{recorder: recorder}
}

// Charge starts a card transaction for `amount` JPY on the P400. Returns the
// session id pos-web polls, or ErrTerminalBusy.
func (b *TerminalBridge) Charge(orderID string, amount int, svc VescaService) (string, error) {
	if svc == "" {
		svc = ServiceCredit
	}
	b.mu.Lock()
	defer b.mu.Unlock()

	if b.active != nil {
		b.active.mu.Lock()
		done := isTerminalDone(b.active.status)
		b.active.mu.Unlock()
		if !done {
			return "", ErrTerminalBusy
		}
	}

	b.seq++
	sess := &terminalSession{
		id:      newSessionID(),
		orderID: orderID,
		amount:  amount,
		service: svc,
		status:  statusQueued,
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

// NextCommand is polled by the workstation frontend. It returns the pending
// command (the AuthorizeSales to run, or a Cancel) and moves a queued session to
// processing. ok is false when there is nothing to do.
func (b *TerminalBridge) NextCommand() (TerminalCommand, bool) {
	b.mu.Lock()
	sess := b.active
	b.mu.Unlock()
	if sess == nil {
		return TerminalCommand{}, false
	}

	sess.mu.Lock()
	defer sess.mu.Unlock()
	if sess.cancelReq && !isTerminalDone(sess.status) {
		return TerminalCommand{SessionID: sess.id, Cancel: true}, true
	}
	if sess.status == statusQueued {
		sess.status = statusProcessing
		return TerminalCommand{SessionID: sess.id, Request: sess.request}, true
	}
	return TerminalCommand{}, false
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
	sess.status = statusApproved
	sess.paymentID = pid
	sess.mu.Unlock()
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
	return TerminalSnapshot{
		SessionID: sess.id,
		OrderID:   sess.orderID,
		Status:    string(sess.status),
		PaymentID: sess.paymentID,
		Amount:    sess.amount,
		Error:     sess.errMsg,
	}, true
}

func (b *TerminalBridge) session(id string) *terminalSession {
	b.mu.Lock()
	defer b.mu.Unlock()
	if b.active != nil && b.active.id == id {
		return b.active
	}
	return nil
}

func isTerminalDone(s terminalStatus) bool {
	switch s {
	case statusApproved, statusDeclined, statusCanceled:
		return true
	default:
		return false
	}
}
