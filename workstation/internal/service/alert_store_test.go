package service

import (
	"errors"
	"path/filepath"
	"sync"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

func newAlertStore(t *testing.T) *AlertStore {
	t.Helper()
	// storetest.Open copies an already-migrated template (~15ms) instead of
	// replaying every migration — see #1186.
	db, err := storetest.Open(filepath.Join(t.TempDir(), "alerts.db"))
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() { _ = db.Close() })
	return NewAlertStore(db)
}

// The allowlist only holds if breaking it is LOUD. A kind nobody registered
// must be refused, not quietly inserted — otherwise a warning added next week
// becomes a notification without anyone deciding it should.
func TestAlertStore_RefusesUnregisteredKind(t *testing.T) {
	s := newAlertStore(t)

	_, err := s.Raise(AlertKind("some_new_warning"), "", "whatever", nil)

	if !errors.Is(err, ErrAlertKindNotRegistered) {
		t.Fatalf("err = %v, want ErrAlertKindNotRegistered", err)
	}
	open, _ := s.ListOpen()
	if len(open) != 0 {
		t.Fatalf("an unregistered kind reached the table: %+v", open)
	}
}

// A printer offline for an hour is ONE alert with a rising count — not 3600
// rows. Without dedup the panel is a log with extra steps, and the alert that
// mattered is pushed off the screen by the one that repeats.
func TestAlertStore_RaiseDedupesOnKindAndSubject(t *testing.T) {
	s := newAlertStore(t)

	id1, err := s.Raise(KindNoPrinter, "kitchen_printer", "Bếp chưa có máy in", nil)
	if err != nil {
		t.Fatalf("raise: %v", err)
	}
	for range 4 {
		if _, err := s.Raise(KindNoPrinter, "kitchen_printer", "Bếp chưa có máy in", nil); err != nil {
			t.Fatalf("re-raise: %v", err)
		}
	}
	// A DIFFERENT subject is a different condition.
	if _, err := s.Raise(KindNoPrinter, "bar_printer", "Quầy bar chưa có máy in", nil); err != nil {
		t.Fatalf("raise other subject: %v", err)
	}

	open, err := s.ListOpen()
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(open) != 2 {
		t.Fatalf("open alerts = %d, want 2 (one per subject)", len(open))
	}
	for _, a := range open {
		if a.Subject == "kitchen_printer" {
			if a.ID != id1 {
				t.Errorf("dedup created a new row: %s != %s", a.ID, id1)
			}
			if a.Count != 5 {
				t.Errorf("count = %d, want 5", a.Count)
			}
		}
	}
}

// The heaviest rule in the file. `cash_retained` means the machine is STILL
// HOLDING the customer's money; the only thing that proves it ended is a person
// opening the machine and counting. A probe that merely knows the session is
// over must NOT be able to clear it.
func TestAlertStore_CashRetainedCannotBeAutoResolved(t *testing.T) {
	s := newAlertStore(t)
	if _, err := s.Raise(KindCashRetained, "order-1", "Tiền còn trong máy", nil); err != nil {
		t.Fatalf("raise: %v", err)
	}

	if err := s.ResolveAuto(KindCashRetained, "order-1"); err != nil {
		t.Fatalf("ResolveAuto should be a no-op, not an error: %v", err)
	}

	open, _ := s.ListOpen()
	if len(open) != 1 {
		t.Fatalf("auto-resolve cleared a cash-retained alert — the money is still in the machine")
	}

	// A human, however, may close it.
	if err := s.Ack(open[0].ID, "manager-1"); err != nil {
		t.Fatalf("ack: %v", err)
	}
	if open, _ = s.ListOpen(); len(open) != 0 {
		t.Fatalf("ack did not close it")
	}
}

// Conditions the workstation CAN re-measure clear themselves — nobody should
// have to dismiss "printer offline" by hand once the printer prints.
func TestAlertStore_MeasurableConditionsAutoResolve(t *testing.T) {
	s := newAlertStore(t)
	if _, err := s.Raise(KindNoPrinter, "kitchen_printer", "Bếp chưa có máy in", nil); err != nil {
		t.Fatalf("raise: %v", err)
	}

	if err := s.ResolveAuto(KindNoPrinter, "kitchen_printer"); err != nil {
		t.Fatalf("resolve: %v", err)
	}

	if open, _ := s.ListOpen(); len(open) != 0 {
		t.Fatalf("open = %d, want 0", len(open))
	}
}

