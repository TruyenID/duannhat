package store

import (
	"fmt"
	"io/fs"
	"strings"
	"testing"
)

// Regression for "table payments has no column named sync_target": migration 040 is
// DUPLICATED (040_payments_sync_target.sql + 040_plan045_refund_rounding.sql), the
// runner keys on the integer version, so on a DB that recorded version 40 via the
// plan-045 file the sync_target ALTER was permanently skipped. repairLegacySchema
// must heal it (idempotent, every Open) — the safe alternative to renumbering.
func TestRepairLegacySchema_RestoresPaymentSyncTarget(t *testing.T) {
	db := openTestDB(t)

	// Simulate the skipped-migration state.
	if _, err := db.conn.Exec(`ALTER TABLE payments DROP COLUMN sync_target`); err != nil {
		t.Fatalf("simulate legacy: drop payments.sync_target: %v", err)
	}
	if has, _ := db.columnExists("payments", "sync_target"); has {
		t.Fatal("setup invariant: sync_target must be absent before repair")
	}

	if err := db.repairLegacySchema(); err != nil {
		t.Fatalf("repair: %v", err)
	}

	if has, err := db.columnExists("payments", "sync_target"); err != nil || !has {
		t.Fatalf("repair must restore payments.sync_target (has=%v err=%v)", has, err)
	}
	// A payment INSERT naming sync_target now succeeds (the failing path in the
	// LAN handlers).
	if _, err := db.conn.Exec(
		`INSERT INTO payments (id, order_id, payment_method, amount, status, sync_target)
		 VALUES ('p-st','o-1','cash',100,'pending','workstation')`,
	); err != nil {
		t.Fatalf("payment INSERT with sync_target must succeed after repair: %v", err)
	}

	// Idempotent: a second repair on the healed DB is a no-op.
	if err := db.repairLegacySchema(); err != nil {
		t.Fatalf("second repair must be idempotent: %v", err)
	}
}

// Guards against FUTURE duplicate migration version numbers. The runner
// (runMigrationsWithOffset) records a version integer in schema_migrations and
// skips any version already present — so two files sharing a number means one is
// SILENTLY skipped forever, exactly the sync_target bug. Version 40 is the known,
// repair-healed duplicate; any NEW duplicate must either be renumbered (safe only
// if no shipped DB recorded it yet) or have its skipped columns healed in
// repair.go, then allow-listed here.
func TestMigrations_NoUnexpectedDuplicateVersions(t *testing.T) {
	entries, err := fs.ReadDir(localMigrationsFS, "migrations")
	if err != nil {
		t.Fatalf("read migrations dir: %v", err)
	}

	byVersion := map[int][]string{}
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".sql") {
			continue
		}
		var v int
		fmt.Sscanf(e.Name(), "%d_", &v)
		if v == 0 {
			t.Errorf("migration %q has no numeric version prefix", e.Name())
			continue
		}
		byVersion[v] = append(byVersion[v], e.Name())
	}

	// Known + healed by repair.go (payments.sync_target + orders.tax_rounding_*).
	allowedDuplicates := map[int]bool{40: true}

	for v, files := range byVersion {
		if len(files) > 1 && !allowedDuplicates[v] {
			t.Errorf("duplicate migration version %d across %v — the runner silently skips all but one; heal the skipped columns in repair.go (see the 040 precedent) then allow-list here, or renumber if no shipped DB recorded it", v, files)
		}
	}
}
