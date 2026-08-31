package service

import "testing"

func TestSyncTracer_RecentNewestFirst(t *testing.T) {
	tr := NewSyncTracer(10)
	tr.enqueue("k1", "order", "create", "o1")
	tr.enqueue("k2", "payment", "create", "p1")

	got := tr.Recent(0, "")
	if len(got) != 2 {
		t.Fatalf("want 2 events, got %d", len(got))
	}
	// Newest first.
	if got[0].TraceID != "k2" || got[1].TraceID != "k1" {
		t.Fatalf("wrong order: %s, %s", got[0].TraceID, got[1].TraceID)
	}
	if got[0].Seq <= got[1].Seq {
		t.Fatalf("seq must be monotonic desc: %d, %d", got[0].Seq, got[1].Seq)
	}
}

func TestSyncTracer_FlowFilter(t *testing.T) {
	tr := NewSyncTracer(10)
	tr.enqueue("k1", "order", "create", "o1") // up
	tr.conn(ConnOnline)                       // conn
	tr.down("customer_orders", 12, 3, nil)    // down

	if n := len(tr.Recent(0, string(FlowUp))); n != 1 {
		t.Fatalf("up filter: want 1, got %d", n)
	}
	if n := len(tr.Recent(0, string(FlowConn))); n != 1 {
		t.Fatalf("conn filter: want 1, got %d", n)
	}
	if n := len(tr.Recent(0, string(FlowDown))); n != 1 {
		t.Fatalf("down filter: want 1, got %d", n)
	}
	if n := len(tr.Recent(0, "")); n != 3 {
		t.Fatalf("no filter: want 3, got %d", n)
	}
}

func TestSyncTracer_RingWraparound(t *testing.T) {
	tr := NewSyncTracer(3)
	for i := 0; i < 5; i++ {
		tr.enqueue("k", "order", "create", "o")
	}
	got := tr.Recent(0, "")
	if len(got) != 3 {
		t.Fatalf("ring should cap at 3, got %d", len(got))
	}
	// Most-recent seq is 5, oldest retained is 3.
	if got[0].Seq != 5 || got[2].Seq != 3 {
		t.Fatalf("wraparound seqs wrong: newest=%d oldest=%d", got[0].Seq, got[2].Seq)
	}
}

func TestSyncTracer_DownSuccessSkippedWhenEmpty(t *testing.T) {
	// pull() records success only when count > 0 so idle ticks don't flood.
	p := &SyncPuller{tracer: NewSyncTracer(10)}
	p.pull("zones", 0, nil, 5)          // idle success — not recorded
	p.pull("menu", 2, nil, 5)           // meaningful success — recorded
	p.pull("tables", 0, errTestPull, 5) // error — always recorded

	got := p.tracer.Recent(0, "")
	if len(got) != 2 {
		t.Fatalf("want 2 recorded events, got %d", len(got))
	}
}

var errTestPull = &pullTestErr{}

type pullTestErr struct{}

func (*pullTestErr) Error() string { return "boom" }
