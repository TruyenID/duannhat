package store

// SQL nullable-type helpers — every workstation row scan that touches a
// nullable column must use these instead of bare string/int/time, so a NULL
// from any insert path (local CRUD, SyncPuller Recover, manual SQL, etc.)
// scans cleanly. Trade-off documented in
// docs/bugs/2026-05-21-recovery-table-number-null.md.

import (
	"database/sql"
	"encoding/json"
	"time"
)

// NullableText wraps sql.NullString and JSON-serialises as "" when NULL.
// Use for every TEXT column declared without NOT NULL.
type NullableText struct {
	sql.NullString
}

// String returns the underlying string, empty when NULL. Lets callers use
// `x.TableNumber.String()` instead of awkward `x.TableNumber.NullString.String`.
func (n NullableText) String() string {
	if !n.Valid {
		return ""
	}
	return n.NullString.String
}

// MarshalJSON renders "" for NULL, matching the contract that workstation
// clients (kiosk, tablet) never see null/undefined for optional fields.
func (n NullableText) MarshalJSON() ([]byte, error) {
	if !n.Valid {
		return []byte(`""`), nil
	}
	return json.Marshal(n.NullString.String)
}

// UnmarshalJSON treats "" as NULL on write so round-tripping a value through
// JSON doesn't flip semantics.
func (n *NullableText) UnmarshalJSON(data []byte) error {
	var s string
	if err := json.Unmarshal(data, &s); err != nil {
		return err
	}
	n.Valid = s != ""
	n.NullString.String = s
	return nil
}

// Text constructs a NullableText that always Scan-as-valid. Use when an
// insert path has a concrete value (including "") that should never be NULL.
func Text(s string) NullableText {
	return NullableText{sql.NullString{String: s, Valid: true}}
}

// NullableInt wraps sql.NullInt64 and JSON-serialises as 0 when NULL.
type NullableInt struct {
	sql.NullInt64
}

func (n NullableInt) Int() int {
	if !n.Valid {
		return 0
	}
	return int(n.NullInt64.Int64)
}

func (n NullableInt) MarshalJSON() ([]byte, error) {
	if !n.Valid {
		return []byte(`0`), nil
	}
	return json.Marshal(n.NullInt64.Int64)
}

// NullableTime wraps sql.NullTime and JSON-serialises as "" when NULL.
// Workstation uses TEXT for timestamps (SQLite convention) so this is rare
// in practice — most callers want NullableText for paid_at/closed_at fields.
type NullableTime struct {
	sql.NullTime
}

func (n NullableTime) MarshalJSON() ([]byte, error) {
	if !n.Valid {
		return []byte(`""`), nil
	}
	return json.Marshal(n.NullTime.Time.Format(time.RFC3339))
}