// A resolved alert is history. The same condition recurring later is a NEW row:
// "the printer dropped again this evening" is a different fact from "it dropped
// this morning", and merging them hides the second.
func TestAlertStore_RecurrenceAfterResolveIsANewAlert(t *testing.T) {
	s := newAlertStore(t)
	first, _ := s.Raise(KindNoPrinter, "kitchen_printer", "Bếp chưa có máy in", nil)
	if err := s.ResolveAuto(KindNoPrinter, "kitchen_printer"); err != nil {
		t.Fatalf("resolve: %v", err)
	}

	second, err := s.Raise(KindNoPrinter, "kitchen_printer", "Bếp chưa có máy in", nil)
	if err != nil {
		t.Fatalf("re-raise: %v", err)
	}

	if second == first {
		t.Fatal("recurrence reused the resolved row — the second outage is invisible")
	}
	open, _ := s.ListOpen()
	if len(open) != 1 || open[0].Count != 1 {
		t.Fatalf("recurrence should start a fresh count, got %+v", open)
	}
}

// Worst first, then freshest — the order someone scanning the panel needs.
func TestAlertStore_ListOpenOrdersBySeverityThenRecency(t *testing.T) {
	s := newAlertStore(t)
	base := time.Date(2026, 8, 5, 3, 0, 0, 0, time.UTC)
	s.now = func() time.Time { return base }
	if _, err := s.Raise(KindSyncStalled, "", "Hàng đợi sync tắc", nil); err != nil {
		t.Fatalf("raise: %v", err)
	}
	s.now = func() time.Time { return base.Add(time.Minute) }
	if _, err := s.Raise(KindNoPrinter, "kitchen_printer", "Bếp chưa có máy in", nil); err != nil {
		t.Fatalf("raise: %v", err)
	}

	open, err := s.ListOpen()
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(open) != 2 || open[0].Severity != SeverityCritical {
		t.Fatalf("critical must sort first, got %+v", open)
	}
}

// severity and audience are two axes, not one. If they ever collapse into a
// single "level", the cell this asserts — dev-owned yet critical — is the one
// that disappears, and it is the cell that gets people hurt.
func TestAlertPolicy_SeverityAndAudienceAreIndependent(t *testing.T) {
	sev, aud, _, ok := AlertPolicyFor(KindRealtimeClientDropped)
	if !ok {
		t.Fatal("kind not registered")
	}
	if aud != AudienceDev {
		t.Errorf("audience = %s, want dev", aud)
	}
	if sev == "" {
		t.Error("severity must be set independently of audience")
	}

	// And a shop-owned kind can be critical.
	sev2, aud2, _, _ := AlertPolicyFor(KindCashRetained)
	if aud2 != AudienceShop || sev2 != SeverityCritical {
		t.Errorf("cash_retained = %s/%s, want shop/critical", aud2, sev2)
	}
}

// REGRESSION (found while reviewing this very PR, before it merged). Raise was
// SELECT-then-INSERT: two concurrent raisers of the SAME condition both saw "no
// open row", both inserted, and one died on the partial unique index —
// reproduced at 1 failure in 16 goroutines, every run.
//
// It is not a theoretical race. It fires whenever two terminals hit one
// condition at once: two prints while the kitchen printer is unplugged, two POS
// seeing the same retained cash. And because alerts are raised fire-and-forget,
// the error is swallowed and the alert is simply LOST — an alert centre that
// drops alerts under load is worse than none, because it looks like it is
// watching.
func TestAlertStore_RaiseIsAtomicUnderConcurrency(t *testing.T) {
	s := newAlertStore(t)

	const raisers = 16
	var wg sync.WaitGroup
	errCh := make(chan error, raisers)
	for range raisers {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if _, err := s.Raise(KindNoPrinter, "kitchen_printer", "Bếp chưa có máy in", nil); err != nil {
				errCh <- err
			}
		}()
	}
	wg.Wait()
	close(errCh)

	for err := range errCh {
		t.Fatalf("concurrent Raise failed — an alert was lost: %v", err)
	}

	open, err := s.ListOpen()
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(open) != 1 {
		t.Fatalf("open alerts = %d, want exactly 1 (dedup must hold under concurrency)", len(open))
	}
	if open[0].Count != raisers {
		t.Errorf("count = %d, want %d — every occurrence must be counted", open[0].Count, raisers)
	}
}

// The upsert targets the PARTIAL index (open rows only), so a resolved row must
// never absorb a new occurrence — otherwise recurrence would silently reopen
// history instead of starting a fresh alert.
func TestAlertStore_UpsertDoesNotReviveResolvedRows(t *testing.T) {
	s := newAlertStore(t)
	first, _ := s.Raise(KindNoPrinter, "kitchen_printer", "Bếp chưa có máy in", nil)
	if err := s.ResolveAuto(KindNoPrinter, "kitchen_printer"); err != nil {
		t.Fatalf("resolve: %v", err)
	}

	var wg sync.WaitGroup
	for range 8 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			_, _ = s.Raise(KindNoPrinter, "kitchen_printer", "Bếp chưa có máy in", nil)
		}()
	}
	wg.Wait()

	open, _ := s.ListOpen()
	if len(open) != 1 {
		t.Fatalf("open = %d, want 1", len(open))
	}
	if open[0].ID == first {
		t.Fatal("the resolved row was revived — the earlier outage is now unrecoverable history")
	}
}

