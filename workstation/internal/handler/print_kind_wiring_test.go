package handler

import (
	"os"
	"regexp"
	"strings"
	"testing"
)

// #1875 tripwire — the receipt, the red invoice and the debt slip must each
// reserve their copy number under their OWN kind.
//
// Why a source-level test instead of a functional one: every money-print
// handler returns 503 and reserves nothing when no printer is configured, and
// there is no fake-printer harness in this package. So the one link the
// behavioural tests cannot reach is the literal `Kind:` each handler hands to
// `beginMoneyPrint` — two words, and getting them wrong reintroduces exactly the
// bug this issue exists for: a customer who was handed a receipt receives their
// FIRST red invoice stamped 「BAN IN #2」, the mark claiming "copy" about an
// original.
//
// It also guards the removal of `AppendPrintHistory`, the shared counter that
// caused it. Re-introducing a call there would silently restore the old
// behaviour while every counter test kept passing, because those tests exercise
// the journal, not the handler.
func TestMoneyPrintHandlersReserveUnderTheirOwnKind(t *testing.T) {
	cases := []struct {
		file    string
		handler string
		kind    string
	}{
		{"print_receipt.go", "handleLANPrintRedInvoice", "printjob.KindRedInvoice"},
		{"lan_local.go", "handleLANPrintReceipt", "printjob.KindReceipt"},
		{"lan_print.go", "handleLANPrintDebtSlip", "printjob.KindDebtSlip"},
		{"auto_print.go", "autoPrintPaymentReceipt", "printjob.KindReceipt"},
	}

	for _, tc := range cases {
		t.Run(tc.handler, func(t *testing.T) {
			body := printKindFuncBody(t, tc.file, tc.handler)

			if !strings.Contains(body, "Kind:") {
				t.Fatalf("%s no longer builds a printjob.Entry — the ledger row and the "+
					"copy number are the same write; losing one loses both", tc.handler)
			}
			if !strings.Contains(body, tc.kind) {
				t.Errorf("%s does not reserve under %s.\n"+
					"Sharing a kind with another money document is the #1875 bug: the "+
					"customer's FIRST sheet of one kind comes out marked as a copy of another.",
					tc.handler, tc.kind)
			}
			if !strings.Contains(body, "beginMoneyPrint") {
				t.Errorf("%s does not go through beginMoneyPrint — the number must be "+
					"reserved before the paper moves (P-12), or a failed print can rewind "+
					"the counter", tc.handler)
			}
		})
	}
}

// AppendPrintHistory was the ONE shared counter. It is gone; nothing may call it.
func TestNoHandlerUsesTheOldSharedCounter(t *testing.T) {
	entries, err := os.ReadDir(".")
	if err != nil {
		t.Fatalf("read package dir: %v", err)
	}
	for _, e := range entries {
		name := e.Name()
		if !strings.HasSuffix(name, ".go") || strings.HasSuffix(name, "_test.go") {
			continue
		}
		src, err := os.ReadFile(name)
		if err != nil {
			t.Fatalf("read %s: %v", name, err)
		}
		// `.AppendPrintHistory(` — a call, not the word in a comment explaining
		// why it is gone.
		if strings.Contains(string(src), ".AppendPrintHistory(") {
			t.Errorf("%s calls AppendPrintHistory — that is the counter shared by "+
				"receipt + red_invoice + debt_slip, removed by #1875. Reserve under the "+
				"document's own kind instead (printjob.Journal.Reserve).", name)
		}
	}
}

// funcBody returns the source text of one function, from its declaration to the
// next top-level `func` (or EOF).
func printKindFuncBody(t *testing.T, file, name string) string {
	t.Helper()
	src, err := os.ReadFile(file)
	if err != nil {
		t.Fatalf("read %s: %v", file, err)
	}
	text := string(src)

	decl := regexp.MustCompile(`(?m)^func (\([^)]*\) )?` + regexp.QuoteMeta(name) + `\(`)
	loc := decl.FindStringIndex(text)
	if loc == nil {
		t.Fatalf("%s: function %s not found — was it renamed? This test must be "+
			"updated deliberately, not deleted.", file, name)
	}

	rest := text[loc[1]:]
	if end := regexp.MustCompile(`(?m)^func `).FindStringIndex(rest); end != nil {
		return rest[:end[0]]
	}
	return rest
}
