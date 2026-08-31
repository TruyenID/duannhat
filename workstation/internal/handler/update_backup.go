package handler

// #2659 (lỗ 2) — the database copy taken immediately before an install.
//
// The workstation runs its own SQLite migrations, from inside the binary, on
// every boot. So installing a build is also migrating the shop's database, and
// the rollback in supervise.go restores the BINARY only — there is no down
// migration. Until this existed, the newest copy on disk could be six hours old
// (the periodic snapshot cadence), which is how much of a shop's evening a
// restore would have cost.
//
// The copy is a PRECONDITION of installing, not a best-effort side task: if it
// cannot be written, the install does not happen. That is deliberately the
// opposite of the fail-open rule elsewhere — a missing snapshot at 2 AM is
// only discovered on the night it is needed.

import (
	"errors"
	"fmt"
	"log/slog"
)

// snapshotBeforeUpdate writes the pre-update copy and returns its path. Both
// install paths — the operator's button (handleUpdateApply) and the unattended
// 2 AM scheduler (tryScheduledAutoApply) — must call it immediately before
// Planner.Apply() and must NOT install when it returns an error.
func (s *Server) snapshotBeforeUpdate(toVersion string) (string, error) {
	if s.maintenance == nil {
		// Not a "backups happen to be off" case — it means this build cannot
		// take one at all, and installing blind is the thing being prevented.
		return "", errors.New("backup subsystem unavailable: refusing to install without a pre-update database copy")
	}
	path, err := s.maintenance.PreUpdateSnapshot(toVersion)
	if err != nil {
		return "", fmt.Errorf("pre-update database copy failed: %w", err)
	}
	slog.Info("pre-update database copy written", "path", path, "to_version", toVersion)
	return path, nil
}
