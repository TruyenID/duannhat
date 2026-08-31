package handler

import (
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

// An operation with no registered handler is not an error at runtime: pushToCloud
// logs `sync: no handler` at WARN and returns success, which DRAINS the queue
// entry. So a mistyped or unwired key does not retry, does not dead-letter, and
// does not raise anything alerting can match — the work simply disappears, and
// Cloud keeps the state it had.
//
// TestNew_RegistersKdsSyncHandlers already pins two keys, and there are similar
// hand-kept lists for the peripheral and print-journal handlers. Three lists,
// each covering what somebody remembered. This derives the list from the code
// instead: every key any call site can enqueue must resolve on a wired server.
func TestEveryEnqueuedOperationHasAHandler(t *testing.T) {
	// Call sites where the operation is a variable and cannot be read off the
	// line. Each is listed with the values it can take, so the entry rots
	// loudly — if the site moves or gains a third value, the missing key shows
	// up as a failure here rather than as silently discarded work.
	dynamic := map[string][]string{
		// local_pos_till.go — op := "close"; op = "handover" on the handover path
		"till_session": {"close", "handover"},
		// local_kiosk.go — confirm/fail for the terminal payment result
		"payment": {"confirm", "fail"},
		// local_kds_ops.go — kdsSyncEnqueue("update_status"|"revert_status", ...)
		"customer_order_item": {"update_status", "revert_status"},
	}

	literal := regexp.MustCompile(`Enqueue\(\s*"([a-z_]+)"\s*,\s*[^,]+,\s*"([a-z_]+)"`)

	keys := map[string]string{} // key -> where it came from
	for entity, ops := range dynamic {
		for _, op := range ops {
			keys[entity+"."+op] = "dynamic call site"
		}
	}

	root := filepath.Join("..", "..", "internal")
	err := filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
		if err != nil || info.IsDir() || !strings.HasSuffix(path, ".go") || strings.HasSuffix(path, "_test.go") {
			return err
		}
		source, readErr := os.ReadFile(path)
		if readErr != nil {
			return readErr
		}
		for _, m := range literal.FindAllStringSubmatch(string(source), -1) {
			keys[m[1]+"."+m[2]] = path
		}
		return nil
	})
	if err != nil {
		t.Fatalf("walk: %v", err)
	}

	if len(keys) < 10 {
		t.Fatalf("only found %d enqueue keys — the scan is broken, not the code", len(keys))
	}

	s, syncEngine := newServerForTest(t)
	t.Cleanup(s.stopBackground)

	for key, origin := range keys {
		if !syncEngine.HasHandler(key) {
			t.Errorf(
				"%q is enqueued (%s) but no handler is registered.\n"+
					"pushToCloud drains an unhandled entry as a success, so this work would vanish silently.",
				key, origin,
			)
		}
	}
}
