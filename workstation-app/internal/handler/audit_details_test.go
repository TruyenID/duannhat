package handler

// Regression tests for the audit-details JSON helper.
//
// The previous pattern was fmt.Sprintf(`{"name":"%s"}`, body.Name), which:
//   - produced INVALID JSON whenever a value contained a `"` or `\`
//   - allowed a deliberate payload like `evil","admin":"true` to forge
//     additional keys in the audit row, breaking forensic value of the log
//
// auditDetails wraps json.Marshal so values are escaped correctly + the
// keys are fixed at the call site. These tests pin both behaviours.

import (
	"encoding/json"
	"strings"
	"testing"
)

func TestAuditDetails_EscapesQuotes(t *testing.T) {
	got := auditDetails(map[string]any{
		"name": `evil","admin":"true`,
	})

	// MUST still be valid JSON.
	var parsed map[string]any
	if err := json.Unmarshal([]byte(got), &parsed); err != nil {
		t.Fatalf("output was not valid JSON: %v\nraw: %s", err, got)
	}

	// MUST have exactly one key — the injection should be inside the
	// string value, NOT a forged top-level field.
	if len(parsed) != 1 {
		t.Errorf("forged keys appeared: got %d keys, want 1\nraw: %s", len(parsed), got)
	}
	if v, _ := parsed["name"].(string); v != `evil","admin":"true` {
		t.Errorf("value did not round-trip: got %q", v)
	}
	// Belt-and-braces: the literal `"admin"` substring must not appear as
	// a top-level key (it's safely escaped as part of the value).
	if strings.Contains(got, `","admin":"true"`) {
		t.Errorf("appears to contain a forged `admin` field: %s", got)
	}
}

func TestAuditDetails_HandlesBackslashesAndUnicode(t *testing.T) {
	got := auditDetails(map[string]any{
		"name":  `C:\Users\boom`,
		"emoji": "🔥",
	})

	var parsed map[string]any
	if err := json.Unmarshal([]byte(got), &parsed); err != nil {
		t.Fatalf("output was not valid JSON: %v\nraw: %s", err, got)
	}
	if parsed["name"] != `C:\Users\boom` {
		t.Errorf("backslash escape failed: got %v", parsed["name"])
	}
	if parsed["emoji"] != "🔥" {
		t.Errorf("unicode failed: got %v", parsed["emoji"])
	}
}

func TestAuditDetails_EmptyMapStillValidJSON(t *testing.T) {
	got := auditDetails(map[string]any{})
	if got != "{}" {
		t.Errorf("empty map: got %q, want %q", got, "{}")
	}
}
