package handler

import (
	"os"
	"regexp"
	"strings"
	"testing"
)

// The handy surface is a second implementation of the same order operations the
// POS surface performs. Every write it makes locally must reach Cloud by the
// same sync op, because nothing else carries it: order.create sends only
// order-level fields, order.checkout sends no lines, and reconcileOrderFromCloud
// runs the other way. order.item_add is the ONLY path that puts a line on Cloud.
//
// This has already gone wrong twice. #1254: handy's void never reached Cloud, so
// Cloud kept counting a voided order as revenue. #1266: handy's item add/update/
// delete and init never reached Cloud either, so a waiter's whole basket stayed
// on the machine and Cloud received an empty order followed by a payment for it.
//
// The repo's own answer to this class of drift is delegation — handy's payment
// handler rewrites the mux path and calls the POS core, which is why it cannot
// diverge. The item handlers write different SQL and return a different shape,
// so they stay separate; this test is what keeps them honest instead.
func TestHandyWritesEnqueueTheSameSyncOpsAsPos(t *testing.T) {
	source, err := os.ReadFile("local_handy.go")
	if err != nil {
		t.Fatalf("read local_handy.go: %v", err)
	}
	text := string(source)

	// handler name -> sync op it must enqueue.
	required := map[string]string{
		"handleLocalHandyCreateOrder": `Enqueue("order", o.ID, "create"`,
		"handleLocalHandyInitOrder":   `enqueueOrderSync("order.init"`,
		"handleLocalHandyAddItems":    `enqueueOrderSync("order.item_add"`,
		"handleLocalHandyUpdateItem":  `enqueueOrderSync("order.item_update"`,
		"handleLocalHandyRemoveItem":  `enqueueOrderSync("order.item_delete"`,
		"handleLocalHandyVoidOrder":   `enqueueOrderSync("order.void"`,
	}

	for handler, op := range required {
		body, ok := funcBody(text, handler)
		if !ok {
			t.Errorf("%s not found — if it was renamed, update this test rather than deleting the entry", handler)

			continue
		}
		if !strings.Contains(body, op) {
			t.Errorf(
				"%s writes locally but does not enqueue %s.\n"+
					"Nothing else carries it: order.create sends no lines, order.checkout sends no lines,\n"+
					"and reconciliation runs Cloud->local. See #1266.",
				handler, op,
			)
		}
	}

	// A rename that silently emptied the map would make this pass, so the count
	// is asserted too.
	if len(required) < 6 {
		t.Fatalf("the required-op map has shrunk to %d entries", len(required))
	}
}

// funcBody returns the source of a top-level func by name, from its signature to
// the closing brace in column 1.
func funcBody(source, name string) (string, bool) {
	sig := regexp.MustCompile(`(?m)^func \(s \*Server\) ` + regexp.QuoteMeta(name) + `\(`)
	loc := sig.FindStringIndex(source)
	if loc == nil {
		return "", false
	}
	end := strings.Index(source[loc[0]:], "\n}\n")
	if end < 0 {
		return source[loc[0]:], true
	}

	return source[loc[0] : loc[0]+end], true
}
