package handler

import (
	"testing"
	"time"
)

func TestHub_BroadcastEventScopedByBranch(t *testing.T) {
	hub := NewHub()
	go hub.Run()
	defer hub.Stop()

	clientA := &Client{authedBy: "dev-a", branchID: "branch-1", send: make(chan []byte, 4)}
	clientB := &Client{authedBy: "dev-b", branchID: "branch-2", send: make(chan []byte, 4)}
	hub.register <- clientA
	hub.register <- clientB
	time.Sleep(10 * time.Millisecond)

	hub.BroadcastEventScoped("order_item.status_changed", map[string]any{"foo": "bar"}, "branch-1")
	time.Sleep(20 * time.Millisecond)

	select {
	case <-clientA.send:
		// good
	default:
		t.Fatalf("client A in branch-1 did not receive event")
	}
	select {
	case <-clientB.send:
		t.Fatalf("client B in branch-2 should NOT receive branch-1 event")
	default:
		// good
	}
}

func TestHub_BroadcastEventUnscoped_BackwardCompat(t *testing.T) {
	// Existing BroadcastEvent (no scope) should reach all authenticated clients.
	hub := NewHub()
	go hub.Run()
	defer hub.Stop()

	clientA := &Client{authedBy: "dev-a", branchID: "branch-1", send: make(chan []byte, 4)}
	clientB := &Client{authedBy: "dev-b", branchID: "branch-2", send: make(chan []byte, 4)}
	hub.register <- clientA
	hub.register <- clientB
	time.Sleep(10 * time.Millisecond)

	hub.BroadcastEvent("order_created", map[string]any{"id": "o-1"})
	time.Sleep(20 * time.Millisecond)

	for _, c := range []*Client{clientA, clientB} {
		select {
		case <-c.send:
			// good
		default:
			t.Fatalf("client did not receive unscoped broadcast")
		}
	}
}

func TestHub_BroadcastEventScoped_SkipsClientsWithoutBranchID(t *testing.T) {
	// Clients without branchID (not yet bound by auth handshake) should NOT
	// receive scoped broadcasts. Only clients with a matching branchID receive them.
	hub := NewHub()
	go hub.Run()
	defer hub.Stop()

	noBranch := &Client{authedBy: "dev-a", send: make(chan []byte, 4)} // branchID=""
	authed := &Client{authedBy: "dev-b", branchID: "branch-1", send: make(chan []byte, 4)}
	hub.register <- noBranch
	hub.register <- authed
	time.Sleep(10 * time.Millisecond)

	hub.BroadcastEventScoped("order_item.status_changed", map[string]any{}, "branch-1")
	time.Sleep(20 * time.Millisecond)

	select {
	case <-noBranch.send:
		t.Fatalf("client without branchID should NOT receive scoped event")
	default:
		// good
	}
	select {
	case <-authed.send:
		// good
	default:
		t.Fatalf("client with matching branchID should receive scoped event")
	}
}