// #2167 — một đợt lệch tiền rải ra hàng chục alert subject-theo-đơn; bắt bấm
// từng dòng là dạy người ta thôi mở panel. AckKind = một khẳng định đóng cả đợt.
func TestAlertStore_AckKindClosesEveryOpenRowOfThatKindOnly(t *testing.T) {
	s := newAlertStore(t)

	for _, subj := range []string{"o1", "o2", "o3"} {
		if _, err := s.Raise(KindCloudMoneyOverwrite, subj, "lệch", nil); err != nil {
			t.Fatalf("raise %s: %v", subj, err)
		}
	}
	if _, err := s.Raise(KindNoPrinter, "kitchen_printer", "Bếp chưa có máy in", nil); err != nil {
		t.Fatalf("raise other kind: %v", err)
	}

	n, err := s.AckKind(KindCloudMoneyOverwrite, "manager-a")
	if err != nil {
		t.Fatalf("AckKind: %v", err)
	}
	if n != 3 {
		t.Errorf("acked = %d, muốn 3", n)
	}

	open, _ := s.ListOpen()
	if len(open) != 1 || open[0].Kind != KindNoPrinter {
		t.Fatalf("kind KHÁC phải còn mở nguyên: %+v", open)
	}
	// Ai bấm phải được ghi lại trên TỪNG dòng — ack hàng loạt không được phép
	// rẻ hơn ack đơn lẻ về trách nhiệm.
	var by string
	if err := s.db.QueryRow(`SELECT resolved_by FROM alerts WHERE kind = ? AND subject = 'o2'`,
		string(KindCloudMoneyOverwrite)).Scan(&by); err != nil {
		t.Fatalf("read resolved_by: %v", err)
	}
	if by != "manager-a" {
		t.Errorf("resolved_by = %q, muốn manager-a", by)
	}
}

func TestAlertStore_AckKindRefusesUnregisteredKind(t *testing.T) {
	s := newAlertStore(t)
	if _, err := s.AckKind(AlertKind("nonsense"), "x"); !errors.Is(err, ErrAlertKindNotRegistered) {
		t.Fatalf("err = %v, muốn ErrAlertKindNotRegistered", err)
	}
}

// #2167 — retention: alert ĐÃ ĐÓNG quá hạn bị dọn; alert MỞ không bao giờ,
// bất kể tuổi — một alert chưa ai xử lý tự biến mất theo tuổi là mất dữ liệu.
func TestMaintenance_PurgeAlertsKeepsOpenRowsForever(t *testing.T) {
	s := newAlertStore(t)

	old := "2026-01-01T00:00:00Z"
	if _, err := s.db.Exec(`
		INSERT INTO alerts (id, kind, subject, severity, audience, title, first_seen_at, last_seen_at, resolved_at, resolution)
		VALUES ('closed-old', ?, 'a', 'warning', 'shop', 't', ?, ?, ?, 'ack'),
		       ('open-old',   ?, 'b', 'warning', 'shop', 't', ?, ?, NULL, NULL)`,
		string(KindCloudMoneyOverwrite), old, old, old,
		string(KindCloudMoneyOverwrite), old, old); err != nil {
		t.Fatalf("seed: %v", err)
	}
	if _, err := s.Raise(KindNoPrinter, "kitchen_printer", "vừa đóng xong", nil); err != nil {
		t.Fatalf("raise: %v", err)
	}
	if _, err := s.db.Exec(`UPDATE alerts SET resolved_at = ?, resolution = 'ack' WHERE kind = ?`,
		time.Now().UTC().Format(time.RFC3339), string(KindNoPrinter)); err != nil {
		t.Fatalf("close fresh: %v", err)
	}

	m := NewMaintenance(s.db, MaintenanceConfig{}) // AlertKeep mặc định 30 ngày
	n, err := m.PurgeAlertsOnce()
	if err != nil {
		t.Fatalf("purge: %v", err)
	}
	if n != 1 {
		t.Errorf("dọn %d dòng, muốn 1 (chỉ closed-old)", n)
	}

	var openOld, closedFresh int
	_ = s.db.QueryRow(`SELECT COUNT(*) FROM alerts WHERE id = 'open-old'`).Scan(&openOld)
	_ = s.db.QueryRow(`SELECT COUNT(*) FROM alerts WHERE kind = ? AND resolved_at IS NOT NULL`,
		string(KindNoPrinter)).Scan(&closedFresh)
	if openOld != 1 {
		t.Error("alert MỞ quá tuổi bị dọn — đó là mất dữ liệu, không phải retention")
	}
	if closedFresh != 1 {
		t.Error("alert đóng CHƯA quá hạn bị dọn — cutoff sai")
	}
}
