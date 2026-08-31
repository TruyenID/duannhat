package service

import (
	"encoding/json"
	"fmt"
	"strconv"
)

// flexFloat decodes a JSON value as float64 regardless of whether the
// upstream emits a number (`1000`) or a quoted string (`"1000.00"`).
//
// Why this exists: Laravel's `decimal:N` Eloquent cast — used liberally
// on backend money / quantity fields — serialises to a quoted string in
// JSON to preserve precision. Workstation pullers that declared the
// receiving field as plain `float64` would silently fail decode on
// every sync because Go's encoding/json refuses to coerce a string into
// a float64 without help. The pull would error, no INSERT ran, and the
// local replica table stayed empty.
//
// Production incident this fixed: shop manager added a JPY denomination
// via Shop Settings; LAN-mode pos-web cashier never saw it because
// PullDenominations decode crashed on `"value":"1000.00"`. The empty
// guard in the puller swallowed the failure path and the table
// remained empty forever. The `flexFloat` accepts both shapes so the
// row decode succeeds whichever cast Cloud uses.
//
// Use this for every field on a pull response that maps to a Laravel
// `decimal` / `decimal:N` / `numeric` / `float` cast.
type flexFloat float64

// UnmarshalJSON accepts either:
//
//	1000            ← number form
//	"1000"          ← stringified integer
//	"1000.00"       ← Laravel decimal cast
//	null            ← treated as zero
//	""              ← treated as zero (defensive against odd backends)
//
// Anything else returns an error so a genuine format change still
// surfaces during pull instead of silently zeroing.
func (f *flexFloat) UnmarshalJSON(b []byte) error {
	if len(b) == 0 || string(b) == "null" {
		*f = 0
		return nil
	}
	// Number form: json.Unmarshal handles it directly.
	if b[0] != '"' {
		var v float64
		if err := json.Unmarshal(b, &v); err != nil {
			return fmt.Errorf("flexFloat decode: %w (raw=%s)", err, string(b))
		}
		*f = flexFloat(v)
		return nil
	}
	// Quoted string form: strip quotes, ParseFloat. Empty string → 0
	// because Cloud occasionally emits "" for unset decimal columns.
	if len(b) < 2 || b[len(b)-1] != '"' {
		return fmt.Errorf("flexFloat decode: malformed quoted value %s", string(b))
	}
	inner := string(b[1 : len(b)-1])
	if inner == "" {
		*f = 0
		return nil
	}
	v, err := strconv.ParseFloat(inner, 64)
	if err != nil {
		return fmt.Errorf("flexFloat decode %q: %w", inner, err)
	}
	*f = flexFloat(v)
	return nil
}

// Float returns the underlying float64. Useful at call sites that need
// to pass the value to SQLite or do arithmetic.
func (f flexFloat) Float() float64 { return float64(f) }
