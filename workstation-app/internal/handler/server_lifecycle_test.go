package handler

// Lifecycle tests for the LAN HTTP server. Two invariants are pinned here:
//
//   1. Start() is idempotent — calling it twice must NOT stack duplicate
//      background goroutines (Wails window reload / over-eager caller).
//   2. Stop() returns the goroutine count to its baseline — no leaks via
//      Hub.Run, Monitor's per-minute rotator, the puller loops, etc.
//
// The httpServer.ListenAndServe call is intentionally NOT exercised here so
// the test runs without binding a port; we drive the background loops
// directly via startBackground.

import (
	"path/filepath"
	"testing"
	"time"

	"go.uber.org/goleak"

	"github.com/dxs-platform/workstation-app/internal/audit"
	"github.com/dxs-platform/workstation-app/internal/monitor"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store"
)

func newServerForTest(t *testing.T) (*Server, *service.SyncEngine) {
	t.Helper()
	db, err := store.Open(filepath.Join(t.TempDir(), "lifecycle.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })

	auditLogger := audit.NewLogger(db)
	loadMonitor := monitor.New(nil)
	syncEngine := service.NewSyncEngine(db, "", func(service.ConnStatus) {})

	srv := New(Dependencies{
		DB:      db,
		Orders:  service.NewOrderEngine(db, 0),
		Sync:    syncEngine,
		Audit:   auditLogger,
		Monitor: loadMonitor,
		Port:    0, // Listener not used in this test — startBackground bypasses it.
	})
	return srv, syncEngine
}

// startBackground runs the same goroutine-spawning logic as Start() but skips
// httpServer.ListenAndServe (which would block on a port we don't want to
// bind in tests). Mirrors the body of Start() inside startOnce.Do so we
// exercise the real idempotency guard.
func (s *Server) startBackground() {
	s.startOnce.Do(func() {
		s.bgWg.Add(1)
		go func() {
			defer s.bgWg.Done()
			s.hub.Run()
		}()
		if s.puller != nil {
			s.puller.Start()
		}
		s.bgWg.Add(1)
		go func() {
			defer s.bgWg.Done()
			s.authCache.RunCleanupLoop(s.bgCtx, 10*time.Minute, 1*time.Hour)
		}()
		if s.maintenance != nil {
			s.maintenance.Start(s.bgCtx)
		}
		if s.seenBuffer != nil {
			s.bgWg.Add(1)
			go func() {
				defer s.bgWg.Done()
				s.seenBuffer.Run(s.bgCtx, 30*time.Second)
			}()
		}
	})
}

// stopBackground mirrors Stop() minus the http server shutdown.
func (s *Server) stopBackground() {
	if s.bgCancel != nil {
		s.bgCancel()
	}
	if s.puller != nil {
		s.puller.Stop()
	}
	if s.hub != nil {
		s.hub.Stop()
	}
	if s.sync != nil {
		s.sync.Stop()
	}
	if s.monitor != nil {
		s.monitor.Stop()
	}
	if s.pairLimiter != nil {
		s.pairLimiter.Stop()
	}
	if s.paymentLimiter != nil {
		s.paymentLimiter.Stop()
	}
	s.bgWg.Wait()
}

// TestNew_RegistersKdsSyncHandlers pins the wiring behind #534: New() must
// register a dispatch handler for BOTH KDS item-status operations. A revert
// bump enqueues operation "revert_status"; without a registered handler for
// customer_order_item.revert_status, pushToCloud misses and processQueue
// silently drains the entry as a no-op "success", leaving Cloud stuck on the
// pre-revert status.
func TestNew_RegistersKdsSyncHandlers(t *testing.T) {
	s, syncEngine := newServerForTest(t)
	// New() spins up rate-limiter gc goroutines; tear the server down so they
	// don't leak into TestServerStop_NoGoroutineLeak.
	t.Cleanup(s.stopBackground)

	for _, key := range []string{
		"customer_order_item.update_status",
		"customer_order_item.revert_status",
	} {
		if !syncEngine.HasHandler(key) {
			t.Errorf("expected sync handler registered for %q, got none", key)
		}
	}
}

func TestServerStart_Idempotent(t *testing.T) {
	s, _ := newServerForTest(t)

	s.startBackground()
	s.startBackground() // second call must be a no-op
	s.startBackground() // and a third for good measure

	// Give any spurious extra goroutines a tick to misbehave.
	time.Sleep(50 * time.Millisecond)

	s.stopBackground()
	// If startOnce was broken, stopBackground would either hang on bgWg.Wait
	// (extra adds without matching Done) or panic on a double-close inside
	// one of the Stop() once-guards. Reaching here means the guard held.
}

func TestServerStop_NoGoroutineLeak(t *testing.T) {
	defer goleak.VerifyNone(t,
		// stdlib database/sql keeps an idle connectionOpener until db.Close().
		// t.Cleanup runs after VerifyNone, so the goroutine is briefly alive
		// here — it is NOT a leak from our code.
		goleak.IgnoreTopFunction("database/sql.(*DB).connectionOpener"),
	)

	s, syncEngine := newServerForTest(t)
	syncEngine.Start() // mirrors main.go ordering: sync starts before server
	s.startBackground()

	// Let the goroutines reach their first select before we tear down so
	// the test exercises a real running state, not a half-spawned one.
	time.Sleep(50 * time.Millisecond)

	s.stopBackground()
}

// TestStop_DoubleCallSafe pins the production invariant: main.go defers
// both syncEngine.Stop() and lanServer.Stop(). lanServer.Stop() cascades into
// syncEngine.Stop() (so the server-owned loops shut down even if main exits
// abnormally), then main's defer calls syncEngine.Stop() a second time.
// Without sync.Once guards on every Stop the second close would panic.
func TestStop_DoubleCallSafe(t *testing.T) {
	s, syncEngine := newServerForTest(t)
	syncEngine.Start()
	s.startBackground()

	s.stopBackground() // server-side stop, cascades into syncEngine.Stop
	syncEngine.Stop()  // main.go's defer fires the second one
	s.stopBackground() // server defer second time (idempotent guard)
}
