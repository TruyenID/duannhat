package service

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	stdsync "sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/device/glory"
)

// ErrMachineBusy is returned by Begin when a collection is already running on
// the (single) machine.
var ErrMachineBusy = errors.New("cash changer: a collection is already in progress")

// A cash collection takes 30–300s (the customer feeding cash + the machine
// dispensing change), so it CANNOT block an HTTP request. Begin runs it in the
// background and returns a session id the POS polls via Snapshot; the cancel
// button hits CancelSession. Only one runs at a time (single machine).

type cashSession struct {
	id      string
	orderID string
	cancel  context.CancelFunc

	mu      stdsync.Mutex
	running bool
	outcome CollectOutcome
	err     error
}

// SessionSnapshot is the pollable state of a collection.
type SessionSnapshot struct {
	SessionID string
	OrderID   string
	Running   bool
	// Set once terminal:
	Status    glory.Status
	PaymentID string // set on a recorded finish
	Total     int
	Tendered  int
	Change    int
	Error     string // terminal non-finish reason (shortage/timeout/failure/cancel)
}

// Begin starts an async cash collection for `total` JPY on the machine. Returns
// the session id immediately, or ErrMachineBusy when one is already running.
func (s *CashChangerService) Begin(orderID string, total int) (string, error) {
	s.sessMu.Lock()
	defer s.sessMu.Unlock()

	if s.active != nil {
		s.active.mu.Lock()
		running := s.active.running
		s.active.mu.Unlock()
		if running {
			return "", ErrMachineBusy
		}
	}

	// Bound the background run generously past the machine's own deposit timeout
	// so a stuck poll can't leak a goroutine forever.
	ctx, cancel := context.WithTimeout(context.Background(), s.depositWait+2*time.Minute)
	sess := &cashSession{id: newSessionID(), orderID: orderID, cancel: cancel, running: true}
	s.active = sess

	go func() {
		out, err := s.Collect(ctx, orderID, total)
		cancel()
		sess.mu.Lock()
		sess.running = false
		sess.outcome = out
		sess.err = err
		sess.mu.Unlock()
	}()

	return sess.id, nil
}

// Snapshot returns the state of the given session. ok is false when the id does
// not match the current/last session.
func (s *CashChangerService) Snapshot(sessionID string) (SessionSnapshot, bool) {
	s.sessMu.Lock()
	sess := s.active
	s.sessMu.Unlock()
	if sess == nil || sess.id != sessionID {
		return SessionSnapshot{}, false
	}

	sess.mu.Lock()
	defer sess.mu.Unlock()
	snap := SessionSnapshot{
		SessionID: sess.id,
		OrderID:   sess.orderID,
		Running:   sess.running,
		Status:    sess.outcome.Status,
		PaymentID: sess.outcome.PaymentID,
		Total:     sess.outcome.Total,
		Tendered:  sess.outcome.Tendered,
		Change:    sess.outcome.Change,
	}
	if sess.err != nil {
		snap.Error = sess.err.Error()
	}
	return snap, true
}

// CancelSession asks the machine to return the deposited cash for the given
// session. The in-flight collection then terminates as canceled.
func (s *CashChangerService) CancelSession(ctx context.Context, sessionID string) error {
	s.sessMu.Lock()
	sess := s.active
	s.sessMu.Unlock()
	if sess == nil || sess.id != sessionID {
		return errors.New("cash changer: unknown session")
	}
	return s.Cancel(ctx)
}

func newSessionID() string {
	var b [12]byte
	_, _ = rand.Read(b[:])
	return hex.EncodeToString(b[:])
}
